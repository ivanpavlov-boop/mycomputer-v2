<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSupplierOffer extends Model
{
    protected $fillable = [
        'product_id',
        'supplier_id',
        'supplier_product_id',
        'supplier_sku',
        'price',
        'quantity',
        'currency',
        'supplier_priority',
        'is_preferred',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_preferred' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function supplierProduct(): BelongsTo
    {
        return $this->belongsTo(SupplierProduct::class);
    }
}
