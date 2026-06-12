<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AvailabilityStatusMapping extends Model
{
    public const SOURCE_TYPES = ['supplier', 'erp', 'xml', 'csv', 'api', 'manual'];

    protected $fillable = [
        'source_type',
        'source_code',
        'external_status',
        'external_status_label',
        'availability_status_id',
        'priority',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function availabilityStatus(): BelongsTo
    {
        return $this->belongsTo(AvailabilityStatus::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
