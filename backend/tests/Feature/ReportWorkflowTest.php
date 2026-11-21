<?php

namespace Tests\Feature;

use App\Models\ReferenceRange;
use App\Models\Report;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The vertical slice: patient -> tests -> reference ranges -> results -> validation -> final report.
 * Expected ranges are read from the database, so these tests contain no clinical numbers.
 */
class ReportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function makePatient(string $gender, int $ageYears): array
    {
        return $this->postJson('/api/patients', [
            'name' => 'Synthetic Patient',
            'gender' => $gender,
            'phone' => '03001234567',
            'address' => 'Test Address',
            'date_of_birth' => now('Asia/Karachi')->subYears($ageYears)->subDays(10)->format('Y-m-d'),
        ])->assertCreated()->json('data');
    }

    private function createReport(array $patient, array $codes = ['CBC']): array
    {
        return $this->postJson('/api/reports', [
            'patient_id' => $patient['id'],
            'test_codes' => $codes,
        ])->assertCreated()->json('data');
    }

    /** @return array<string, array> results keyed by parameter name */
    private function resultsByName(array $report): array
    {
        $out = [];
        foreach ($report['report_tests'] as $reportTest) {
            foreach ($reportTest['results'] as $result) {
                $out[$result['parameter_name_snapshot']] = $result;
            }
        }

        return $out;
    }

    private function storedRange(string $parameter, string $gender): ReferenceRange
    {
        return ReferenceRange::where('gender', $gender)
            ->whereHas('parameter', fn ($q) => $q->where('name', $parameter)
                ->whereHas('testDefinition', fn ($t) => $t->where('code', 'CBC')))
            ->firstOrFail();
    }

    public function test_results_start_empty_and_ranges_follow_sex_and_age(): void
    {
        $this->seed(CatalogSeeder::class);

        $hbMale = $this->storedRange('Hemoglobin', 'male');
        $hbFemale = $this->storedRange('Hemoglobin', 'female');

        $male = $this->resultsByName($this->createReport($this->makePatient('male', 30)));
        $female = $this->resultsByName($this->createReport($this->makePatient('female', 30)));
        $child = $this->resultsByName($this->createReport($this->makePatient('male', 5)));
        $other = $this->resultsByName($this->createReport($this->makePatient('other', 30)));

        // No normal value is ever typed into a result field.
        foreach ([$male, $female, $child, $other] as $results) {
            foreach ($results as $result) {
                $this->assertNull($result['result_value']);
                $this->assertFalse($result['was_auto_filled']);
            }
        }

        $this->assertSame($hbMale->id, $male['Hemoglobin']['reference_range_id']);
        $this->assertSame($hbFemale->id, $female['Hemoglobin']['reference_range_id']);

        // Only adult ranges exist in the catalog, so a child gets NO range rather than a wrong one.
        $this->assertNull($child['Hemoglobin']['reference_range_id']);
        $this->assertNull($child['Hemoglobin']['ref_low']);
        $this->assertNull($child['Hemoglobin']['reference_range_text']);

        // "other"/unknown sex only receives ranges that apply to everyone.
        $this->assertNull($other['Hemoglobin']['reference_range_id']);
        $this->assertNotNull($other['MCV']['reference_range_id']);

        // The applied range carries its provenance, and nothing shipped is verified yet.
        $this->assertSame(ReferenceRange::STATUS_PENDING, $male['Hemoglobin']['reference_verification_status']);
        $this->assertNotEmpty($male['Hemoglobin']['reference_source']);
    }

    public function test_flags_follow_the_frozen_limits_and_nothing_is_critical_without_critical_limits(): void
    {
        $this->seed(CatalogSeeder::class);

        $report = $this->createReport($this->makePatient('male', 30));
        $hb = $this->resultsByName($report)['Hemoglobin'];
        $low = (float) $hb['ref_low'];
        $high = (float) $hb['ref_high'];
        $this->assertGreaterThan($low, $high);

        $enter = function (string $value) use ($report, $hb) {
            $response = $this->putJson("/api/reports/{$report['id']}/results", [
                'results' => [['id' => $hb['id'], 'result_value' => $value]],
            ])->assertOk();

            return collect($response->json('data.report_tests.0.results'))->firstWhere('id', $hb['id']);
        };

        $below = $enter((string) ($low - 1));
        $this->assertSame('L', $below['result_flag']);
        $this->assertTrue($below['is_abnormal']);
        $this->assertFalse($below['is_critical']);

        $above = $enter((string) ($high + 1));
        $this->assertSame('H', $above['result_flag']);
        $this->assertFalse($above['is_critical']);

        $inside = $enter((string) (($low + $high) / 2));
        $this->assertNull($inside['result_flag']);
        $this->assertFalse($inside['is_abnormal']);
    }

    public function test_finalize_requires_results_and_freezes_a_snapshot(): void
    {
        $this->seed(CatalogSeeder::class);

        $patient = $this->makePatient('male', 30);
        $report = $this->createReport($patient);
        $hb = $this->resultsByName($report)['Hemoglobin'];

        // Nothing entered yet.
        $this->postJson("/api/reports/{$report['id']}/finalize")->assertStatus(422);

        // One value entered, the other selected parameters still empty: blocked unless explicit.
        $this->putJson("/api/reports/{$report['id']}/results", [
            'results' => [['id' => $hb['id'], 'result_value' => '13.5']],
        ])->assertOk();
        $this->postJson("/api/reports/{$report['id']}/finalize")
            ->assertStatus(422)
            ->assertJsonStructure(['missing_parameters']);

        $final = $this->postJson("/api/reports/{$report['id']}/finalize", ['allow_incomplete' => true])->assertOk();
        $final->assertJsonPath('data.status', 'final');
        $this->assertSame($patient['mr_number'], $final->json('data.snapshot.patient.mr_number'));
        $this->assertTrue($final->json('data.snapshot.incomplete'));
        $this->assertSame(1, $final->json('meta.unverified_reference_ranges'));

        // A final report is read-only.
        $this->putJson("/api/reports/{$report['id']}/results", [
            'results' => [['id' => $hb['id'], 'result_value' => '9']],
        ])->assertNotFound();
    }

    public function test_an_unknown_test_code_is_rejected_instead_of_silently_dropped(): void
    {
        $this->seed(CatalogSeeder::class);
        $patient = $this->makePatient('male', 30);

        $this->postJson('/api/reports', [
            'patient_id' => $patient['id'],
            'test_codes' => ['CBC', 'NO_SUCH_TEST'],
        ])->assertStatus(422)->assertJsonPath('unknown_test_codes.0', 'NO_SUCH_TEST');

        $this->assertSame(0, Report::count());
    }

    public function test_report_numbers_are_unique_and_well_formed(): void
    {
        $this->seed(CatalogSeeder::class);
        $patient = $this->makePatient('male', 30);

        $a = $this->createReport($patient)['report_number'];
        $b = $this->createReport($patient)['report_number'];

        $this->assertNotSame($a, $b);
        $this->assertMatchesRegularExpression('/^\d{6}-P-R\d{4}$/', $a);
    }
}
