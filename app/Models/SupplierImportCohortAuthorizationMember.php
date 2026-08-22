<?php

namespace App\Models;

use App\Models\Concerns\GuardsImmutableCanonicalSupplierRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierImportCohortAuthorizationMember extends Model
{
    use GuardsImmutableCanonicalSupplierRecord;

    public const UPDATED_AT = null;

    protected $table = 'supplier_import_cohort_authorization_members';

    protected $keyType = 'int';

    public $incrementing = true;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'supplier_import_execution_claim_id',
        'supplier_sku_hash',
    ];

    protected $hidden = [
        'supplier_sku_hash',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }

    public function executionClaim(): BelongsTo
    {
        return $this->belongsTo(SupplierImportExecutionClaim::class, 'supplier_import_execution_claim_id');
    }
}
