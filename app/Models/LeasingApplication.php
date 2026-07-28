<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeasingApplication extends Model
{
    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_CONTACT_PENDING = 'contact_pending';

    public const STATUS_CONTACTED = 'contacted';

    public const STATUS_SENT_TO_PARTNER = 'sent_to_partner';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CUSTOMER_CANCELLED = 'customer_cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_CONTACT_PENDING,
        self::STATUS_CONTACTED,
        self::STATUS_SENT_TO_PARTNER,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CUSTOMER_CANCELLED,
        self::STATUS_EXPIRED,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CUSTOMER_CANCELLED,
        self::STATUS_EXPIRED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_SUBMITTED => 'Подадена',
        self::STATUS_CONTACT_PENDING => 'Очаква контакт',
        self::STATUS_CONTACTED => 'Осъществен контакт',
        self::STATUS_SENT_TO_PARTNER => 'Изпратена към партньор',
        self::STATUS_APPROVED => 'Одобрена',
        self::STATUS_REJECTED => 'Отказана',
        self::STATUS_CUSTOMER_CANCELLED => 'Отказана от клиента',
        self::STATUS_EXPIRED => 'Изтекла',
    ];

    protected $fillable = [
        'reference',
        'order_id',
        'status',
        'requested_term_months',
        'requested_down_payment',
        'currency',
        'preferred_contact_method',
        'preferred_contact_time',
        'customer_note',
        'contact_consent_at',
        'consent_version',
        'assigned_to_user_id',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_down_payment' => 'decimal:2',
            'contact_consent_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id')->withTrashed();
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeasingApplicationActivity::class)->oldest('id');
    }

    public static function statusLabel(?string $status): string
    {
        return self::STATUS_LABELS[$status] ?? 'Неизвестно';
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            self::STATUS_APPROVED => 'success',
            self::STATUS_REJECTED,
            self::STATUS_CUSTOMER_CANCELLED,
            self::STATUS_EXPIRED => 'danger',
            self::STATUS_CONTACT_PENDING,
            self::STATUS_CONTACTED,
            self::STATUS_SENT_TO_PARTNER => 'warning',
            self::STATUS_SUBMITTED => 'info',
            default => 'gray',
        };
    }
}
