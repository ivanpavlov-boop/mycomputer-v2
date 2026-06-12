<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderShipment extends Model
{
    protected $fillable = [
        'order_id',
        'shipping_provider_id',
        'shipping_method_id',
        'tracking_number',
        'label_path',
        'office_id',
        'delivery_type',
        'recipient_name',
        'recipient_phone',
        'city',
        'postcode',
        'address',
        'price',
        'status',
        'raw_request',
        'raw_response',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'raw_request' => 'array',
            'raw_response' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ShippingProvider::class, 'shipping_provider_id');
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class, 'shipping_method_id');
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(ShippingOffice::class, 'office_id');
    }
}
