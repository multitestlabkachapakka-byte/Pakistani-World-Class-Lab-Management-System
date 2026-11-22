<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

trait SignsIn
{
    /** Creates a user with the given role and authenticates the next requests as that user. */
    protected function signIn(string $role = 'admin'): User
    {
        $user = User::create([
            'name' => 'Test '.$role,
            'username' => $role.'_'.Str::lower(Str::random(6)),
            'password' => 'Passw0rd-test',
            'role' => $role,
            'is_active' => true,
        ]);

        Sanctum::actingAs($user, ['*']);

        return $user;
    }
}
