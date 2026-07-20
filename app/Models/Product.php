<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedFields;
use App\Services\Products\ProductWorkflowService;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use HasLocalizedFields;
    use Searchable;
    use SoftDeletes;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_SUPPLIER_IMPORT = 'supplier_import';

    public const CATALOG_CURRENCY = 'EUR';

    public const PRICE_SOURCE_MANUAL = 'manual';

    public const PRICE_SOURCE_SUPPLIER_IMPORT = 'supplier_import_calculated';

    public const PRICE_SOURCE_ADMIN_OVERRIDE = 'admin_override';

    public const SALE_PRICE_SOURCE_MANUAL = 'manual';

    public const SALE_PRICE_SOURCE_PROMOTION_RULE = 'promotion_rule';

    public const SALE_PRICE_SOURCE_SUPPLIER_FEED = 'supplier_feed';

    public const WORKFLOW_DRAFT = 'draft';

    public const WORKFLOW_PENDING_REVIEW = 'pending_review';

    public const WORKFLOW_CHANGES_REQUESTED = 'changes_requested';

    public const WORKFLOW_APPROVED = 'approved';

    public const WORKFLOW_PUBLISHED = 'published';

    public const STOCK_STATUS_OUT_OF_STOCK = 'out_of_stock';

    public const STOCK_STATUS_IN_STOCK = 'in_stock';

    public const STOCK_STATUS_LIMITED_STOCK = 'limited_stock';

    protected $fillable = [
        'category_id',
        'brand_id',
        'supplier_id',
        'sku',
        'supplier_sku',
        'ean',
        'mpn',
        'name',
        'name_translations',
        'lock_name',
        'slug',
        'slug_translations',
        'short_description',
        'short_description_translations',
        'description',
        'description_translations',
        'lock_descriptions',
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
        'workflow_status',
        'created_by',
        'submitted_by',
        'approved_by',
        'published_by',
        'returned_by',
        'assigned_to',
        'submitted_at',
        'approved_at',
        'returned_at',
        'review_notes',
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
        'meta_title_translations',
        'meta_description',
        'meta_description_translations',
        'meta_keywords',
        'lock_seo',
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
            'name_translations' => 'array',
            'slug_translations' => 'array',
            'short_description_translations' => 'array',
            'description_translations' => 'array',
            'lock_name' => 'boolean',
            'lock_descriptions' => 'boolean',
            'active' => 'boolean',
            'featured' => 'boolean',
            'new_product' => 'boolean',
            'bestseller' => 'boolean',
            'meta_title_translations' => 'array',
            'meta_description_translations' => 'array',
            'lock_seo' => 'boolean',
            'expected_date' => 'date',
            'manual_override' => 'boolean',
            'specifications' => 'array',
            'source_payload' => 'array',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'returned_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Product $product): void {
            if (blank($product->stock_status)) {
                $product->stock_status = self::defaultStockStatusForQuantity($product->quantity);
            }
        });

        static::restoring(function (Product $product): void {
            app(ProductWorkflowService::class)->prepareForRestore($product);
        });
    }

    public function searchableAs(): string
    {
        return 'products';
    }

    public function shouldBeSearchable(): bool
    {
        return $this->isPubliclyVisible();
    }

    public function isPubliclyVisible(): bool
    {
        $hasActiveCategory = $this->relationLoaded('category')
            ? ($category = $this->getRelation('category')) !== null
                && ! $category->trashed()
                && (bool) $category->is_active
            : $this->category()->where('is_active', true)->exists();

        return ! $this->trashed()
            && (bool) $this->active
            && $this->published_at !== null
            && $this->workflow_status === self::WORKFLOW_PUBLISHED
            && $this->product_status === 'active'
            && filled($this->slug)
            && $hasActiveCategory;
    }

    public function storefrontUrl(): ?string
    {
        if (! $this->isPubliclyVisible()) {
            return null;
        }

        return rtrim((string) config('app.url'), '/').'/p/'.rawurlencode((string) $this->slug);
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

    public function thumbnailImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->ofMany([
            'is_primary' => 'max',
            'sort_order' => 'min',
            'id' => 'min',
        ]);
    }

    public function thumbnailUrl(): ?string
    {
        $path = $this->thumbnailImage?->path;

        if (blank($path)) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://', 'data:'])) {
            return $path;
        }

        return Storage::url($path);
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by')->withTrashed();
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by')->withTrashed();
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by')->withTrashed();
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function qualityFlagAssignments(): HasMany
    {
        return $this->hasMany(ProductQualityFlagAssignment::class);
    }

    public function activeQualityFlagAssignments(): HasMany
    {
        return $this->qualityFlagAssignments()->active();
    }

    public function qualityFlags(): BelongsToMany
    {
        return $this
            ->belongsToMany(ProductQualityFlag::class, 'product_quality_flag_assignments')
            ->withPivot(['status', 'note', 'assigned_by', 'resolved_by', 'resolved_at', 'metadata'])
            ->withTimestamps();
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
            ->whereNull($this->qualifyColumn('deleted_at'))
            ->where('active', true)
            ->whereNotNull('published_at')
            ->where('workflow_status', self::WORKFLOW_PUBLISHED)
            ->where('product_status', 'active')
            ->whereNotNull('slug')
            ->where('slug', '!=', '')
            ->whereHas('category', fn (Builder $category): Builder => $category->where('is_active', true));
    }

    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('stock_status', 'in_stock');
    }

    /**
     * @return array<string, string>
     */
    public static function workflowStatusOptions(): array
    {
        return [
            self::WORKFLOW_DRAFT => 'Чернова',
            self::WORKFLOW_PENDING_REVIEW => 'За преглед',
            self::WORKFLOW_CHANGES_REQUESTED => 'Върнат за корекции',
            self::WORKFLOW_APPROVED => 'Одобрен',
            self::WORKFLOW_PUBLISHED => 'Публикуван',
        ];
    }

    public static function workflowStatusLabel(?string $status): string
    {
        return self::workflowStatusOptions()[$status] ?? 'Неизвестен';
    }

    public static function workflowStatusColor(?string $status): string
    {
        return match ($status) {
            self::WORKFLOW_PUBLISHED => 'success',
            self::WORKFLOW_APPROVED => 'info',
            self::WORKFLOW_PENDING_REVIEW => 'warning',
            self::WORKFLOW_CHANGES_REQUESTED => 'danger',
            default => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function stockStatusOptions(): array
    {
        return [
            self::STOCK_STATUS_OUT_OF_STOCK => 'Няма наличност',
            self::STOCK_STATUS_IN_STOCK => 'В наличност',
            self::STOCK_STATUS_LIMITED_STOCK => 'Ограничена наличност',
        ];
    }

    public static function defaultStockStatusForQuantity(mixed $quantity): string
    {
        return (int) ($quantity ?? 0) > 0
            ? self::STOCK_STATUS_IN_STOCK
            : self::STOCK_STATUS_OUT_OF_STOCK;
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
