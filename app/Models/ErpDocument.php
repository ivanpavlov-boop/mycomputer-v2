<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpDocument extends Model
{
    public const TYPES = ['invoice', 'credit_note', 'receipt', 'stock_document'];

    public const STATUSES = ['pending', 'created', 'cancelled', 'failed'];

    protected $fillable = [
        'provider_id',
        'order_id',
        'document_type',
        'external_id',
        'document_number',
        'document_date',
        'status',
        'payload',
        'file_path',
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'payload' => 'array',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ErpProvider::class, 'provider_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
