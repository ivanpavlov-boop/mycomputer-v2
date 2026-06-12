<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Promotion extends Model
{
    public const TYPES = [
        'percentage_discount',
        'fixed_discount',
        'free_shipping',
        'gift_product',
        'bundle_discount',
        'category_discount',
        'brand_discount',
        'cart_discount',
        'buy_x_get_y',
    ];

    public const STATUSES = ['active', 'inactive', 'scheduled', 'expired'];

    protected $fillable = [
        'name',
        'code',
        'description',
        'type',
        'status',
        'priority',
        'starts_at',
        'ends_at',
        'usage_limit',
        'usage_count',
        'stackable',
        'stop_further_rules',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'stackable' => 'boolean',
            'stop_further_rules' => 'boolean',
        ];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(PromotionRule::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(PromotionAction::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromotionRedemption::class);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query
            ->where('status', 'active')
            ->where(fn (Builder $query): Builder => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $query): Builder => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->where(fn (Builder $query): Builder => $query->whereNull('usage_limit')->orWhereColumn('usage_count', '<', 'usage_limit'));
    }
}
