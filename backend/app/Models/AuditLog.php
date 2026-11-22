<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Append-only trail of who did what. There is deliberately no update or delete API. */
class AuditLog extends Model
{
    use HasUuids;

    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'action', 'entity_type', 'entity_id', 'old_values', 'new_values',
        'user_id', 'device_id', 'ip_address', 'notes', 'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];
}
