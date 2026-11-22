<?php

namespace Tests\Feature;

use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class RolesAndUsersTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    private function patientPayload(): array
    {
        return [
            'name' => 'Synthetic Patient',
            'gender' => 'male',
            'phone' => '03001234567',
            'address' => 'Test Address',
            'date_of_birth' => now('Asia/Karachi')->subYears(30)->subDays(10)->format('Y-m-d'),
        ];
    }

    public function test_each_role_can_only_do_its_own_work(): void
    {
        $this->seed(CatalogSeeder::class);

        $this->signIn('admin');
        $patient = $this->postJson('/api/patients', $this->patientPayload())->assertCreated()->json('data');
        $report = $this->postJson('/api/reports', ['patient_id' => $patient['id'], 'test_codes' => ['CBC']])
            ->assertCreated()->json('data');
        $resultId = $report['report_tests'][0]['results'][0]['id'];
        $results = fn () => ['results' => [['id' => $resultId, 'result_value' => '1']]];

        // Receptionist: registers patients and books tests; no result entry, sign-off, deletion, sync or user admin.
        $this->signIn('receptionist');
        $this->postJson('/api/patients', $this->patientPayload())->assertCreated();
        $this->putJson("/api/reports/{$report['id']}/results", $results())->assertStatus(403);
        $this->postJson("/api/reports/{$report['id']}/finalize")->assertStatus(403);
        $this->deleteJson("/api/patients/{$patient['id']}")->assertStatus(403);
        $this->postJson('/api/sync/run')->assertStatus(403);
        $this->getJson('/api/users')->assertStatus(403);

        // Technician: enters results; cannot delete patients, see sync status or manage users.
        $this->signIn('technician');
        $this->putJson("/api/reports/{$report['id']}/results", $results())->assertOk();
        $this->deleteJson("/api/patients/{$patient['id']}")->assertStatus(403);
        $this->getJson('/api/sync/status')->assertStatus(403);
        $this->getJson('/api/users')->assertStatus(403);

        // Pathologist: may enter results, but cannot run sync, delete patients or manage users.
        $this->signIn('pathologist');
        $this->putJson("/api/reports/{$report['id']}/results", $results())->assertOk();
        $this->postJson('/api/sync/run')->assertStatus(403);
        $this->deleteJson("/api/patients/{$patient['id']}")->assertStatus(403);
        $this->getJson('/api/users')->assertStatus(403);

        // Admin: may delete (soft-delete) the patient.
        $this->signIn('admin');
        $this->deleteJson("/api/patients/{$patient['id']}")->assertOk();
    }

    public function test_admin_manages_staff_and_cannot_remove_the_last_admin(): void
    {
        $admin = $this->signIn('admin');

        $created = $this->postJson('/api/users', [
            'name' => 'New Tech', 'username' => 'NewTech', 'password' => 'Techpass-1', 'role' => 'technician',
        ])->assertCreated()
            ->assertJsonPath('data.username', 'newtech')
            ->assertJsonPath('data.role', 'technician')
            ->json('data');

        $this->postJson('/api/users', [
            'name' => 'Dup', 'username' => 'newtech', 'password' => 'Techpass-1', 'role' => 'technician',
        ])->assertStatus(422);
        $this->postJson('/api/users', [
            'name' => 'Bad', 'username' => 'badrole', 'password' => 'Techpass-1', 'role' => 'superuser',
        ])->assertStatus(422);

        // The only administrator can be neither demoted nor deactivated.
        $this->putJson("/api/users/{$admin->id}", ['role' => 'technician'])->assertStatus(422);
        $this->putJson("/api/users/{$admin->id}", ['is_active' => false])->assertStatus(422);

        // With a second administrator it is allowed.
        $this->putJson("/api/users/{$created['id']}", ['role' => 'admin'])->assertOk();
        $this->putJson("/api/users/{$admin->id}", ['role' => 'technician'])->assertOk();
    }
}
