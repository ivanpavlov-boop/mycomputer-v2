<?php

namespace App\Models;

use App\Models\Concerns\GuardsImmutableCanonicalSupplierRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierOfferSnapshotGeneration extends Model
{
    use GuardsImmutableCanonicalSupplierRecord;

    public const UPDATED_AT = null;

    protected $table = 'supplier_offer_snapshot_generations';

    protected $keyType = 'int';

    public $incrementing = true;

    protected $fillable = [
        'supplier_id',
        'supplier_key',
        'supplier_feed_id',
        'supplier_import_execution_claim_id',
        'import_history_id',
        'predecessor_snapshot_generation_id',
        'schema_version',
        'producer_version',
        'qualification_policy_key',
        'capture_integrity_policy_key',
        'policy_versions',
        'freshness_policy_key',
        'freshness_max_age_hours',
        'freshness_policy_approved',
        'source_identity',
        'source_fingerprint',
        'captured_at',
        'authoritative_snapshot_at',
        'capture_started_at',
        'capture_completed_at',
        'capture_outcome',
        'capture_failure_reason_code',
        'qualification_state',
        'qualification_reason_codes',
        'successful',
        'full',
        'schema_valid',
        'truncated',
        'fatal_integrity_blocker',
        'supplier_identity_confirmed',
        'comparable',
        'total_observed_count',
        'valid_observation_count',
        'invalid_observation_count',
        'rejected_observation_count',
        'duplicate_observation_count',
        'enrolled_observation_count',
        'minimum_product_count',
        'product_drop_percent',
        'maximum_product_drop_percent',
        'cohort_fingerprint',
        'observation_set_fingerprint',
        'generation_fingerprint',
    ];

    protected $hidden = [
        'source_identity',
        'source_fingerprint',
        'cohort_fingerprint',
        'observation_set_fingerprint',
        'generation_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'policy_versions' => 'array',
            'freshness_max_age_hours' => 'integer',
            'freshness_policy_approved' => 'boolean',
            'qualification_reason_codes' => 'array',
            'successful' => 'boolean',
            'full' => 'boolean',
            'schema_valid' => 'boolean',
            'truncated' => 'boolean',
            'fatal_integrity_blocker' => 'boolean',
            'supplier_identity_confirmed' => 'boolean',
            'comparable' => 'boolean',
            'total_observed_count' => 'integer',
            'valid_observation_count' => 'integer',
            'invalid_observation_count' => 'integer',
            'rejected_observation_count' => 'integer',
            'duplicate_observation_count' => 'integer',
            'enrolled_observation_count' => 'integer',
            'minimum_product_count' => 'integer',
            'product_drop_percent' => 'decimal:6',
            'maximum_product_drop_percent' => 'integer',
            'created_at' => 'immutable_datetime',
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

    public function executionClaim(): BelongsTo
    {
        return $this->belongsTo(SupplierImportExecutionClaim::class, 'supplier_import_execution_claim_id');
    }

    public function importHistory(): BelongsTo
    {
        return $this->belongsTo(ImportHistory::class);
    }

    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'predecessor_snapshot_generation_id');
    }
}
