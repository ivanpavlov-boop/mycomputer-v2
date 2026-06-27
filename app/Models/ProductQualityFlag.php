<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductQualityFlag extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const TYPE_CONTENT = 'content';

    public const TYPE_SEO = 'seo';

    public const TYPE_MEDIA = 'media';

    public const TYPE_DATA = 'data';

    protected $fillable = [
        'code',
        'label_bg',
        'label_en',
        'description_bg',
        'description_en',
        'severity',
        'responsible_role',
        'type',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function severityOptions(): array
    {
        return [
            self::SEVERITY_LOW => 'Low',
            self::SEVERITY_MEDIUM => 'Medium',
            self::SEVERITY_HIGH => 'High',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            self::TYPE_CONTENT => 'Content',
            self::TYPE_SEO => 'SEO',
            self::TYPE_MEDIA => 'Media',
            self::TYPE_DATA => 'Data',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('label_bg');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ProductQualityFlagAssignment::class);
    }

    public function products(): BelongsToMany
    {
        return $this
            ->belongsToMany(Product::class, 'product_quality_flag_assignments')
            ->withPivot(['status', 'note', 'assigned_by', 'resolved_by', 'resolved_at', 'metadata'])
            ->withTimestamps();
    }
}
