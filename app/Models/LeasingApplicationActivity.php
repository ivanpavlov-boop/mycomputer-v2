<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class LeasingApplicationActivity extends Model
{
    public const EVENT_SUBMITTED = 'submitted';

    public const EVENT_STATUS_CHANGED = 'status_changed';

    public const EVENT_ASSIGNED = 'assigned';

    public const EVENT_NOTE_ADDED = 'note_added';

    public const EVENT_TYPES = [
        self::EVENT_SUBMITTED,
        self::EVENT_STATUS_CHANGED,
        self::EVENT_ASSIGNED,
        self::EVENT_NOTE_ADDED,
    ];

    public $timestamps = false;

    protected $fillable = [
        'leasing_application_id',
        'event_type',
        'from_status',
        'to_status',
        'actor_user_id',
        'note',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Leasing application activities are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Leasing application activities are immutable.'));
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(LeasingApplication::class, 'leasing_application_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id')->withTrashed();
    }
}
