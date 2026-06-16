<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierExclusionRule extends Model
{
    protected $fillable = [
        'name',
        'is_active',
        'supplier_id',
        'category_id',
        'brand_id',
        'sku',
        'ean',
        'mpn',
        'product_name_contains',
        'exclude_zero_stock',
        'exclude_eol',
        'exclude_missing_ean',
        'min_price',
        'max_price',
        'priority',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'exclude_zero_stock' => 'boolean',
            'exclude_eol' => 'boolean',
            'exclude_missing_ean' => 'boolean',
            'min_price' => 'decimal:2',
            'max_price' => 'decimal:2',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
