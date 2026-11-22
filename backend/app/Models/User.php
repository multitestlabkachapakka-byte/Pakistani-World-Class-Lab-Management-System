<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_PATHOLOGIST = 'pathologist';
    public const ROLE_TECHNICIAN = 'technician';
    public const ROLE_RECEPTIONIST = 'receptionist';

    public const ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_PATHOLOGIST,
        self::ROLE_TECHNICIAN,
        self::ROLE_RECEPTIONIST,
    ];

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name', 'username', 'email', 'password', 'role', 'is_active', 'branch_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /** Safe representation for API responses. */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'role' => $this->role,
            'is_active' => $this->is_active,
            'branch_id' => $this->branch_id,
            'last_login_at' => $this->last_login_at,
        ];
    }
}
