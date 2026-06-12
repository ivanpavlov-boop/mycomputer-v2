<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'company_name',
        'vat_number',
        'billing_address',
        'shipping_address',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
