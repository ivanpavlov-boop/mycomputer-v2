<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class ProductBundle extends Model
{
    use Searchable;
    use SoftDeletes;

    public const STATUSES = ['draft', 'active', 'inactive', 'scheduled', 'expired'];

    public const TYPES = ['fixed_bundle', 'configurable_bundle', 'frequently_bought_together', 'starter_pack', 'accessory_pack'];

    public const PRICING_TYPES = ['sum_items', 'fixed_price', 'discount_percentage', 'discount_fixed'];

    protected $fillable = [
        'name',
        'slug',
        'description',
        'short_description',
        'image_path',
        'status',
        'type',
        'pricing_type',
        'fixed_price',
        'discount_type',
        'discount_value',
        'starts_at',
        'ends_at',
        'sort_order',
        'meta_title',
        'meta_description',
    ];

    protected function casts(): array
    {
        return [
            'fixed_price' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductBundleItem::class)->orderBy('sort_order');
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductBundleOption::class)->orderBy('sort_order');
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartBundleItem::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderBundleItem::class);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query
            ->where('status', 'active')
            ->where(fn (Builder $query): Builder => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $query): Builder => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    public function searchableAs(): string
    {
        return 'product_bundles';
    }

    public function shouldBeSearchable(): bool
    {
        return $this->status === 'active'
            && ($this->starts_at === null || $this->starts_at->lte(now()))
            && ($this->ends_at === null || $this->ends_at->gte(now()));
    }

    public function toSearchableArray(): array
    {
        $this->loadMissing([
            'items.product' => fn ($query) => $query->published()->with('brand'),
            'options.product' => fn ($query) => $query->published()->with('brand'),
        ]);

        $products = $this->items
            ->pluck('product')
            ->merge($this->options->pluck('product'))
            ->filter();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => strip_tags((string) $this->description),
            'short_description' => $this->short_description,
            'type' => $this->type,
            'pricing_type' => $this->pricing_type,
            'fixed_price' => $this->fixed_price !== null ? (float) $this->fixed_price : null,
            'discount_value' => $this->discount_value !== null ? (float) $this->discount_value : null,
            'status' => $this->status,
            'active' => $this->shouldBeSearchable(),
            'included_products' => $products->pluck('name')->unique()->values()->all(),
            'included_skus' => $products->pluck('sku')->filter()->unique()->values()->all(),
            'included_brands' => $products->pluck('brand.name')->filter()->unique()->values()->all(),
        ];
    }
}
