<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Doctor extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'name', 'qualification', 'specialization', 'pmdc_number',
        'clinic', 'city', 'phone', 'email', 'is_active', 'is_default_self',
        'updated_by_device', 'rev',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default_self' => 'boolean',
        'rev' => 'integer',
        'deleted_at' => 'datetime',
    ];
}
