<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public const STATUSES = ['pending', 'confirmed', 'processing', 'shipped', 'completed', 'cancelled', 'refunded'];

    public const PAYMENT_STATUSES = ['pending', 'paid', 'failed', 'refunded'];

    public const SHIPPING_STATUSES = ['pending', 'preparing', 'shipped', 'delivered', 'returned'];

    protected $fillable = [
        'order_number',
        'customer_id',
        'user_id',
        'b2b_company_id',
        'quote_request_id',
        'customer_email',
        'customer_phone',
        'customer_name',
        'company_name',
        'vat_number',
        'billing_address',
        'shipping_address',
        'subtotal',
        'shipping_price',
        'discount_total',
        'grand_total',
        'payment_method',
        'payment_status',
        'shipping_method',
        'shipping_status',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'shipping_price' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function b2bCompany(): BelongsTo
    {
        return $this->belongsTo(B2BCompany::class);
    }

    public function quoteRequest(): BelongsTo
    {
        return $this->belongsTo(QuoteRequest::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function bundleItems(): HasMany
    {
        return $this->hasMany(OrderBundleItem::class);
    }

    public function shipment(): HasMany
    {
        return $this->hasMany(OrderShipment::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(OrderShipment::class);
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function productReviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }
}
