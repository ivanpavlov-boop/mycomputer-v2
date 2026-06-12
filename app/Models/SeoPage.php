<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SeoPage extends Model
{
    use SoftDeletes;

    public const TYPES = ['landing_page', 'buying_guide', 'category_guide', 'brand_guide', 'comparison_guide', 'service_page'];

    public const STATUSES = ['draft', 'scheduled', 'published', 'archived'];

    protected $fillable = [
        'title',
        'slug',
        'type',
        'content',
        'status',
        'related_category_id',
        'related_brand_id',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'canonical_url',
        'og_title',
        'og_description',
        'og_image',
        'schema_type',
        'schema_data',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'schema_data' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function relatedCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'related_category_id');
    }

    public function relatedBrand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'related_brand_id');
    }

    public function relatedProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'seo_page_product');
    }

    public function relatedCategories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'seo_page_category');
    }

    public function relatedBrands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class, 'seo_page_brand');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }
}
