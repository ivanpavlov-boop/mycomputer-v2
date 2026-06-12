<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartBundleItem extends Model
{
    protected $fillable = ['cart_id', 'product_bundle_id', 'selected_items', 'quantity', 'unit_price', 'total_price'];

    protected function casts(): array
    {
        return ['selected_items' => 'array', 'unit_price' => 'decimal:2', 'total_price' => 'decimal:2'];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(ProductBundle::class, 'product_bundle_id');
    }
}
