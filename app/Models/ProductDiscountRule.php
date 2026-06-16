<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductDiscountRule extends Model
{
    public const SCOPE_PRODUCT = 'product';

    public const SCOPE_CATEGORY_BRAND_SUPPLIER = 'category_brand_supplier';

    public const SCOPE_CATEGORY_BRAND = 'category_brand';

    public const SCOPE_CATEGORY_SUPPLIER = 'category_supplier';

    public const SCOPE_CATEGORY = 'category';

    public const SCOPE_BRAND = 'brand';

    public const SCOPE_SUPPLIER = 'supplier';

    public const SCOPE_GLOBAL_CAMPAIGN = 'global_campaign';

    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPE_FIXED_PRICE = 'fixed_price';

    public const TYPE_FIXED_AMOUNT = 'fixed_amount';

    protected $fillable = [
        'name',
        'scope_type',
        'product_id',
        'category_id',
        'brand_id',
        'supplier_id',
        'discount_type',
        'discount_value',
        'starts_at',
        'ends_at',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:4',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function (Builder $query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            });
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
