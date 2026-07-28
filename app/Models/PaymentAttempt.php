<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAttempt extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_INDETERMINATE = 'indeterminate';

    public const STATUSES = [
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_INDETERMINATE,
    ];

    public const AUTH_ACCOUNT_OWNER = 'account_owner';

    public const AUTH_GUEST_CAPABILITY = 'guest_capability';

    public const AUTHORIZATION_TYPES = [
        self::AUTH_ACCOUNT_OWNER,
        self::AUTH_GUEST_CAPABILITY,
    ];

    protected $fillable = [
        'reference',
        'order_id',
        'payment_method_id',
        'payment_provider_id',
        'payment_transaction_id',
        'idempotency_key_hash',
        'request_hash',
        'attempt_number',
        'status',
        'authorization_type',
        'initiated_by_user_id',
        'provider_reference',
        'completed_at',
        'failed_at',
        'failure_code',
    ];

    protected $hidden = [
        'idempotency_key_hash',
        'request_hash',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class, 'payment_provider_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id')->withTrashed();
    }
}
