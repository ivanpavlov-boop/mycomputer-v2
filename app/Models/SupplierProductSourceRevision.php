<?php

namespace App\Models;

use App\Models\Concerns\GuardsCanonicalSupplierMassAssignment;
use App\Models\Concerns\GuardsImmutableCanonicalSupplierRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class SupplierProductSourceRevision extends Model
{
    use GuardsCanonicalSupplierMassAssignment;
    use GuardsImmutableCanonicalSupplierRecord;

    public const UPDATED_AT = null;

    protected $table = 'supplier_product_source_revisions';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['supplier_sku_bytes'];

    protected function casts(): array
    {
        return [
            'supplier_product_identity_head_id' => 'integer',
            'supplier_product_id' => 'integer',
            'supplier_import_source_execution_id' => 'integer',
            'supplier_id' => 'integer',
            'supplier_feed_id' => 'integer',
            'observed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function save(array $options = [])
    {
        throw new LogicException('Source revisions are read/hydrate-only; a writer is not implemented.');
    }
}
