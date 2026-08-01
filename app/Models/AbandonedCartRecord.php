<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbandonedCartRecord extends Model
{
    public const STATUSES = [
        'pending',
        'emailed_once',
        'emailed_twice',
        'emailed_three_times',
        'restored',
        'recovered',
        'expired',
        'suppressed',
    ];

    protected $fillable = [
        'user_id',
        'session_id',
        'email',
        'cart_snapshot',
        'cart_total',
        'items_count',
        'last_cart_activity_at',
        'recovery_capability_expires_at',
        'status',
        'last_email_sent_at',
        'first_email_sent_at',
        'second_email_sent_at',
        'third_email_sent_at',
        'emails_sent',
        'restored_at',
        'restored_cart_id',
        'recovered_at',
        'recovered_order_id',
        'recovered_revenue',
    ];

    protected $hidden = [
        'recovery_capability_hash',
    ];

    protected function casts(): array
    {
        return [
            'cart_snapshot' => 'array',
            'cart_total' => 'decimal:2',
            'last_cart_activity_at' => 'datetime',
            'recovery_capability_expires_at' => 'immutable_datetime',
            'last_email_sent_at' => 'datetime',
            'first_email_sent_at' => 'datetime',
            'second_email_sent_at' => 'datetime',
            'third_email_sent_at' => 'datetime',
            'restored_at' => 'datetime',
            'recovered_at' => 'datetime',
            'recovered_revenue' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recoveredOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'recovered_order_id');
    }

    public function restoredCart(): BelongsTo
    {
        return $this->belongsTo(Cart::class, 'restored_cart_id');
    }
}
