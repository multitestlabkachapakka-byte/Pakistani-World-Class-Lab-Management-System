<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    private const TINY_PNG = 'data:image/png;base64,iVBORw0KGgo=';

    public function test_defaults_are_returned_before_anything_is_saved(): void
    {
        $this->signIn('receptionist');

        $this->getJson('/api/settings')->assertOk()
            ->assertJsonPath('data.lab_name', '')
            ->assertJsonPath('data.show_unverified_notice', true)
            ->assertJsonPath('data.signatories', []);
    }

    public function test_only_an_admin_can_change_settings_but_everyone_can_read_them(): void
    {
        $this->signIn('admin');
        $this->putJson('/api/settings', ['lab_name' => 'Test Lab', 'show_unverified_notice' => false])->assertOk();

        foreach (['pathologist', 'technician', 'receptionist'] as $role) {
            $this->signIn($role);
            $this->getJson('/api/settings')->assertOk()
                ->assertJsonPath('data.lab_name', 'Test Lab')
                ->assertJsonPath('data.show_unverified_notice', false);
            $this->putJson('/api/settings', ['lab_name' => 'Hijacked'])->assertStatus(403);
        }

        $this->signIn('admin');
        $this->getJson('/api/settings')->assertJsonPath('data.lab_name', 'Test Lab');
    }

    public function test_partial_updates_keep_the_other_settings(): void
    {
        $this->signIn('admin');

        $this->putJson('/api/settings', [
            'lab_name' => 'Test Lab', 'lab_address' => 'Somewhere',
            'signatories' => [['name' => 'Dr Example', 'designation' => 'Pathologist']],
        ])->assertOk();
        $this->putJson('/api/settings', ['lab_phone' => '0300-0000000', 'logo_data_uri' => self::TINY_PNG])->assertOk();

        $this->getJson('/api/settings')
            ->assertJsonPath('data.lab_name', 'Test Lab')
            ->assertJsonPath('data.lab_address', 'Somewhere')
            ->assertJsonPath('data.lab_phone', '0300-0000000')
            ->assertJsonPath('data.logo_data_uri', self::TINY_PNG)
            ->assertJsonPath('data.signatories.0.name', 'Dr Example');

        // Clearing a value returns it to its default.
        $this->putJson('/api/settings', ['lab_address' => null])->assertOk()->assertJsonPath('data.lab_address', '');
    }

    public function test_unsafe_or_invalid_values_are_refused(): void
    {
        $this->signIn('admin');

        $bad = [
            'logo from another site' => ['logo_data_uri' => 'https://example.com/logo.png'],
            'logo that is not an image' => ['logo_data_uri' => 'data:text/html;base64,PHNjcmlwdD4='],
            'logo with a script' => ['logo_data_uri' => 'data:image/png;base64,AAAA"><script>'],
            'name too long' => ['lab_name' => str_repeat('x', 121)],
            'bad e-mail' => ['lab_email' => 'not-an-email'],
            'signatory without a name' => ['signatories' => [['designation' => 'Pathologist']]],
            'too many signatories' => ['signatories' => array_fill(0, 5, ['name' => 'A'])],
        ];

        foreach ($bad as $label => $payload) {
            $this->assertSame(422, $this->putJson('/api/settings', $payload)->status(), $label);
        }
    }

    public function test_the_audit_trail_records_which_settings_changed_but_not_their_values(): void
    {
        $this->signIn('admin');
        $this->putJson('/api/settings', ['lab_name' => 'Very Secret Lab Name', 'logo_data_uri' => self::TINY_PNG])->assertOk();

        $row = AuditLog::where('action', 'settings.updated')->firstOrFail();
        $this->assertEqualsCanonicalizing(['lab_name', 'logo_data_uri'], $row->new_values['keys']);
        $this->assertStringNotContainsString('Very Secret Lab Name', json_encode($row->toArray()));
        $this->assertStringNotContainsString('iVBOR', json_encode($row->toArray()));
    }
}
