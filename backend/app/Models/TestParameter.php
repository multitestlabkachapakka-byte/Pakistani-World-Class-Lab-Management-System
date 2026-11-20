<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TestParameter extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'test_definition_id', 'name', 'short_name', 'unit',
        'data_type', 'is_calculated', 'calculation_formula',
        'sort_order', 'print_by_default',
    ];

    protected $casts = [
        'is_calculated' => 'boolean',
        'print_by_default' => 'boolean',
    ];

    public function testDefinition(): BelongsTo
    {
        return $this->belongsTo(TestDefinition::class);
    }

    public function referenceRanges(): HasMany
    {
        return $this->hasMany(ReferenceRange::class);
    }
}
