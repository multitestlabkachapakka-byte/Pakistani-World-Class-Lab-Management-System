<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TestDefinition extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'code', 'name', 'short_name', 'department', 'aliases',
        'sample_type', 'methodology', 'is_profile', 'is_active',
        'verification_status', 'last_verified', 'verified_by', 'source', 'notes',
        'sort_order', 'updated_by_device', 'rev',
    ];

    protected $casts = [
        'aliases' => 'array',
        'is_profile' => 'boolean',
        'is_active' => 'boolean',
        'last_verified' => 'date',
        'rev' => 'integer',
        'deleted_at' => 'datetime',
    ];

    public function parameters(): HasMany
    {
        return $this->hasMany(TestParameter::class);
    }
}
