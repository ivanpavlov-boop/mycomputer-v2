<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    public const STATUSES = ['pending', 'sent', 'skipped', 'failed'];

    protected $fillable = [
        'email',
        'provider',
        'type',
        'subject',
        'status',
        'payload',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sent_at' => 'datetime',
        ];
    }
}
