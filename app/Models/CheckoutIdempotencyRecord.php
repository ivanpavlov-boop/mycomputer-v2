<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckoutIdempotencyRecord extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'cart_id',
        'order_id',
        'key_hash',
        'request_hash',
        'status',
        'completed_at',
    ];

    protected $hidden = [
        'key_hash',
        'request_hash',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return null;
    }
}
