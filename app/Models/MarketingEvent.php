<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingEvent extends Model
{
    public const SOURCES = ['ga4', 'meta', 'merchant', 'internal'];

    public const STATUSES = ['logged', 'sent', 'failed', 'skipped'];

    protected $fillable = [
        'user_id',
        'session_id',
        'event_name',
        'source',
        'payload',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
