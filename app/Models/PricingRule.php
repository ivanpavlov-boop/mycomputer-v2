<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingRule extends Model
{
    public const SCOPE_PRODUCT = 'product';

    public const SCOPE_CATEGORY_BRAND_SUPPLIER = 'category_brand_supplier';

    public const SCOPE_CATEGORY_BRAND = 'category_brand';

    public const SCOPE_CATEGORY_SUPPLIER = 'category_supplier';

    public const SCOPE_CATEGORY = 'category';

    public const SCOPE_BRAND = 'brand';

    public const SCOPE_SUPPLIER = 'supplier';

    public const SCOPE_PRICE_RANGE = 'price_range';

    public const SCOPE_GLOBAL = 'global';

    public const MARGIN_PERCENTAGE = 'percentage';

    public const MARGIN_FIXED = 'fixed';

    public const MSRP_MARGIN_ONLY = 'margin_only';

    public const MSRP_RECOMMENDED_ONLY = 'recommended_only';

    public const MSRP_RECOMMENDED_MIN_MARGIN = 'recommended_min_margin';

    public const MSRP_HIGHER_OF_MARGIN_OR_RECOMMENDED = 'higher_of_margin_or_recommended';

    public const MSRP_LOWER_OF_MARGIN_OR_RECOMMENDED = 'lower_of_margin_or_recommended';

    public const ROUND_NONE = 'none';

    public const ROUND_NEAREST_0_01 = 'nearest_0_01';

    public const ROUND_NEAREST_0_05 = 'nearest_0_05';

    public const ROUND_NEAREST_0_10 = 'nearest_0_10';

    public const ROUND_UP_0_99 = 'up_0_99';

    protected $fillable = [
        'name',
        'scope_type',
        'product_id',
        'category_id',
        'brand_id',
        'supplier_id',
        'price_min',
        'price_max',
        'margin_type',
        'margin_value',
        'minimum_margin',
        'minimum_final_price',
        'rounding_rule',
        'msrp_strategy',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'margin_value' => 'decimal:4',
            'price_min' => 'decimal:2',
            'price_max' => 'decimal:2',
            'minimum_margin' => 'decimal:2',
            'minimum_final_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
