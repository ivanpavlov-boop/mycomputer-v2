<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierImportDispatchMonitorHealth extends Model
{
    protected $table = 'supplier_import_dispatch_monitor_health';

    protected $keyType = 'int';

    public $incrementing = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'monitor_identity',
        'monitor_generation',
        'last_successful_monitor_generation',
        'monitor_owner_token_hash',
        'monitor_lease_acquired_at',
        'monitor_lease_expires_at',
        'cycle_sequence',
        'last_successful_cycle_at',
        'last_successful_sink_health_at',
        'last_successful_sink_contract_key',
        'observer_identity',
        'observer_sequence',
        'observed_monitor_generation',
        'observed_cycle_sequence',
        'last_successful_observer_at',
        'integrity_state',
        'last_failure_code',
    ];

    protected $hidden = [
        'monitor_owner_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'monitor_generation' => 'integer',
            'last_successful_monitor_generation' => 'integer',
            'monitor_lease_acquired_at' => 'immutable_datetime',
            'monitor_lease_expires_at' => 'immutable_datetime',
            'cycle_sequence' => 'integer',
            'last_successful_cycle_at' => 'immutable_datetime',
            'last_successful_sink_health_at' => 'immutable_datetime',
            'observer_sequence' => 'integer',
            'observed_monitor_generation' => 'integer',
            'observed_cycle_sequence' => 'integer',
            'last_successful_observer_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
