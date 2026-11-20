<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Patient extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'mr_number',
        'name',
        'guardian_name',
        'guardian_relation',
        'date_of_birth',
        'age_years',
        'age_months',
        'age_days',
        'gender',
        'phone',
        'phone_raw',
        'cnic',
        'whatsapp',
        'email',
        'address',
        'city',
        'notes',
        'branch_id',
        'updated_by_device',
        'rev',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'rev' => 'integer',
        'deleted_at' => 'datetime',
    ];
}
