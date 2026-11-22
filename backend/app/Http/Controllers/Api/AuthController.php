<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string|max:50',
            'password' => 'required|string|max:200',
            'device_name' => 'nullable|string|max:100',
        ]);

        $username = strtolower(trim($data['username']));
        $user = User::where('username', $username)->first();

        if ($user === null) {
            Hash::make($data['password']); // similar work for unknown users, so timing does not reveal them
        }

        if ($user === null || !$user->is_active || !Hash::check($data['password'], $user->password)) {
            AuditLogger::record($request, 'auth.login_failed', 'user', $user?->id, null, ['username' => $username]);

            throw ValidationException::withMessages(['username' => 'Invalid username or password.']);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken(
            $data['device_name'] ?? 'web',
            ['*'],
            now()->addDays((int) config('lims.token_days', 30))
        );

        AuditLogger::record($request, 'auth.login', 'user', $user->id, null, null, null, $user->id);

        return response()->json(['data' => [
            'token' => $token->plainTextToken,
            'user' => $user->present(),
        ]]);
    }

    public function me(Request $request)
    {
        return response()->json(['data' => $request->user()->present()]);
    }

    public function logout(Request $request)
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        AuditLogger::record($request, 'auth.logout', 'user', $request->user()->id);

        return response()->json(['message' => 'Logged out']);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $user = $request->user();

        if (!Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }

        $user->forceFill(['password' => $data['password']])->save();

        // Sign out every other device; keep this one.
        $current = $user->currentAccessToken();
        $user->tokens()
            ->when($current instanceof PersonalAccessToken, fn ($q) => $q->where('id', '!=', $current->id))
            ->delete();

        AuditLogger::record($request, 'auth.password_changed', 'user', $user->id);

        return response()->json(['message' => 'Password changed']);
    }
}
