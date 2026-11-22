<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class PatientApiTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'Synthetic Patient',
            'gender' => 'male',
            'phone' => '03001234567',
            'address' => 'Test Address',
            'date_of_birth' => now('Asia/Karachi')->subYears(30)->subDays(10)->format('Y-m-d'),
        ], $over);
    }

    public function test_mr_numbers_are_sequential_and_carry_the_device_code(): void
    {
        $a = $this->postJson('/api/patients', $this->payload())->assertCreated()->json('data.mr_number');
        $b = $this->postJson('/api/patients', $this->payload())->assertCreated()->json('data.mr_number');

        $this->assertMatchesRegularExpression('/^\d{6}-P-0001$/', $a);
        $this->assertMatchesRegularExpression('/^\d{6}-P-0002$/', $b);

        // Client-supplied device codes are reduced to A-Z0-9, so a number can never be malformed.
        $c = $this->withHeader('X-Device-Code', 'a-1/x')->postJson('/api/patients', $this->payload())
            ->assertCreated()->json('data.mr_number');
        $this->assertMatchesRegularExpression('/^\d{6}-A1X-0001$/', $c);
    }

    public function test_invalid_registration_is_rejected(): void
    {
        $this->postJson('/api/patients', $this->payload(['gender' => 'robot']))->assertStatus(422);
        $this->postJson('/api/patients', $this->payload(['name' => '']))->assertStatus(422);
    }

    public function test_patients_can_be_found_by_name_and_by_mr_number(): void
    {
        $mr = $this->postJson('/api/patients', $this->payload())->assertCreated()->json('data.mr_number');

        $byName = $this->getJson('/api/patients?q=Synthetic')->assertOk()->json('data');
        $byMr = $this->getJson('/api/patients?q='.$mr)->assertOk()->json('data');

        $this->assertCount(1, $byName);
        $this->assertCount(1, $byMr);
    }
}
