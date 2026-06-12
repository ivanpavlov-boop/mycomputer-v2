<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpSyncJob extends Model
{
    public const STATUSES = ['pending', 'processing', 'success', 'failed', 'skipped'];

    public const SYNC_TYPES = ['push', 'pull'];

    public const ENTITY_TYPES = ['order', 'customer', 'invoice', 'payment', 'product', 'stock', 'service_ticket'];

    protected $fillable = [
        'provider_id',
        'sync_type',
        'entity_type',
        'entity_id',
        'status',
        'attempts',
        'last_error',
        'payload',
        'response',
        'external_id',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'response' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ErpProvider::class, 'provider_id');
    }
}
