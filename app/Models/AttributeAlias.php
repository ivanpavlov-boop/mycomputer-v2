<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttributeAlias extends Model
{
    protected $fillable = [
        'canonical_attribute_id',
        'alias',
        'normalized_alias',
        'locale',
        'supplier_id',
        'source_type',
        'confidence',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function canonicalAttribute(): BelongsTo
    {
        return $this->belongsTo(CanonicalAttribute::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
