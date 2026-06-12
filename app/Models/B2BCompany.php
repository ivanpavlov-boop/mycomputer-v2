<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class B2BCompany extends Model
{
    public const STATUSES = ['active', 'inactive', 'suspended'];

    public const APPROVAL_STATUSES = ['pending', 'approved', 'rejected'];

    protected $table = 'b2b_companies';

    protected $fillable = [
        'name',
        'vat_number',
        'company_number',
        'mol',
        'email',
        'phone',
        'website',
        'billing_address',
        'shipping_address',
        'status',
        'approval_status',
        'credit_limit',
        'payment_terms',
        'notes',
        'approved_at',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(B2BCompanyUser::class, 'b2b_company_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(QuoteRequest::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
