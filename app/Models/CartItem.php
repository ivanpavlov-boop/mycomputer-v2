<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    protected $fillable = [
        'cart_id',
        'product_id',
        'quantity',
        'is_gift',
        'promotion_id',
        'unit_price',
        'total_price',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'total_price' => 'decimal:2',
            'is_gift' => 'boolean',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('is_gift', false);
    }

    public function scopeGifts(Builder $query): Builder
    {
        return $query->where('is_gift', true);
    }

    public function isPaidLine(): bool
    {
        return ! $this->is_gift;
    }

    public function isGiftLine(): bool
    {
        return $this->is_gift;
    }
}
