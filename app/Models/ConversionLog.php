<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversionLog extends Model
{
    public const PROVIDERS = ['ga4', 'meta', 'merchant', 'internal'];

    public const STATUSES = ['pending', 'sent', 'failed', 'skipped'];

    protected $fillable = [
        'order_id',
        'provider',
        'event_name',
        'payload',
        'response',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'response' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
