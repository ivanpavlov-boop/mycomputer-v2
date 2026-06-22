<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogSyncBatch extends Model
{
    public const string MODE_MANUAL_SELECTED_CREATE = 'manual_selected_create';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_PARTIAL = 'partial';

    public const string STATUS_FAILED = 'failed';

    protected $fillable = [
        'batch_uuid',
        'user_id',
        'supplier_id',
        'mode',
        'status',
        'selected_count',
        'created_count',
        'updated_count',
        'skipped_count',
        'failed_count',
        'started_at',
        'completed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'selected_count' => 'integer',
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'skipped_count' => 'integer',
            'failed_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(CatalogSyncLog::class);
    }
}
