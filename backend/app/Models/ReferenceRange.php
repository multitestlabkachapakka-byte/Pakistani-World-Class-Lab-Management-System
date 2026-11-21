<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReferenceRange extends Model
{
    use HasUuids, SoftDeletes;

    /** Default for every range: nobody has verified it yet (pathologist review pending). */
    public const STATUS_PENDING = 'VERIFY REQUIRED';

    /** Only a pathologist action may set this, together with verified_by + verified_at. */
    public const STATUS_VERIFIED = 'VERIFIED';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'test_parameter_id', 'gender', 'age_min_days', 'age_max_days',
        'age_label', 'low', 'high', 'critical_low', 'critical_high',
        'text_normal', 'range_text', 'unit', 'population', 'methodology',
        'source', 'verification_status', 'verified_by', 'verified_at',
        'updated_by_device', 'rev',
    ];

    protected $casts = [
        'low' => 'decimal:4',
        'high' => 'decimal:4',
        'critical_low' => 'decimal:4',
        'critical_high' => 'decimal:4',
        'verified_at' => 'datetime',
        'rev' => 'integer',
    ];

    public function parameter(): BelongsTo
    {
        return $this->belongsTo(TestParameter::class, 'test_parameter_id');
    }
}
