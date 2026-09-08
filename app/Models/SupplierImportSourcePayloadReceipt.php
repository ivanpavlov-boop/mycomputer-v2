<?php

namespace App\Models;

use App\Models\Concerns\GuardsCanonicalSupplierMassAssignment;
use App\Models\Concerns\GuardsImmutableCanonicalSupplierRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SupplierImportSourcePayloadReceipt extends Model
{
    use GuardsCanonicalSupplierMassAssignment;
    use GuardsImmutableCanonicalSupplierRecord;

    public const UPDATED_AT = null;

    protected $table = 'supplier_import_source_payload_receipts';

    protected $keyType = 'int';

    public $incrementing = true;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = [
        'source_execution_fingerprint',
        'accepted_payload_sha256',
        'payload_receipt_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'supplier_import_source_execution_id' => 'integer',
            'accepted_payload_bytes' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function save(array $options = [])
    {
        throw new LogicException('Payload receipts require the transaction repository.');
    }

    public function sourceExecution(): BelongsTo
    {
        return $this->belongsTo(SupplierImportSourceExecution::class, 'supplier_import_source_execution_id');
    }
}
