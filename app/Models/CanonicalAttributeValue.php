<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CanonicalAttributeValue extends Model
{
    protected $fillable = [
        'canonical_attribute_id',
        'normalized_value',
        'display_value',
        'numeric_value',
        'unit',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'numeric_value' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function canonicalAttribute(): BelongsTo
    {
        return $this->belongsTo(CanonicalAttribute::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(AttributeValueAlias::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }
}
