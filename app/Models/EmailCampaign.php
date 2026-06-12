<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailCampaign extends Model
{
    public const STATUSES = ['draft', 'scheduled', 'sending', 'sent', 'cancelled'];

    protected $fillable = [
        'name',
        'subject',
        'template',
        'status',
        'scheduled_at',
        'sent_at',
        'recipients_count',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
