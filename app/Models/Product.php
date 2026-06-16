<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use Searchable;
    use SoftDeletes;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_SUPPLIER_IMPORT = 'supplier_import';

    public const PRICE_SOURCE_MANUAL = 'manual';

    public const PRICE_SOURCE_SUPPLIER_IMPORT = 'supplier_import_calculated';

    public const PRICE_SOURCE_ADMIN_OVERRIDE = 'admin_override';

    public const SALE_PRICE_SOURCE_MANUAL = 'manual';

    public const SALE_PRICE_SOURCE_PROMOTION_RULE = 'promotion_rule';

    public const SALE_PRICE_SOURCE_SUPPLIER_FEED = 'supplier_feed';

    protected $fillable = [
        'category_id',
        'brand_id',
        'supplier_id',
        'sku',
        'supplier_sku',
        'ean',
        'mpn',
        'name',
        'slug',
        'short_description',
        'description',
        'weight',
        'purchase_price',
        'supplier_price_raw',
        'recommended_price',
        'final_selling_price',
        'regular_price',
        'source',
        'apply_pricing_rules',
        'price_source',
        'price',
        'promo_price',
        'promo_start',
        'promo_end',
        'sale_price',
        'sale_price_starts_at',
        'sale_price_ends_at',
        'sale_price_source',
        'quantity',
        'reserved_quantity',
        'stock_status',
        'availability_status_id',
        'product_status',
        'availability_message',
        'expected_date',
        'supplier_lead_time_days',
        'manual_override',
        'external_availability_status',
        'external_availability_label',
        'warranty_months',
        'active',
        'featured',
        'new_product',
        'bestseller',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'searchable_keywords',
        'specifications',
        'source_payload',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:3',
            'purchase_price' => 'decimal:2',
            'supplier_price_raw' => 'decimal:2',
            'recommended_price' => 'decimal:2',
            'final_selling_price' => 'decimal:2',
            'regular_price' => 'decimal:2',
            'apply_pricing_rules' => 'boolean',
            'price' => 'decimal:2',
            'promo_price' => 'decimal:2',
            'promo_start' => 'datetime',
            'promo_end' => 'datetime',
            'sale_price' => 'decimal:2',
            'sale_price_starts_at' => 'datetime',
            'sale_price_ends_at' => 'datetime',
            'active' => 'boolean',
            'featured' => 'boolean',
            'new_product' => 'boolean',
            'bestseller' => 'boolean',
            'expected_date' => 'date',
            'manual_override' => 'boolean',
            'specifications' => 'array',
            'source_payload' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function searchableAs(): string
    {
        return 'products';
    }

    public function shouldBeSearchable(): bool
    {
        return $this->active && $this->published_at !== null;
    }

    public function shouldApplyPricingEngine(): bool
    {
        return $this->source === self::SOURCE_SUPPLIER_IMPORT || $this->apply_pricing_rules;
    }

    public function activeSalePrice(): ?float
    {
        $salePrice = $this->sale_price ?? $this->promo_price;

        if ($salePrice === null) {
            return null;
        }

        $regularPrice = $this->regular_price ?? $this->price;

        if ($regularPrice !== null && (float) $salePrice >= (float) $regularPrice) {
            return null;
        }

        $startsAt = $this->sale_price_starts_at ?? $this->promo_start;
        $endsAt = $this->sale_price_ends_at ?? $this->promo_end;

        if ($startsAt !== null && $startsAt->isFuture()) {
            return null;
        }

        if ($endsAt !== null && $endsAt->isPast()) {
            return null;
        }

        return round((float) $salePrice, 2);
    }

    public function effectivePrice(): float
    {
        return $this->activeSalePrice() ?? (float) ($this->regular_price ?? $this->price ?? 0);
    }

    public function toSearchableArray(): array
    {
        $this->loadMissing([
            'brand',
            'category.parent',
            'availabilityStatus',
            'attributeValues.attribute.group',
            'attributeValues.value',
            'attributeValues.canonicalAttribute',
            'attributeValues.canonicalAttributeValue',
        ]);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'ean' => $this->ean,
            'mpn' => $this->mpn,
            'brand' => $this->brand?->name,
            'brand_slug' => $this->brand?->slug,
            'category' => $this->category?->name,
            'category_slug' => $this->category?->slug,
            'category_path' => $this->searchableCategoryPath(),
            'short_description' => $this->short_description,
            'description' => strip_tags((string) $this->description),
            'price' => (float) $this->price,
            'regular_price' => $this->regular_price !== null ? (float) $this->regular_price : (float) $this->price,
            'sale_price' => $this->sale_price !== null ? (float) $this->sale_price : ($this->promo_price !== null ? (float) $this->promo_price : null),
            'active_sale_price' => $this->activeSalePrice(),
            'promo_price' => $this->activeSalePrice(),
            'stock_status' => $this->stock_status,
            'availability_status' => $this->availabilityStatus?->code ?? $this->stock_status,
            'availability_status_code' => $this->availabilityStatus?->code ?? $this->stock_status,
            'availability_status_name' => $this->availabilityStatus?->name ?? $this->stock_status,
            'availability_sort_order' => (int) ($this->availabilityStatus?->sort_order ?? 999),
            'allow_purchase' => (bool) ($this->availabilityStatus?->allow_purchase ?? $this->stock_status !== 'out_of_stock'),
            'active' => (bool) $this->active,
            'featured' => (bool) $this->featured,
            'new_product' => (bool) $this->new_product,
            'bestseller' => (bool) $this->bestseller,
            'published_at' => $this->published_at?->timestamp,
            'attributes' => $this->searchableAttributes(),
            'attribute_numeric' => $this->searchableNumericAttributes(),
            'searchable_keywords' => $this->searchable_keywords,
        ];
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

    public function availabilityStatus(): BelongsTo
    {
        return $this->belongsTo(AvailabilityStatus::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    public function relatedProducts(): BelongsToMany
    {
        return $this
            ->belongsToMany(Product::class, 'product_related_products', 'product_id', 'related_product_id')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    public function accessoryProducts(): BelongsToMany
    {
        return $this
            ->belongsToMany(Product::class, 'product_accessory_products', 'product_id', 'accessory_product_id')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    public function supplierProducts(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }

    public function supplierOffers(): HasMany
    {
        return $this->hasMany(ProductSupplierOffer::class);
    }

    public function pricingRules(): HasMany
    {
        return $this->hasMany(PricingRule::class);
    }

    public function discountRules(): HasMany
    {
        return $this->hasMany(ProductDiscountRule::class);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }

    public function compareItems(): HasMany
    {
        return $this->hasMany(ProductCompareItem::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function pcBuildItems(): HasMany
    {
        return $this->hasMany(PcBuildItem::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function quoteRequestItems(): HasMany
    {
        return $this->hasMany(QuoteRequestItem::class);
    }

    public function bundleItems(): HasMany
    {
        return $this->hasMany(ProductBundleItem::class);
    }

    public function bundleOptions(): HasMany
    {
        return $this->hasMany(ProductBundleOption::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('active', true)
            ->whereNotNull('published_at');
    }

    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('stock_status', 'in_stock');
    }

    private function searchableCategoryPath(): array
    {
        $path = [];
        $category = $this->category;

        while ($category !== null) {
            array_unshift($path, $category->name);
            $category = $category->parent;
        }

        return $path;
    }

    private function searchableAttributes(): array
    {
        return $this->attributeValues
            ->map(function (ProductAttributeValue $assignment): array {
                return [
                    'group' => $assignment->canonicalAttribute?->group_name ?? $assignment->attribute?->group?->name,
                    'name' => $assignment->canonicalAttribute?->name ?? $assignment->attribute?->name,
                    'slug' => $assignment->canonicalAttribute?->code ?? $assignment->attribute?->slug,
                    'value' => $assignment->canonicalAttributeValue?->display_value ?? $assignment->value?->value ?? $assignment->custom_value,
                    'value_slug' => $assignment->canonicalAttributeValue?->normalized_value ?? $assignment->value?->slug,
                    'filterable' => (bool) ($assignment->canonicalAttribute?->is_filterable ?? $assignment->is_filterable),
                ];
            })
            ->values()
            ->all();
    }

    private function searchableNumericAttributes(): array
    {
        return $this->attributeValues
            ->filter(fn (ProductAttributeValue $assignment): bool => $assignment->canonicalAttribute !== null && $assignment->canonicalAttributeValue?->numeric_value !== null)
            ->mapWithKeys(function (ProductAttributeValue $assignment): array {
                $unit = $assignment->canonicalAttributeValue?->unit ?: $assignment->canonicalAttribute?->unit;
                $key = $assignment->canonicalAttribute->code.($unit ? '_'.$unit : '');

                return [$key => (float) $assignment->canonicalAttributeValue->numeric_value];
            })
            ->all();
    }
}
