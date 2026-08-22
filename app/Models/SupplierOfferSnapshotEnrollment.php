<?php

namespace App\Models;

use App\Models\Concerns\GuardsImmutableCanonicalSupplierRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierOfferSnapshotEnrollment extends Model
{
    use GuardsImmutableCanonicalSupplierRecord;

    public const UPDATED_AT = null;

    protected $table = 'supplier_offer_snapshot_enrollments';

    protected $keyType = 'int';

    public $incrementing = true;

    protected $fillable = [
        'supplier_id',
        'supplier_feed_id',
        'source_identity',
        'supplier_sku_hash',
        'effective_import_history_id',
        'enrollment_source',
        'enrollment_fingerprint',
        'enrolled_at',
    ];

    protected $hidden = [
        'source_identity',
        'supplier_sku_hash',
        'enrollment_fingerprint',
    ];

    protected function casts(): array
    {
        return [
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

    public function effectiveImportHistory(): BelongsTo
    {
        return $this->belongsTo(ImportHistory::class, 'effective_import_history_id');
    }
}
