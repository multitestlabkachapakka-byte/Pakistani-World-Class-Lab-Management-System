<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/**
 * First-run setup: creates the first administrator. Works only while no user exists, and only
 * from the server computer (localhost) unless LIMS_ALLOW_REMOTE_SETUP=true. There is no default
 * password anywhere in the code or the database.
 */
class SetupController extends Controller
{
    public function status()
    {
        return response()->json(['data' => ['needs_setup' => User::query()->doesntExist()]]);
    }

    public function store(Request $request)
    {
        if (!config('lims.allow_remote_setup') && !in_array($request->ip(), ['127.0.0.1', '::1'], true)) {
            return response()->json([
                'message' => 'First-time setup must be done from the server computer (localhost).',
            ], 403);
        }

        $request->merge(['username' => strtolower(trim((string) $request->input('username')))]);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|min:3|max:50|alpha_dash:ascii',
            'email' => 'nullable|email|max:255',
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $user = DB::transaction(function () use ($data) {
            if (User::query()->exists()) {
                return null;
            }

            return User::create($data + ['role' => User::ROLE_ADMIN, 'is_active' => true]);
        });

        if ($user === null) {
            return response()->json(['message' => 'Setup has already been completed.'], 409);
        }

        AuditLogger::record($request, 'setup.admin_created', 'user', $user->id, null, ['username' => $user->username], null, $user->id);

        $token = $user->createToken('setup', ['*'], now()->addDays((int) config('lims.token_days', 30)));

        return response()->json(['data' => [
            'token' => $token->plainTextToken,
            'user' => $user->present(),
        ]], 201);
    }
}
