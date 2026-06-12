<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAddress extends Model
{
    public const TYPES = ['billing', 'shipping'];

    protected $fillable = [
        'user_id',
        'type',
        'first_name',
        'last_name',
        'company_name',
        'vat_number',
        'phone',
        'country',
        'city',
        'postcode',
        'address_line_1',
        'address_line_2',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
