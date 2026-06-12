<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpCustomerMapping extends Model
{
    protected $fillable = [
        'provider_id',
        'user_id',
        'customer_id',
        'external_customer_id',
        'external_company_id',
        'sync_enabled',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'sync_enabled' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ErpProvider::class, 'provider_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
