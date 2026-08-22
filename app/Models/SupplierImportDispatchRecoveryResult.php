<?php

namespace App\Models;

use App\Models\Concerns\GuardsImmutableCanonicalSupplierRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierImportDispatchRecoveryResult extends Model
{
    use GuardsImmutableCanonicalSupplierRecord;

    protected $table = 'supplier_import_dispatch_recovery_results';

    protected $keyType = 'int';

    public $incrementing = true;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'supplier_import_dispatch_recovery_authorization_id',
        'authorization_action',
        'authorized_operator_id',
        'supplier_import_execution_claim_id',
        'supplier_import_dispatch_outbox_id',
        'logical_execution_key',
        'target_parent_type',
        'target_parent_id',
        'event_sequence',
        'event_kind',
        'canonical_result_code',
        'resume_state_fingerprint',
        'occurred_at',
        'result_fingerprint',
    ];

    protected $hidden = [
        'logical_execution_key',
        'resume_state_fingerprint',
        'result_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'event_sequence' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'started_once_guard' => 'integer',
            'terminal_once_guard' => 'integer',
        ];
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(
            SupplierImportDispatchRecoveryAuthorization::class,
            'supplier_import_dispatch_recovery_authorization_id',
        );
    }

    public function executionClaim(): BelongsTo
    {
        return $this->belongsTo(SupplierImportExecutionClaim::class, 'supplier_import_execution_claim_id');
    }

    public function dispatchOutbox(): BelongsTo
    {
        return $this->belongsTo(SupplierImportDispatchOutbox::class, 'supplier_import_dispatch_outbox_id');
    }

    public function authorizedOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_operator_id');
    }
}
