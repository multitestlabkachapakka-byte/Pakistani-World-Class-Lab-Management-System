<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportResult extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'report_id', 'report_test_id', 'test_parameter_id',
        'parameter_name_snapshot', 'unit_snapshot', 'result_value',
        'result_flag', 'reference_range_text', 'ref_low', 'ref_high',
        'is_abnormal', 'is_critical', 'print_selected',
        'was_auto_filled', 'was_manually_edited', 'sort_order', 'interpretation',
    ];

    protected $casts = [
        'ref_low' => 'decimal:4',
        'ref_high' => 'decimal:4',
        'is_abnormal' => 'boolean',
        'is_critical' => 'boolean',
        'print_selected' => 'boolean',
        'was_auto_filled' => 'boolean',
        'was_manually_edited' => 'boolean',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function reportTest(): BelongsTo
    {
        return $this->belongsTo(ReportTest::class);
    }
}
