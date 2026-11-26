<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class DoctorTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
    }

    private function doctor(array $over = []): array
    {
        return array_merge(['name' => 'Dr Synthetic', 'specialization' => 'Physician', 'phone' => '0300-1111111'], $over);
    }

    private function makePatient(): array
    {
        return $this->postJson('/api/patients', [
            'name' => 'Synthetic Patient', 'gender' => 'male', 'phone' => '03001234567', 'address' => 'Test Address',
            'date_of_birth' => now('Asia/Karachi')->subYears(30)->subDays(10)->format('Y-m-d'),
        ])->assertCreated()->json('data');
    }

    public function test_everyone_reads_the_list_but_only_admin_and_receptionist_change_it(): void
    {
        $this->signIn('admin');
        $first = $this->postJson('/api/doctors', $this->doctor())->assertCreated()->json('data');

        foreach (['pathologist', 'technician'] as $role) {
            $this->signIn($role);
            $this->getJson('/api/doctors')->assertOk()->assertJsonCount(1, 'data');
            $this->postJson('/api/doctors', $this->doctor(['name' => 'Dr Other']))->assertStatus(403);
            $this->putJson("/api/doctors/{$first['id']}", ['city' => 'X'])->assertStatus(403);
        }

        $this->signIn('receptionist');
        $this->postJson('/api/doctors', $this->doctor(['name' => 'Dr Second', 'phone' => '0300-2222222']))->assertCreated();
        $this->putJson("/api/doctors/{$first['id']}", ['city' => 'Sahiwal'])->assertOk()->assertJsonPath('data.city', 'Sahiwal');
    }

    public function test_names_must_be_given_and_the_same_doctor_cannot_be_entered_twice(): void
    {
        $this->signIn('admin');

        $this->postJson('/api/doctors', $this->doctor(['name' => '']))->assertStatus(422);
        $this->postJson('/api/doctors', $this->doctor(['email' => 'not-an-email']))->assertStatus(422);

        $first = $this->postJson('/api/doctors', $this->doctor())->assertCreated()->json('data');
        $this->postJson('/api/doctors', $this->doctor(['name' => 'dr synthetic']))->assertStatus(422); // same name + phone
        $second = $this->postJson('/api/doctors', $this->doctor(['phone' => '0300-9999999']))->assertCreated()->json('data'); // same name, other phone

        // Editing into a duplicate is refused too, and a name can never be blanked.
        $this->putJson("/api/doctors/{$second['id']}", ['phone' => '0300-1111111'])->assertStatus(422);
        $this->putJson("/api/doctors/{$first['id']}", ['name' => ''])->assertStatus(422);
    }

    public function test_doctors_can_be_searched_and_switched_off_but_never_deleted(): void
    {
        $this->signIn('admin');
        $a = $this->postJson('/api/doctors', $this->doctor(['name' => 'Dr Ahmed', 'specialization' => 'Cardiologist', 'phone' => '1']))->assertCreated()->json('data');
        $this->postJson('/api/doctors', $this->doctor(['name' => 'Dr Bilal', 'specialization' => 'Surgeon', 'clinic' => 'City Clinic', 'phone' => '2']))->assertCreated();

        $names = fn (string $url) => collect($this->getJson($url)->assertOk()->json('data'))->pluck('name')->all();
        $this->assertSame(['Dr Ahmed', 'Dr Bilal'], $names('/api/doctors'));
        $this->assertSame(['Dr Ahmed'], $names('/api/doctors?q=cardio'));
        $this->assertSame(['Dr Bilal'], $names('/api/doctors?q=City'));

        $this->putJson("/api/doctors/{$a['id']}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertSame(['Dr Bilal'], $names('/api/doctors'));
        $this->assertSame(['Dr Ahmed', 'Dr Bilal'], $names('/api/doctors?include_inactive=1'));

        $this->deleteJson("/api/doctors/{$a['id']}")->assertStatus(405); // there is no delete
    }

    public function test_changes_are_versioned_and_audited(): void
    {
        $this->signIn('admin');
        $doctor = $this->postJson('/api/doctors', $this->doctor())->assertCreated()->json('data');
        $this->assertSame(1, $doctor['rev']);

        $this->putJson("/api/doctors/{$doctor['id']}", ['city' => 'Lahore'])->assertOk()->assertJsonPath('data.rev', 2);
        $this->putJson("/api/doctors/{$doctor['id']}", ['city' => 'Lahore'])->assertOk()->assertJsonPath('data.rev', 2); // no change, no new version

        $this->assertSame(1, AuditLog::where('action', 'doctor.created')->count());
        $this->assertSame(1, AuditLog::where('action', 'doctor.updated')->count());
    }

    public function test_a_report_records_the_referring_doctor_by_name_and_keeps_it_when_the_doctor_changes(): void
    {
        $this->signIn('admin');
        $doctor = $this->postJson('/api/doctors', $this->doctor(['name' => 'Dr Original']))->assertCreated()->json('data');
        $patient = $this->makePatient();

        $report = $this->postJson('/api/reports', [
            'patient_id' => $patient['id'], 'doctor_id' => $doctor['id'], 'test_codes' => ['CBC'],
        ])->assertCreated()->json('data');
        $this->assertSame('Dr Original', $report['referred_by']);
        $this->assertSame($doctor['id'], $report['doctor_id']);

        $this->putJson("/api/doctors/{$doctor['id']}", ['name' => 'Dr Renamed'])->assertOk();
        $this->getJson("/api/reports/{$report['id']}")->assertOk()->assertJsonPath('data.referred_by', 'Dr Original');

        // Without a doctor the report says so.
        $walkIn = $this->postJson('/api/reports', ['patient_id' => $patient['id'], 'test_codes' => ['CBC']])->assertCreated();
        $walkIn->assertJsonPath('data.referred_by', 'Self / Walk-in');
    }

    public function test_an_unknown_or_switched_off_doctor_cannot_be_chosen_for_a_report(): void
    {
        $this->signIn('admin');
        $doctor = $this->postJson('/api/doctors', $this->doctor())->assertCreated()->json('data');
        $patient = $this->makePatient();
        $this->putJson("/api/doctors/{$doctor['id']}", ['is_active' => false])->assertOk();

        foreach ([$doctor['id'], '5f1d8f0e-0000-4000-8000-000000000000'] as $id) {
            $this->postJson('/api/reports', ['patient_id' => $patient['id'], 'doctor_id' => $id, 'test_codes' => ['CBC']])
                ->assertStatus(422);
        }
    }

    public function test_the_report_list_names_the_tests_for_the_patient_history(): void
    {
        $this->signIn('admin');
        $patient = $this->makePatient();
        $this->postJson('/api/reports', ['patient_id' => $patient['id'], 'test_codes' => ['CBC', 'LFT']])->assertCreated();

        $rows = $this->getJson('/api/reports?patient_id='.$patient['id'])->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertEqualsCanonicalizing(
            ['Complete Blood Count (Blood C/E)', 'Liver Function Tests (with GGT)'],
            array_column($rows[0]['report_tests'], 'test_name_snapshot')
        );
    }
}
