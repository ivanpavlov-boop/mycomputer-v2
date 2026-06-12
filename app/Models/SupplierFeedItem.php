<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierFeedItem extends Model
{
    protected $fillable = [
        'supplier_feed_id',
        'product_id',
        'supplier_sku',
        'raw_payload',
        'status',
        'error_message',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'imported_at' => 'datetime',
        ];
    }

    public function feed(): BelongsTo
    {
        return $this->belongsTo(SupplierFeed::class, 'supplier_feed_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
