<?php

namespace App\Models;

use Database\Factories\ProductAttributeValueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAttributeValue extends Model
{
    /** @use HasFactory<ProductAttributeValueFactory> */
    use HasFactory;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_IMPORT_PREVIEW = 'import_preview';

    public const SOURCE_CONTROLLED_SYNC = 'controlled_sync';

    protected $fillable = [
        'product_id',
        'product_attribute_id',
        'canonical_attribute_id',
        'canonical_attribute_value_id',
        'attribute_value_id',
        'custom_value',
        'value_text',
        'value_number',
        'value_boolean',
        'value_json',
        'unit',
        'source',
        'is_verified',
        'sort_order',
        'is_filterable',
    ];

    protected function casts(): array
    {
        return [
            'value_number' => 'decimal:4',
            'value_boolean' => 'boolean',
            'value_json' => 'array',
            'is_verified' => 'boolean',
            'is_filterable' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class, 'product_attribute_id');
    }

    public function value(): BelongsTo
    {
        return $this->belongsTo(AttributeValue::class, 'attribute_value_id');
    }

    public function canonicalAttribute(): BelongsTo
    {
        return $this->belongsTo(CanonicalAttribute::class);
    }

    public function canonicalAttributeValue(): BelongsTo
    {
        return $this->belongsTo(CanonicalAttributeValue::class);
    }
}
