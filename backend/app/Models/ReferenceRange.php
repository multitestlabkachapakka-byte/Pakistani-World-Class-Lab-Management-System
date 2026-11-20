<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferenceRange extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'test_parameter_id', 'gender', 'age_min_days', 'age_max_days',
        'age_label', 'low', 'high', 'critical_low', 'critical_high',
        'text_normal', 'range_text', 'source', 'verification_status',
    ];

    protected $casts = [
        'low' => 'decimal:4',
        'high' => 'decimal:4',
        'critical_low' => 'decimal:4',
        'critical_high' => 'decimal:4',
    ];

    public function parameter(): BelongsTo
    {
        return $this->belongsTo(TestParameter::class, 'test_parameter_id');
    }
}
