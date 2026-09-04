<?php

namespace App\Models;

use App\Models\Concerns\GuardsCanonicalSupplierMassAssignment;
use App\Models\Concerns\GuardsImmutableCanonicalSupplierRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierImportSourceExecution extends Model
{
    use GuardsCanonicalSupplierMassAssignment;
    use GuardsImmutableCanonicalSupplierRecord;

    public const UPDATED_AT = null;

    protected $table = 'supplier_import_source_executions';

    protected $keyType = 'int';

    public $incrementing = true;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = [
        'source_identity',
        'source_descriptor_fingerprint',
        'import_job_identity_canonical_bytes',
        'import_job_identity_fingerprint',
        'source_execution_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'supplier_feed_id' => 'integer',
            'import_job_id' => 'integer',
            'import_history_id' => 'integer',
            'supplier_import_source_profile_id' => 'integer',
            'captured_at' => 'immutable_datetime',
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

    public function importJob(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class);
    }

    public function importHistory(): BelongsTo
    {
        return $this->belongsTo(ImportHistory::class);
    }

    public function sourceProfile(): BelongsTo
    {
        return $this->belongsTo(SupplierImportSourceProfile::class, 'supplier_import_source_profile_id');
    }
}
