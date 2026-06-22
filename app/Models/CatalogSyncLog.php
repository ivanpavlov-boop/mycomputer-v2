<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogSyncLog extends Model
{
    public const string ACTION_CREATE = 'CREATE';

    public const string ACTION_UPDATE = 'UPDATE';

    public const string STATUS_SUCCESS = 'success';

    public const string STATUS_SKIPPED = 'skipped';

    public const string STATUS_FAILED = 'failed';

    protected $fillable = [
        'catalog_sync_batch_id',
        'supplier_id',
        'supplier_product_id',
        'product_id',
        'action',
        'status',
        'reason',
        'old_values',
        'new_values',
        'error_message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CatalogSyncBatch::class, 'catalog_sync_batch_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function supplierProduct(): BelongsTo
    {
        return $this->belongsTo(SupplierProduct::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
