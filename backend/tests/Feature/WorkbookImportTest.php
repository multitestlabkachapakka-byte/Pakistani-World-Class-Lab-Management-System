<?php

namespace Tests\Feature;

use App\Models\ReferenceRange;
use App\Models\TestDefinition;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/** Tests imported from the uploaded workbook (tools/import_workbook_catalog.py). */
class WorkbookImportTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    private function imported(): array
    {
        return json_decode(file_get_contents(database_path('seeders/catalog/workbook_import.json')), true);
    }

    private function makePatient(int $ageYears): array
    {
        return $this->postJson('/api/patients', [
            'name' => 'Synthetic Patient',
            'gender' => 'male',
            'phone' => '03001234567',
            'address' => 'Test Address',
            'date_of_birth' => now('Asia/Karachi')->subYears($ageYears)->subDays(10)->format('Y-m-d'),
        ])->assertCreated()->json('data');
    }

    private function createReport(array $patient, string $code): array
    {
        return $this->postJson('/api/reports', ['patient_id' => $patient['id'], 'test_codes' => [$code]])
            ->assertCreated()->json('data');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
        $this->seed(CatalogSeeder::class);
    }

    public function test_every_workbook_test_is_in_the_catalog_unverified_and_with_its_source(): void
    {
        $imported = $this->imported();
        $this->assertGreaterThanOrEqual(40, count($imported));

        foreach ($imported as $entry) {
            $test = TestDefinition::where('code', $entry['code'])->first();
            $this->assertNotNull($test, $entry['code']);
            $this->assertSame(ReferenceRange::STATUS_PENDING, $test->verification_status, $entry['code']);
            $this->assertStringContainsString('Pakistan_Major_Labs_Test_Catalog_Reference_Ranges.xlsx', (string) $test->source);
        }

        $this->assertSame(0, ReferenceRange::where('source', 'like', '%Pakistan_Major_Labs%')
            ->where('verification_status', '!=', ReferenceRange::STATUS_PENDING)
            ->count());
    }

    public function test_no_numeric_limit_is_invented_each_one_appears_in_the_workbook_text(): void
    {
        foreach ($this->imported() as $entry) {
            foreach ($entry['parameters'] as $param) {
                foreach ($param['ranges'] as $range) {
                    preg_match_all('/\d+(?:\.\d+)?/', $range['range_text'], $m);
                    $numbers = array_map('floatval', $m[0]);

                    foreach (['low', 'high'] as $limit) {
                        if ($range[$limit] !== null) {
                            $this->assertContains((float) $range[$limit], $numbers, "{$entry['code']} {$limit}");
                        }
                    }
                }
            }
        }
    }

    public function test_text_only_tests_show_the_workbook_text_but_never_produce_a_flag(): void
    {
        $report = $this->createReport($this->makePatient(30), 'FOBT');
        $result = $report['report_tests'][0]['results'][0];

        $this->assertSame('Result', $result['parameter_name_snapshot']);
        $this->assertNotEmpty($result['reference_range_text']);
        $this->assertNull($result['ref_low']);
        $this->assertNull($result['ref_high']);
        $this->assertSame(ReferenceRange::STATUS_PENDING, $result['reference_verification_status']);

        $saved = $this->putJson("/api/reports/{$report['id']}/results", [
            'results' => [['id' => $result['id'], 'result_value' => '999']],
        ])->assertOk();
        $row = collect($saved->json('data.report_tests.0.results'))->firstWhere('id', $result['id']);

        $this->assertNull($row['result_flag']);
        $this->assertFalse($row['is_abnormal']);
    }

    public function test_a_numeric_workbook_test_flags_against_its_stored_limits_and_gives_children_no_range(): void
    {
        $report = $this->createReport($this->makePatient(30), 'CA');
        $result = $report['report_tests'][0]['results'][0];
        $low = (float) $result['ref_low'];
        $high = (float) $result['ref_high'];
        $this->assertGreaterThan($low, $high);

        $enter = function (string $value) use ($report, $result) {
            $res = $this->putJson("/api/reports/{$report['id']}/results", [
                'results' => [['id' => $result['id'], 'result_value' => $value]],
            ])->assertOk();

            return collect($res->json('data.report_tests.0.results'))->firstWhere('id', $result['id']);
        };

        $this->assertSame('L', $enter((string) ($low - 1))['result_flag']);
        $this->assertSame('H', $enter((string) ($high + 1))['result_flag']);
        $this->assertNull($enter((string) (($low + $high) / 2))['result_flag']);

        // The workbook only gives adult ranges: a child gets none.
        $child = $this->createReport($this->makePatient(5), 'CA')['report_tests'][0]['results'][0];
        $this->assertNull($child['reference_range_id']);
        $this->assertNull($child['ref_low']);
    }

    public function test_sex_specific_workbook_ranges_follow_the_patient_sex(): void
    {
        $maleRange = ReferenceRange::where('gender', 'male')
            ->whereHas('parameter.testDefinition', fn ($q) => $q->where('code', 'CK'))->firstOrFail();
        $femaleRange = ReferenceRange::where('gender', 'female')
            ->whereHas('parameter.testDefinition', fn ($q) => $q->where('code', 'CK'))->firstOrFail();

        $male = $this->createReport($this->makePatient(30), 'CK')['report_tests'][0]['results'][0];
        $this->assertSame($maleRange->id, $male['reference_range_id']);

        $femalePatient = $this->postJson('/api/patients', [
            'name' => 'Synthetic Patient', 'gender' => 'female', 'phone' => '03001234567', 'address' => 'Test Address',
            'date_of_birth' => now('Asia/Karachi')->subYears(30)->subDays(10)->format('Y-m-d'),
        ])->assertCreated()->json('data');
        $female = $this->createReport($femalePatient, 'CK')['report_tests'][0]['results'][0];
        $this->assertSame($femaleRange->id, $female['reference_range_id']);
    }

    public function test_imported_tests_are_found_by_their_short_forms(): void
    {
        foreach (['cpk' => 'CK', 'amylase' => 'AMYLASE', 'ca 19-9' => 'CA_19_9', 'tibc' => 'TIBC', 'fit' => 'FOBT', 'anti-ccp' => 'ANTI_CCP'] as $query => $code) {
            $json = $this->getJson('/api/catalog/search?limit=50&q='.urlencode($query))->assertOk()->json();
            $codes = collect($json['data'] ?? $json)->pluck('code')->all();

            $this->assertContains($code, $codes, "query: {$query}");
        }
    }
}
