<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceTicket extends Model
{
    public const TYPES = ['warranty_claim', 'service_request', 'return_request', 'doa_request', 'replacement_request'];

    public const STATUSES = [
        'new', 'awaiting_review', 'awaiting_customer', 'approved', 'rejected', 'awaiting_service',
        'in_diagnosis', 'awaiting_parts', 'repaired', 'replaced', 'refunded', 'completed', 'closed',
    ];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected $fillable = [
        'ticket_number', 'user_id', 'order_id', 'product_id', 'b2b_company_id', 'assigned_to',
        'ticket_type', 'status', 'priority', 'subject', 'description', 'serial_number',
        'purchased_at', 'warranty_expires_at', 'diagnosis', 'resolution', 'work_performed',
        'parts_used', 'repair_date', 'refund_amount', 'refund_date', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'purchased_at' => 'date',
            'warranty_expires_at' => 'date',
            'parts_used' => 'array',
            'repair_date' => 'date',
            'refund_amount' => 'decimal:2',
            'refund_date' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(B2BCompany::class, 'b2b_company_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ServiceTicketFile::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ServiceTicketMessage::class);
    }

    public function publicMessages(): HasMany
    {
        return $this->messages()->where('internal_note', false);
    }

    public function isOwnedBy(User $user): bool
    {
        if ((int) $this->user_id === (int) $user->id) {
            return true;
        }

        return $this->b2b_company_id !== null
            && B2BCompanyUser::query()
                ->where('b2b_company_id', $this->b2b_company_id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->exists();
    }
}
