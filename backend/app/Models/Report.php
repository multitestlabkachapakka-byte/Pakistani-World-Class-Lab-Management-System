<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Report extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'report_number', 'patient_id', 'doctor_id', 'branch_id',
        'status', 'sample_type', 'collected_at', 'received_at', 'reported_at',
        'referred_by', 'clinical_notes', 'comments', 'finalized_by',
        'snapshot', 'updated_by_device', 'rev',
    ];

    protected $casts = [
        'collected_at' => 'datetime',
        'received_at' => 'datetime',
        'reported_at' => 'datetime',
        'snapshot' => 'array',
        'rev' => 'integer',
        'deleted_at' => 'datetime',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function reportTests(): HasMany
    {
        return $this->hasMany(ReportTest::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(ReportResult::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ReportRevision::class);
    }
}
