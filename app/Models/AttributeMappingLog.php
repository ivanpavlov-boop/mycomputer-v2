<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttributeMappingLog extends Model
{
    public const ACTIONS = ['mapped', 'fallback', 'needs_review', 'ignored', 'failed'];

    protected $fillable = [
        'source_type',
        'source_code',
        'supplier_id',
        'raw_name',
        'raw_value',
        'mapped_attribute_id',
        'mapped_value_id',
        'confidence',
        'action',
        'message',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function mappedAttribute(): BelongsTo
    {
        return $this->belongsTo(CanonicalAttribute::class, 'mapped_attribute_id');
    }

    public function mappedValue(): BelongsTo
    {
        return $this->belongsTo(CanonicalAttributeValue::class, 'mapped_value_id');
    }
}
