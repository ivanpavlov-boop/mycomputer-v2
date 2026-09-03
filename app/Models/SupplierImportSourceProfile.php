<?php

namespace App\Models;

use App\Models\Concerns\GuardsCanonicalSupplierMassAssignment;
use App\Models\Concerns\GuardsImmutableCanonicalSupplierRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierImportSourceProfile extends Model
{
    use GuardsCanonicalSupplierMassAssignment;
    use GuardsImmutableCanonicalSupplierRecord;

    public const UPDATED_AT = null;

    protected $table = 'supplier_import_source_profiles';

    protected $keyType = 'int';

    public $incrementing = true;

    protected $hidden = [
        'source_identity',
        'source_locator_canonical_bytes',
        'mapping_canonical_bytes',
        'mapping_contract_fingerprint',
        'source_descriptor_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'supplier_feed_id' => 'integer',
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
}
