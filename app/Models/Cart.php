<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    protected $fillable = [
        'session_id',
        'user_id',
        'customer_email',
        'coupon_code',
        'status',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function bundleItems(): HasMany
    {
        return $this->hasMany(CartBundleItem::class);
    }

    public function abandonedCartRecords(): HasMany
    {
        return $this->hasMany(AbandonedCartRecord::class, 'session_id', 'session_id');
    }

    public function promotionRedemptions(): HasMany
    {
        return $this->hasMany(PromotionRedemption::class, 'session_id', 'session_id');
    }
}
