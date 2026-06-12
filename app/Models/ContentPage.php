<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentPage extends Model
{
    public const TYPES = ['homepage', 'landing_page', 'campaign_page', 'brand_page', 'category_page', 'seo_page', 'b2b_page', 'service_page', 'custom_page'];

    public const STATUSES = ['draft', 'scheduled', 'published', 'archived'];

    protected $fillable = [
        'title', 'slug', 'page_type', 'status', 'template_id',
        'meta_title', 'meta_description', 'canonical_url',
        'og_title', 'og_description', 'og_image', 'published_at',
    ];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ContentTemplate::class, 'template_id');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(ContentBlock::class)->orderBy('sort_order');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }
}
