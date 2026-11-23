<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One laboratory setting (branding, report text ...). The value is stored as {"value": ...}. */
class LabSetting extends Model
{
    use HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['id', 'key', 'value', 'group', 'updated_by_device', 'rev'];

    protected $casts = [
        'value' => 'array',
        'rev' => 'integer',
    ];
}
