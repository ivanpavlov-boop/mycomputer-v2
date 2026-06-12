<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AvailabilityStatus extends Model
{
    public const COLOR_OPTIONS = ['green', 'orange', 'yellow', 'red', 'blue'];

    public const ICON_OPTIONS = ['check', 'warning', 'clock', 'truck', 'package'];

    public const BADGE_STYLES = ['solid', 'outline', 'soft'];

    protected $fillable = [
        'code',
        'name',
        'description',
        'color',
        'icon',
        'badge_style',
        'allow_purchase',
        'show_stock_quantity',
        'is_default',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'allow_purchase' => 'boolean',
            'show_stock_quantity' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(AvailabilityStatusMapping::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
