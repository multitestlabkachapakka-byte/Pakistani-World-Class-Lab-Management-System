<?php

namespace Tests\Feature;

use App\Models\ReportResult;
use App\Models\TestParameter;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class CalculatedResultsTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
        $this->seed(CatalogSeeder::class);
    }

    private function lftReport(): array
    {
        $patient = $this->postJson('/api/patients', [
            'name' => 'Synthetic Patient', 'gender' => 'male', 'phone' => '03001234567', 'address' => 'Test Address',
            'date_of_birth' => now('Asia/Karachi')->subYears(30)->subDays(10)->format('Y-m-d'),
        ])->assertCreated()->json('data');

        return $this->postJson('/api/reports', ['patient_id' => $patient['id'], 'test_codes' => ['LFT']])
            ->assertCreated()->json('data');
    }

    /** @return array<string, array> */
    private function byName(array $report): array
    {
        return collect($report['report_tests'][0]['results'])->keyBy('parameter_name_snapshot')->all();
    }

    public function test_every_calculated_parameter_has_a_formula_that_refers_to_real_parameters(): void
    {
        $calculated = TestParameter::where('is_calculated', true)->get();
        $this->assertGreaterThanOrEqual(5, $calculated->count());

        foreach ($calculated as $param) {
            $this->assertNotEmpty($param->calculation_formula, "{$param->name} has no formula");

            preg_match_all('/\{([^}]+)\}/', $param->calculation_formula, $m);
            $this->assertNotEmpty($m[1], "{$param->name}: the formula uses no parameter");

            $siblings = TestParameter::where('test_definition_id', $param->test_definition_id)
                ->where('id', '!=', $param->id)->pluck('short_name')
                ->map(fn ($s) => strtolower((string) $s))->all();

            foreach ($m[1] as $name) {
                $this->assertContains(strtolower($name), $siblings, "{$param->name}: unknown parameter {{$name}}");
            }
        }

        $this->assertSame(0, TestParameter::where('is_calculated', false)->whereNotNull('calculation_formula')->count());
    }

    public function test_a_report_freezes_the_short_name_and_formula_of_each_parameter(): void
    {
        $results = $this->byName($this->lftReport());

        $this->assertSame('T.Bil', $results['Total Bilirubin']['short_name_snapshot']);
        $this->assertNull($results['Total Bilirubin']['calculation_formula_snapshot']);

        $this->assertSame('I.Bil', $results['Indirect Bilirubin']['short_name_snapshot']);
        $this->assertStringContainsString('{T.Bil}', $results['Indirect Bilirubin']['calculation_formula_snapshot']);
    }

    public function test_calculated_values_are_recorded_as_auto_filled_and_typed_values_as_manual(): void
    {
        $report = $this->lftReport();
        $results = $this->byName($report);
        $total = $results['Total Bilirubin'];
        $indirect = $results['Indirect Bilirubin'];

        $save = fn (array $rows) => $this->putJson("/api/reports/{$report['id']}/results", ['results' => $rows])->assertOk();

        $save([
            ['id' => $total['id'], 'result_value' => '1.2'],
            ['id' => $indirect['id'], 'result_value' => '0.9', 'was_auto_filled' => true],
        ]);

        $typed = ReportResult::find($total['id']);
        $this->assertFalse($typed->was_auto_filled);
        $this->assertTrue($typed->was_manually_edited);

        $calculated = ReportResult::find($indirect['id']);
        $this->assertTrue($calculated->was_auto_filled);
        $this->assertFalse($calculated->was_manually_edited);

        // An empty value can never count as auto-filled.
        $save([['id' => $indirect['id'], 'result_value' => '', 'was_auto_filled' => true]]);
        $this->assertFalse(ReportResult::find($indirect['id'])->was_auto_filled);

        // Flags apply to calculated values like to any other value.
        $high = (float) $indirect['ref_high'];
        $saved = $save([['id' => $indirect['id'], 'result_value' => (string) ($high + 1), 'was_auto_filled' => true]]);
        $row = collect($saved->json('data.report_tests.0.results'))->firstWhere('id', $indirect['id']);
        $this->assertSame('H', $row['result_flag']);
    }
}
