<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttributeValueAlias extends Model
{
    protected $fillable = [
        'canonical_attribute_value_id',
        'alias',
        'normalized_alias',
        'supplier_id',
        'locale',
        'confidence',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function canonicalAttributeValue(): BelongsTo
    {
        return $this->belongsTo(CanonicalAttributeValue::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
