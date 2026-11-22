<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Staff accounts. Admin only (enforced in routes). */
class UserController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => User::orderBy('username')->get()->map->present()->values(),
        ]);
    }

    public function store(Request $request)
    {
        $request->merge(['username' => strtolower(trim((string) $request->input('username')))]);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|min:3|max:50|alpha_dash:ascii|unique:users,username',
            'email' => 'nullable|email|max:255|unique:users,email',
            'password' => ['required', Password::min(8)->letters()->numbers()],
            'role' => ['required', Rule::in(User::ROLES)],
            'branch_id' => 'nullable|uuid',
        ]);

        $user = User::create($data + ['is_active' => true]);

        AuditLogger::record($request, 'user.created', 'user', $user->id, null, [
            'username' => $user->username,
            'role' => $user->role,
        ]);

        return response()->json(['data' => $user->present()], 201);
    }

    public function update(Request $request, string $id)
    {
        $user = User::findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', Password::min(8)->letters()->numbers()],
            'role' => ['sometimes', Rule::in(User::ROLES)],
            'is_active' => 'sometimes|boolean',
            'branch_id' => 'nullable|uuid',
        ]);

        // Never lock the laboratory out: keep at least one active administrator.
        $losesAdmin = $user->role === User::ROLE_ADMIN && $user->is_active
            && ((isset($data['role']) && $data['role'] !== User::ROLE_ADMIN)
                || (array_key_exists('is_active', $data) && !$data['is_active']));

        if ($losesAdmin) {
            $otherAdmins = User::where('role', User::ROLE_ADMIN)
                ->where('is_active', true)
                ->where('id', '!=', $user->id)
                ->count();

            if ($otherAdmins === 0) {
                return response()->json(['message' => 'At least one active administrator is required.'], 422);
            }
        }

        $user->update($data);

        // A password reset or deactivation signs the user out everywhere.
        if (isset($data['password']) || (array_key_exists('is_active', $data) && !$data['is_active'])) {
            $user->tokens()->delete();
        }

        AuditLogger::record($request, 'user.updated', 'user', $user->id, null, [
            'fields' => array_keys(Arr::except($data, ['password'])),
            'password_reset' => isset($data['password']),
        ]);

        return response()->json(['data' => $user->fresh()->present()]);
    }
}
