<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingOffice extends Model
{
    protected $fillable = [
        'shipping_provider_id',
        'office_id',
        'name',
        'city',
        'postcode',
        'address',
        'phone',
        'latitude',
        'longitude',
        'raw_data',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'raw_data' => 'array',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ShippingProvider::class, 'shipping_provider_id');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(OrderShipment::class, 'office_id');
    }
}
