<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpProductMapping extends Model
{
    protected $fillable = [
        'provider_id',
        'product_id',
        'external_product_id',
        'external_sku',
        'external_barcode',
        'sync_enabled',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'sync_enabled' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ErpProvider::class, 'provider_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
