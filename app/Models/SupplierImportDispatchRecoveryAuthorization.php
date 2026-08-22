<?php

namespace App\Models;

use App\Models\Concerns\GuardsImmutableCanonicalSupplierRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierImportDispatchRecoveryAuthorization extends Model
{
    use GuardsImmutableCanonicalSupplierRecord;

    protected $table = 'supplier_import_dispatch_recovery_authorizations';

    protected $keyType = 'int';

    public $incrementing = true;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'supplier_import_execution_claim_id',
        'supplier_import_dispatch_outbox_id',
        'logical_execution_key',
        'target_parent_type',
        'target_parent_id',
        'authorization_action',
        'expected_state_fingerprint',
        'canonical_reason_code',
        'authorized_operator_id',
        'authorized_at',
        'expires_at',
        'authorization_nonce_hash',
    ];

    protected $hidden = [
        'logical_execution_key',
        'expected_state_fingerprint',
        'authorization_nonce_hash',
    ];

    protected function casts(): array
    {
        return [
            'authorized_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
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
