<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierProduct extends Model
{
    protected $fillable = [
        'supplier_id',
        'supplier_feed_id',
        'product_id',
        'supplier_sku',
        'ean',
        'mpn',
        'name',
        'brand_name',
        'category_name',
        'price',
        'quantity',
        'external_availability_status',
        'external_availability_label',
        'availability_status_id',
        'currency',
        'raw_data',
        'payload_hash',
        'received_at',
        'synced_at',
        'status',
        'mapping_notes',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'raw_data' => 'array',
            'received_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function feed(): BelongsTo
    {
        return $this->belongsTo(SupplierFeed::class, 'supplier_feed_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function availabilityStatus(): BelongsTo
    {
        return $this->belongsTo(AvailabilityStatus::class);
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(SupplierProductAttribute::class);
    }
}
