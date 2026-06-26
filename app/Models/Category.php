<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedFields;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    use HasLocalizedFields;
    use SoftDeletes;

    protected $fillable = [
        'parent_id',
        'name',
        'name_translations',
        'slug',
        'slug_translations',
        'description',
        'description_translations',
        'image_path',
        'icon',
        'is_active',
        'sort_order',
        'meta_title',
        'meta_title_translations',
        'meta_description',
        'meta_description_translations',
        'meta_keywords',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'name_translations' => 'array',
            'slug_translations' => 'array',
            'description_translations' => 'array',
            'meta_title_translations' => 'array',
            'meta_description_translations' => 'array',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('sort_order');
    }

    public function childrenRecursive(): HasMany
    {
        return $this->children()->with('childrenRecursive');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function pricingRules(): HasMany
    {
        return $this->hasMany(PricingRule::class);
    }
}
