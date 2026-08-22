<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierImportExecutionClaim extends Model
{
    protected $table = 'supplier_import_execution_claims';

    protected $keyType = 'int';

    public $incrementing = true;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'logical_execution_key',
        'supplier_id',
        'supplier_feed_id',
        'supplier_import_run_id',
        'import_job_id',
        'allocated_at',
        'import_history_id',
        'execution_path',
        'state',
        'active_attempt_token_hash',
        'source_fingerprint',
        'cohort_authorization_version',
        'cohort_authorized_at',
        'cohort_seed_count',
        'cohort_seed_fingerprint',
        'terminal_reason_code',
        'claimed_at',
        'attempt_lease_expires_at',
        'processing_started_at',
        'terminal_at',
    ];

    protected $hidden = [
        'logical_execution_key',
        'active_attempt_token_hash',
        'source_fingerprint',
        'cohort_seed_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'allocated_at' => 'immutable_datetime',
            'cohort_authorized_at' => 'immutable_datetime',
            'cohort_seed_count' => 'integer',
            'claimed_at' => 'immutable_datetime',
            'attempt_lease_expires_at' => 'immutable_datetime',
            'processing_started_at' => 'immutable_datetime',
            'terminal_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function feed(): BelongsTo
    {
        return $this->belongsTo(SupplierFeed::class, 'supplier_feed_id');
    }

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(SupplierImportRun::class, 'supplier_import_run_id');
    }

    public function importJob(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class);
    }

    public function importHistory(): BelongsTo
    {
        return $this->belongsTo(ImportHistory::class);
    }
}
