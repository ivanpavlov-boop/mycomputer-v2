<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentPage extends Model
{
    use HasLocalizedFields;

    public const TYPES = ['homepage', 'landing_page', 'campaign_page', 'brand_page', 'category_page', 'seo_page', 'b2b_page', 'service_page', 'custom_page'];

    public const STATUSES = ['draft', 'scheduled', 'published', 'archived'];

    protected $fillable = [
        'title', 'title_translations', 'slug', 'slug_translations', 'page_type', 'status', 'template_id',
        'meta_title', 'meta_title_translations', 'meta_description', 'meta_description_translations', 'canonical_url',
        'og_title', 'og_description', 'og_image', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'title_translations' => 'array',
            'slug_translations' => 'array',
            'meta_title_translations' => 'array',
            'meta_description_translations' => 'array',
            'published_at' => 'datetime',
        ];
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
