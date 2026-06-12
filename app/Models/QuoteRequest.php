<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuoteRequest extends Model
{
    public const STATUSES = ['draft', 'submitted', 'under_review', 'offered', 'accepted', 'rejected', 'expired', 'converted'];

    public const SOURCES = ['product_page', 'cart', 'b2b_portal', 'admin'];

    protected $fillable = [
        'user_id',
        'b2b_company_id',
        'quote_number',
        'customer_name',
        'customer_email',
        'customer_phone',
        'company_name',
        'vat_number',
        'status',
        'source',
        'subtotal',
        'discount_total',
        'grand_total',
        'valid_until',
        'notes',
        'internal_notes',
        'submitted_at',
        'approved_at',
        'rejected_at',
        'converted_order_id',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'valid_until' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(B2BCompany::class, 'b2b_company_id');
    }

    public function convertedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'converted_order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteRequestItem::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(QuoteRequestMessage::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(QuoteRequestFile::class);
    }

    public function isOwnedBy(User $user): bool
    {
        if ((int) $this->user_id === (int) $user->id) {
            return true;
        }

        return $this->b2b_company_id !== null
            && B2BCompanyUser::query()
                ->where('b2b_company_id', $this->b2b_company_id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->exists();
    }
}
