<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierImportDispatchAlertIntent extends Model
{
    protected $table = 'supplier_import_dispatch_alert_intents';

    protected $keyType = 'int';

    public $incrementing = true;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'alert_identity',
        'schema_version',
        'alert_type',
        'dispatch_outbox_id',
        'delivery_watchdog_at',
        'severity',
        'critical_bucket',
        'payload',
        'delivery_state',
        'attempt_count',
        'delivery_generation',
        'delivery_owner_token_hash',
        'delivery_lease_acquired_at',
        'delivery_lease_expires_at',
        'next_attempt_at',
        'acknowledged_at',
        'last_failure_code',
    ];

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
