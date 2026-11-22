<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role = 'technician', bool $active = true): User
    {
        return User::create([
            'name' => 'Staff '.$role,
            'username' => $role.'1',
            'password' => 'Passw0rd-test',
            'role' => $role,
            'is_active' => $active,
        ]);
    }

    private function setupPayload(array $over = []): array
    {
        return array_merge([
            'name' => 'Lab Owner',
            'username' => 'Owner',
            'password' => 'Strong-pass1',
            'password_confirmation' => 'Strong-pass1',
        ], $over);
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_first_run_setup_creates_one_admin_and_then_closes(): void
    {
        $this->getJson('/api/setup/status')->assertOk()->assertJsonPath('data.needs_setup', true);

        $res = $this->postJson('/api/setup', $this->setupPayload())->assertCreated();
        $res->assertJsonPath('data.user.role', 'admin')->assertJsonPath('data.user.username', 'owner');
        $this->assertNotEmpty($res->json('data.token'));

        $this->getJson('/api/setup/status')->assertOk()->assertJsonPath('data.needs_setup', false);
        $this->postJson('/api/setup', $this->setupPayload(['username' => 'second']))->assertStatus(409);
        $this->assertSame(1, User::count());
    }

    public function test_setup_needs_a_strong_password_and_is_local_only(): void
    {
        $this->postJson('/api/setup', $this->setupPayload(['password' => 'short1', 'password_confirmation' => 'short1']))
            ->assertStatus(422);
        $this->postJson('/api/setup', $this->setupPayload(['password' => 'onlyletters', 'password_confirmation' => 'onlyletters']))
            ->assertStatus(422);
        $this->postJson('/api/setup', $this->setupPayload(['password_confirmation' => 'different1']))
            ->assertStatus(422);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/api/setup', $this->setupPayload())
            ->assertStatus(403);
        $this->assertSame(0, User::count());

        config(['lims.allow_remote_setup' => true]);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/api/setup', $this->setupPayload())
            ->assertCreated();
    }

    public function test_protected_routes_require_a_login(): void
    {
        foreach ([
            '/api/patients', '/api/dashboard', '/api/catalog/search?q=cbc', '/api/reports',
            '/api/sync/status', '/api/users', '/api/auth/me',
        ] as $url) {
            $this->getJson($url)->assertStatus(401);
        }

        $this->postJson('/api/patients', [])->assertStatus(401);
        $this->postJson('/api/reports', [])->assertStatus(401);
    }

    public function test_login_returns_a_token_that_works_until_logout(): void
    {
        $this->makeUser('technician');

        $login = $this->postJson('/api/auth/login', ['username' => 'Technician1', 'password' => 'Passw0rd-test'])->assertOk();
        $token = $login->json('data.token');
        $this->assertNotEmpty($token);

        $this->getJson('/api/auth/me', $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('data.username', 'technician1')
            ->assertJsonMissingPath('data.password');

        $this->postJson('/api/auth/logout', [], $this->bearer($token))->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/auth/me', $this->bearer($token))->assertStatus(401);
    }

    public function test_wrong_password_unknown_user_and_inactive_user_cannot_log_in(): void
    {
        $this->makeUser('technician');
        $this->makeUser('receptionist', false);

        $this->postJson('/api/auth/login', ['username' => 'technician1', 'password' => 'wrong-pass1'])->assertStatus(422);
        $this->postJson('/api/auth/login', ['username' => 'nobody', 'password' => 'Passw0rd-test'])->assertStatus(422);
        $this->postJson('/api/auth/login', ['username' => 'receptionist1', 'password' => 'Passw0rd-test'])->assertStatus(422);
    }

    public function test_login_is_rate_limited(): void
    {
        $this->makeUser('technician');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['username' => 'technician1', 'password' => 'wrong-pass1'])->assertStatus(422);
        }

        $this->postJson('/api/auth/login', ['username' => 'technician1', 'password' => 'Passw0rd-test'])->assertStatus(429);
    }

    public function test_a_deactivated_account_stops_working_immediately(): void
    {
        $user = $this->makeUser('technician');
        $token = $this->postJson('/api/auth/login', ['username' => 'technician1', 'password' => 'Passw0rd-test'])->json('data.token');

        $user->forceFill(['is_active' => false])->save();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/auth/me', $this->bearer($token))->assertStatus(403);
    }

    public function test_changing_the_password_needs_the_current_one_and_signs_out_other_devices(): void
    {
        $this->makeUser('technician');

        $a = $this->postJson('/api/auth/login', ['username' => 'technician1', 'password' => 'Passw0rd-test', 'device_name' => 'phone'])->json('data.token');
        $this->app['auth']->forgetGuards();
        $b = $this->postJson('/api/auth/login', ['username' => 'technician1', 'password' => 'Passw0rd-test', 'device_name' => 'pc'])->json('data.token');

        $change = fn (string $current) => [
            'current_password' => $current,
            'password' => 'Newpass-123',
            'password_confirmation' => 'Newpass-123',
        ];

        $this->app['auth']->forgetGuards();
        $this->putJson('/api/auth/password', $change('not-the-password'), $this->bearer($a))->assertStatus(422);

        $this->app['auth']->forgetGuards();
        $this->putJson('/api/auth/password', $change('Passw0rd-test'), $this->bearer($a))->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/auth/me', $this->bearer($a))->assertOk();          // this device stays signed in
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/auth/me', $this->bearer($b))->assertStatus(401);   // the other device is signed out

        $this->postJson('/api/auth/login', ['username' => 'technician1', 'password' => 'Newpass-123'])->assertOk();
    }
}
