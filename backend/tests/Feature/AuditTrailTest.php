<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    public function test_key_actions_are_recorded_without_passwords_or_result_values(): void
    {
        $this->seed(CatalogSeeder::class);
        $user = $this->signIn('admin');

        $patient = $this->postJson('/api/patients', [
            'name' => 'Synthetic Patient',
            'gender' => 'male',
            'phone' => '03001234567',
            'address' => 'Test Address',
            'date_of_birth' => now('Asia/Karachi')->subYears(30)->subDays(10)->format('Y-m-d'),
        ])->assertCreated()->json('data');

        $report = $this->postJson('/api/reports', ['patient_id' => $patient['id'], 'test_codes' => ['CBC']])
            ->assertCreated()->json('data');
        $resultId = $report['report_tests'][0]['results'][0]['id'];

        $this->putJson("/api/reports/{$report['id']}/results", [
            'results' => [['id' => $resultId, 'result_value' => '13.5']],
        ])->assertOk();
        $this->postJson("/api/reports/{$report['id']}/finalize", ['allow_incomplete' => true])->assertOk();

        $actions = AuditLog::pluck('action')->all();
        foreach (['patient.created', 'report.created', 'report.results_updated', 'report.finalized'] as $expected) {
            $this->assertContains($expected, $actions);
        }

        $created = AuditLog::where('action', 'patient.created')->firstOrFail();
        $this->assertSame($user->id, $created->user_id);
        $this->assertSame($patient['id'], $created->entity_id);
        $this->assertSame('127.0.0.1', $created->ip_address);

        $finalized = AuditLog::where('action', 'report.finalized')->firstOrFail();
        $this->assertSame($report['id'], $finalized->entity_id);
        $this->assertSame($user->id, \App\Models\Report::find($report['id'])->finalized_by);

        // A failed login is recorded - the password never is.
        $this->postJson('/api/auth/login', ['username' => 'ghost', 'password' => 'SuperSecret-123'])->assertStatus(422);
        $this->assertSame(1, AuditLog::where('action', 'auth.login_failed')->count());

        foreach (AuditLog::all() as $row) {
            $json = json_encode($row->toArray());
            $this->assertStringNotContainsString('SuperSecret-123', $json);
            $this->assertStringNotContainsString('13.5', $json, 'result values must not be copied into the audit trail');
        }
    }
}
