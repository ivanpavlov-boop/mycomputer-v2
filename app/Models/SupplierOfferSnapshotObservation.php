<?php

namespace App\Models;

use App\Models\Concerns\GuardsImmutableCanonicalSupplierRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierOfferSnapshotObservation extends Model
{
    use GuardsImmutableCanonicalSupplierRecord;

    public const UPDATED_AT = null;

    protected $table = 'supplier_offer_snapshot_observations';

    protected $keyType = 'int';

    public $incrementing = true;

    protected $fillable = [
        'snapshot_generation_id',
        'snapshot_enrollment_id',
        'supplier_sku_hash',
        'present',
        'price',
        'currency',
        'raw_quantity_observed',
        'eol_flag',
        'canonical_public_status',
        'supplier_mapper_valid',
        'exact_supplier_sku_match',
        'identifier_conflict',
        'blocking_validation_issue',
        'duplicate_offer',
        'reliable_manufacturer_mpn_hash',
        'observation_fingerprint',
    ];

    protected $hidden = [
        'supplier_sku_hash',
        'reliable_manufacturer_mpn_hash',
        'observation_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'present' => 'boolean',
            'price' => 'decimal:2',
            'raw_quantity_observed' => 'integer',
            'eol_flag' => 'integer',
            'supplier_mapper_valid' => 'boolean',
            'exact_supplier_sku_match' => 'boolean',
            'identifier_conflict' => 'boolean',
            'blocking_validation_issue' => 'boolean',
            'duplicate_offer' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(SupplierOfferSnapshotGeneration::class, 'snapshot_generation_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(SupplierOfferSnapshotEnrollment::class, 'snapshot_enrollment_id');
    }
}
