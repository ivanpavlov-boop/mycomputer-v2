<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierImportRun extends Model
{
    public const TRIGGERS = ['scheduled', 'manual', 'retry', 'force'];

    public const STATUSES = ['pending', 'running', 'completed', 'completed_with_warnings', 'failed', 'skipped'];

    protected $fillable = [
        'supplier_id',
        'supplier_feed_id',
        'import_job_id',
        'trigger_type',
        'import_type',
        'status',
        'started_at',
        'finished_at',
        'duration_seconds',
        'products_seen',
        'products_created',
        'products_updated',
        'products_skipped',
        'products_failed',
        'products_out_of_stock',
        'products_needs_review',
        'attributes_mapped',
        'attributes_unmapped',
        'availability_mapped',
        'availability_unmapped',
        'warning_count',
        'error_count',
        'warnings',
        'errors',
        'report',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'warnings' => 'array',
            'errors' => 'array',
            'report' => 'array',
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
}
