<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingMethod extends Model
{
    protected $fillable = [
        'shipping_provider_id',
        'name',
        'code',
        'type',
        'status',
        'price',
        'free_shipping_threshold',
        'settings',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'free_shipping_threshold' => 'decimal:2',
            'settings' => 'array',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ShippingProvider::class, 'shipping_provider_id');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(OrderShipment::class);
    }
}
