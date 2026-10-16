<?php

namespace App\Models;

use App\Models\Concerns\GuardsCanonicalSupplierMassAssignment;
use App\Models\Concerns\GuardsImmutableCanonicalSupplierRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class SupplierProductIdentityHead extends Model
{
    use GuardsCanonicalSupplierMassAssignment;
    use GuardsImmutableCanonicalSupplierRecord;

    public const UPDATED_AT = null;

    protected $table = 'supplier_product_identity_heads';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['supplier_sku_bytes'];

    protected function casts(): array
    {
        return ['supplier_id' => 'integer', 'supplier_feed_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    public function save(array $options = [])
    {
        throw new LogicException('Identity heads require the private transaction participant.');
    }
}
