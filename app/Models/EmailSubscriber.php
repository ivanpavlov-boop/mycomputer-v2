<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailSubscriber extends Model
{
    public const STATUSES = ['subscribed', 'unsubscribed', 'bounced', 'suppressed'];

    public const SOURCES = ['checkout', 'account', 'newsletter', 'popup', 'import'];

    protected $fillable = [
        'user_id',
        'email',
        'first_name',
        'last_name',
        'source',
        'status',
        'gdpr_consent',
        'subscribed_at',
        'unsubscribed_at',
    ];

    protected function casts(): array
    {
        return [
            'gdpr_consent' => 'boolean',
            'subscribed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
