<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailAutomation extends Model
{
    public const TRIGGERS = [
        'account_registered',
        'abandoned_cart',
        'order_created',
        'order_paid',
        'order_shipped',
        'order_delivered',
        'order_cancelled',
        'review_request',
        'wishlist_reminder',
        'price_drop',
        'back_in_stock',
    ];

    protected $fillable = [
        'name',
        'trigger',
        'enabled',
        'configuration',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'configuration' => 'array',
        ];
    }
}
