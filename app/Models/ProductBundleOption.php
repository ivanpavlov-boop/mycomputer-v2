<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBundleOption extends Model
{
    protected $fillable = ['product_bundle_id', 'component_group', 'product_id', 'price_adjustment', 'is_default', 'sort_order'];

    protected function casts(): array
    {
        return ['price_adjustment' => 'decimal:2', 'is_default' => 'boolean'];
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(ProductBundle::class, 'product_bundle_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
