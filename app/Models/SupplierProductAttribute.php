<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierProductAttribute extends Model
{
    public const STATUSES = ['mapped', 'unmapped', 'needs_review', 'ignored'];

    protected $fillable = [
        'supplier_product_id',
        'supplier_id',
        'product_id',
        'source_type',
        'source_code',
        'raw_name',
        'raw_value',
        'raw_unit',
        'canonical_attribute_id',
        'canonical_attribute_value_id',
        'normalized_name',
        'normalized_value',
        'confidence',
        'status',
    ];

    public function supplierProduct(): BelongsTo
    {
        return $this->belongsTo(SupplierProduct::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
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
