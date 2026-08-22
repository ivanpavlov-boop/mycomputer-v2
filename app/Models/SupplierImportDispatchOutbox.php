<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierImportDispatchOutbox extends Model
{
    protected $table = 'supplier_import_dispatch_outbox';

    protected $keyType = 'int';

    public $incrementing = true;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'supplier_import_execution_claim_id',
        'logical_execution_key',
        'event_type',
        'job_type',
        'dispatch_payload',
        'dispatch_payload_hash',
        'transport_deadline_at',
        'state',
        'attempt_count',
        'publication_attempt_generation',
        'publication_attempt_state',
        'publication_attempt_token_hash',
        'publication_attempt_reserved_at',
        'publication_attempt_lease_expires_at',
        'publication_external_fence_installed_at',
        'publication_call_boundary_at',
        'publication_attempt_resolved_at',
        'delivery_attempt_count',
        'lease_owner_key',
        'lease_token_hash',
        'leased_at',
        'lease_expires_at',
        'next_attempt_at',
        'published_at',
        'last_published_at',
        'delivery_watchdog_at',
        'recovery_required_at',
        'recovery_reason_code',
        'terminal_at',
        'terminal_failure_reason_code',
    ];

    protected $hidden = [
        'logical_execution_key',
        'dispatch_payload',
        'dispatch_payload_hash',
        'publication_attempt_token_hash',
        'lease_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'dispatch_payload' => 'array',
            'transport_deadline_at' => 'immutable_datetime',
            'attempt_count' => 'integer',
            'publication_attempt_generation' => 'integer',
            'publication_attempt_reserved_at' => 'immutable_datetime',
            'publication_attempt_lease_expires_at' => 'immutable_datetime',
            'publication_external_fence_installed_at' => 'immutable_datetime',
            'publication_call_boundary_at' => 'immutable_datetime',
            'publication_attempt_resolved_at' => 'immutable_datetime',
            'delivery_attempt_count' => 'integer',
            'leased_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'last_published_at' => 'immutable_datetime',
            'delivery_watchdog_at' => 'immutable_datetime',
            'recovery_required_at' => 'immutable_datetime',
            'terminal_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function executionClaim(): BelongsTo
    {
        return $this->belongsTo(SupplierImportExecutionClaim::class, 'supplier_import_execution_claim_id');
    }
}
