<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ReferenceRange;
use App\Models\TestDefinition;
use App\Models\TestParameter;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class RangeManagementTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
    }

    private function hbParameter(): TestParameter
    {
        return TestParameter::where('name', 'Hemoglobin')
            ->whereHas('testDefinition', fn ($q) => $q->where('code', 'CBC'))->firstOrFail();
    }

    private function hbRange(string $gender): ReferenceRange
    {
        return ReferenceRange::where('test_parameter_id', $this->hbParameter()->id)
            ->where('gender', $gender)->firstOrFail();
    }

    /** SYNTHETIC values for tests only - not clinical data. */
    private function newRange(array $over = []): array
    {
        return array_merge([
            'gender' => 'both',
            'age_min_days' => 0,
            'age_max_days' => 365,
            'low' => 10,
            'high' => 20,
            'population' => 'SYNTHETIC TEST DATA',
            'source' => 'Unit test fixture - not clinical data',
        ], $over);
    }

    private function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
    }

    private function makePatient(string $gender, string $dob): array
    {
        return $this->postJson('/api/patients', [
            'name' => 'Synthetic Patient', 'gender' => $gender, 'phone' => '03001234567',
            'address' => 'Test Address', 'date_of_birth' => $dob,
        ])->assertCreated()->json('data');
    }

    private function hbResult(array $patient): array
    {
        $report = $this->postJson('/api/reports', ['patient_id' => $patient['id'], 'test_codes' => ['CBC']])
            ->assertCreated()->json('data');

        foreach ($report['report_tests'][0]['results'] as $result) {
            if ($result['parameter_name_snapshot'] === 'Hemoglobin') {
                return $result + ['report_id' => $report['id']];
            }
        }
        $this->fail('no hemoglobin result');
    }

    public function test_admin_and_pathologist_manage_ranges_but_only_a_pathologist_verifies(): void
    {
        $param = $this->hbParameter();
        $range = $this->hbRange('male');

        foreach (['receptionist', 'technician'] as $role) {
            $this->signIn($role);
            $this->postJson("/api/parameters/{$param->id}/ranges", $this->newRange())->assertStatus(403);
            $this->putJson("/api/ranges/{$range->id}", ['source' => 'changed'])->assertStatus(403);
            $this->deleteJson("/api/ranges/{$range->id}")->assertStatus(403);
            $this->postJson("/api/ranges/{$range->id}/verify")->assertStatus(403);
        }

        $this->signIn('admin');
        $this->postJson("/api/parameters/{$param->id}/ranges", $this->newRange())->assertCreated();
        $this->postJson("/api/ranges/{$range->id}/verify")->assertStatus(403); // clinical sign-off is not an admin act
        $this->postJson('/api/catalog/'.$param->test_definition_id.'/verify', ['confirm' => true])->assertStatus(403);

        $this->signIn('pathologist');
        $this->postJson("/api/ranges/{$range->id}/verify")->assertOk();
    }

    public function test_a_range_needs_a_source_and_consistent_numbers(): void
    {
        $this->signIn('admin');
        $param = $this->hbParameter();

        $bad = [
            'no source' => $this->newRange(['source' => null]),
            'low above high' => $this->newRange(['low' => 30, 'high' => 20]),
            'critical low above low' => $this->newRange(['critical_low' => 15]),
            'critical high below high' => $this->newRange(['critical_high' => 15]),
            'max age below min age' => $this->newRange(['age_min_days' => 100, 'age_max_days' => 50]),
            'text disagrees with the limits' => $this->newRange(['range_text' => '1 - 2']),
            'neither limit nor text' => $this->newRange(['low' => null, 'high' => null]),
            'unknown sex' => $this->newRange(['gender' => 'robot']),
        ];

        foreach ($bad as $label => $payload) {
            $this->assertSame(422, $this->postJson("/api/parameters/{$param->id}/ranges", $payload)->status(), $label);
        }
        $this->assertSame(0, ReferenceRange::where('population', 'SYNTHETIC TEST DATA')->count());
    }

    public function test_a_new_range_starts_unverified_is_unique_and_is_used_for_matching_patients(): void
    {
        $this->signIn('admin');
        $param = $this->hbParameter();

        $created = $this->postJson("/api/parameters/{$param->id}/ranges", $this->newRange())
            ->assertCreated()
            ->assertJsonPath('data.verification_status', ReferenceRange::STATUS_PENDING)
            ->assertJsonPath('data.verified_by', null)
            ->assertJsonPath('data.range_text', '10 – 20')
            ->assertJsonPath('data.age_label', '0-365 days')
            ->json('data');
        $this->assertSame($param->unit, $created['unit']);

        // The same sex and age band cannot be entered twice.
        $this->postJson("/api/parameters/{$param->id}/ranges", $this->newRange())->assertStatus(422);

        // A 200-day-old baby now gets that range (before, a child got none).
        $baby = $this->makePatient('male', now('Asia/Karachi')->subDays(200)->format('Y-m-d'));
        $result = $this->hbResult($baby);

        $this->assertSame($created['id'], $result['reference_range_id']);
        $this->assertSame(10.0, (float) $result['ref_low']);
        $this->assertSame(20.0, (float) $result['ref_high']);
        $this->assertSame(ReferenceRange::STATUS_PENDING, $result['reference_verification_status']);
    }

    public function test_editing_a_verified_range_voids_the_verification(): void
    {
        $range = $this->hbRange('male');

        $this->signIn('pathologist');
        $this->postJson("/api/ranges/{$range->id}/verify")->assertOk();
        $verified = $range->fresh();
        $this->assertSame(ReferenceRange::STATUS_VERIFIED, $verified->verification_status);
        $this->assertSame(2, $verified->rev);

        $this->signIn('admin');

        // Saving the same values changes nothing - the verification stays.
        $this->putJson("/api/ranges/{$range->id}", [
            'low' => (float) $verified->low, 'high' => (float) $verified->high,
        ])->assertOk();
        $this->assertSame(ReferenceRange::STATUS_VERIFIED, $range->fresh()->verification_status);
        $this->assertSame(2, $range->fresh()->rev);

        // Inconsistent edits are refused.
        $this->putJson("/api/ranges/{$range->id}", ['low' => 999])->assertStatus(422);

        // A real change voids it, and the printed text follows the new limit.
        $newHigh = (float) $verified->high + 1;
        $this->putJson("/api/ranges/{$range->id}", ['high' => $newHigh])->assertOk();

        $edited = $range->fresh();
        $this->assertSame(ReferenceRange::STATUS_PENDING, $edited->verification_status);
        $this->assertNull($edited->verified_by);
        $this->assertNull($edited->verified_at);
        $this->assertSame(3, $edited->rev);
        $this->assertStringContainsString($this->fmt($newHigh), (string) $edited->range_text);

        $audit = AuditLog::where('action', 'range.updated')->firstOrFail();
        $this->assertTrue($audit->new_values['verification_cleared']);
        $this->assertContains('high', $audit->new_values['fields']);
    }

    public function test_verifying_a_whole_test_records_the_pathologist_and_reaches_new_reports(): void
    {
        $test = TestDefinition::where('code', 'CBC')->firstOrFail();
        $pathologist = $this->signIn('pathologist');

        $this->postJson("/api/catalog/{$test->id}/verify")->assertStatus(422); // confirmation is required
        $res = $this->postJson("/api/catalog/{$test->id}/verify", ['confirm' => true])->assertOk();

        $ranges = ReferenceRange::whereIn('test_parameter_id', $test->parameters()->pluck('id'))->get();
        $this->assertGreaterThan(0, $ranges->count());
        $this->assertSame($ranges->count(), $res->json('data.verified'));
        foreach ($ranges as $r) {
            $this->assertSame(ReferenceRange::STATUS_VERIFIED, $r->verification_status);
            $this->assertSame($pathologist->name, $r->verified_by);
            $this->assertNotNull($r->verified_at);
        }
        $this->assertSame(ReferenceRange::STATUS_VERIFIED, $test->fresh()->verification_status);
        $this->assertSame($pathologist->name, $test->fresh()->verified_by);
        $this->assertSame(1, AuditLog::where('action', 'catalog.test_verified')->count());

        // New reports carry the verification and the finalize notice has nothing to warn about.
        $this->signIn('admin');
        $man = $this->makePatient('male', now('Asia/Karachi')->subYears(30)->subDays(10)->format('Y-m-d'));
        $hb = $this->hbResult($man);
        $this->assertSame(ReferenceRange::STATUS_VERIFIED, $hb['reference_verification_status']);

        $this->putJson("/api/reports/{$hb['report_id']}/results", [
            'results' => [['id' => $hb['id'], 'result_value' => '13.5']],
        ])->assertOk();
        $this->postJson("/api/reports/{$hb['report_id']}/finalize", ['allow_incomplete' => true])
            ->assertOk()->assertJsonPath('meta.unverified_reference_ranges', 0);
    }

    public function test_a_verification_can_be_withdrawn_and_a_range_can_be_removed(): void
    {
        $range = $this->hbRange('male');

        $this->signIn('pathologist');
        $this->postJson("/api/ranges/{$range->id}/verify")->assertOk();
        $this->postJson("/api/ranges/{$range->id}/unverify")->assertOk();
        $this->assertSame(ReferenceRange::STATUS_PENDING, $range->fresh()->verification_status);
        $this->assertNull($range->fresh()->verified_by);

        $this->signIn('admin');
        $this->deleteJson("/api/ranges/{$range->id}")->assertOk();
        $this->assertNull(ReferenceRange::find($range->id));
        $this->assertNotNull(ReferenceRange::withTrashed()->find($range->id)); // kept for history

        // Removed ranges are never applied to new reports.
        $man = $this->makePatient('male', now('Asia/Karachi')->subYears(30)->subDays(10)->format('Y-m-d'));
        $this->assertNull($this->hbResult($man)['reference_range_id']);

        foreach (['range.verified', 'range.unverified', 'range.deleted'] as $action) {
            $this->assertSame(1, AuditLog::where('action', $action)->count(), $action);
        }
    }

    public function test_reseeding_never_undoes_what_staff_have_verified_edited_or_removed(): void
    {
        $verified = $this->hbRange('male');
        $edited = $this->hbRange('female');
        $removed = ReferenceRange::whereHas('parameter', fn ($q) => $q->where('name', 'MCV')
            ->whereHas('testDefinition', fn ($t) => $t->where('code', 'CBC')))->firstOrFail();

        $this->signIn('pathologist');
        $this->postJson("/api/ranges/{$verified->id}/verify")->assertOk();

        $this->signIn('admin');
        $newHigh = (float) $edited->high + 1;
        $this->putJson("/api/ranges/{$edited->id}", ['high' => $newHigh])->assertOk();
        $this->deleteJson("/api/ranges/{$removed->id}")->assertOk();

        $countBefore = ReferenceRange::count();
        $this->seed(CatalogSeeder::class);

        $this->assertSame(ReferenceRange::STATUS_VERIFIED, $verified->fresh()->verification_status);
        $this->assertNotNull($verified->fresh()->verified_by);
        $this->assertEqualsWithDelta($newHigh, (float) $edited->fresh()->high, 0.00001);
        $this->assertNull(ReferenceRange::find($removed->id));
        $this->assertSame($countBefore, ReferenceRange::count());
    }

    public function test_the_review_queue_shows_how_much_is_still_unverified(): void
    {
        $this->signIn('technician'); // everyone may read the status
        $before = $this->getJson('/api/review/catalog')->assertOk();
        $rows = collect($before->json('data'));

        $this->assertSame(TestDefinition::where('is_active', true)->count(), $rows->count());
        $cbc = $rows->firstWhere('code', 'CBC');
        $this->assertGreaterThan(0, $cbc['ranges_pending']);
        $this->assertSame(0, $cbc['ranges_verified']);
        $this->assertSame(ReferenceRange::STATUS_PENDING, $cbc['verification_status']);

        $this->signIn('pathologist');
        $this->postJson("/api/catalog/{$cbc['id']}/verify", ['confirm' => true])->assertOk();

        $after = $this->getJson('/api/review/catalog')->assertOk();
        $cbcAfter = collect($after->json('data'))->firstWhere('code', 'CBC');

        $this->assertSame(0, $cbcAfter['ranges_pending']);
        $this->assertSame(ReferenceRange::STATUS_VERIFIED, $cbcAfter['verification_status']);
        $this->assertSame(
            $before->json('meta.ranges_pending') - $cbc['ranges_pending'],
            $after->json('meta.ranges_pending')
        );
    }
}
