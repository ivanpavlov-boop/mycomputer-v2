<?php

namespace App\Models;

use App\Models\Concerns\GuardsCanonicalSupplierMassAssignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierImportDispatchAlertIntent extends Model
{
    use GuardsCanonicalSupplierMassAssignment;

    protected $table = 'supplier_import_dispatch_alert_intents';

    protected $keyType = 'int';

    public $incrementing = true;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = [
        'alert_identity',
        'delivery_owner_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'dispatch_outbox_id' => 'integer',
            'delivery_watchdog_at' => 'immutable_datetime',
            'critical_bucket' => 'integer',
            'payload' => 'array',
            'attempt_count' => 'integer',
            'delivery_generation' => 'integer',
            'delivery_lease_acquired_at' => 'immutable_datetime',
            'delivery_lease_expires_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function dispatchOutbox(): BelongsTo
    {
        return $this->belongsTo(SupplierImportDispatchOutbox::class, 'dispatch_outbox_id');
    }
}
