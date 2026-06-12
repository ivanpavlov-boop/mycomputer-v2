<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportJob extends Model
{
    protected $fillable = [
        'supplier_id',
        'supplier_feed_id',
        'xml_mapping_template_id',
        'type',
        'mode',
        'status',
        'preview_limit',
        'total_rows',
        'processed_rows',
        'failed_rows',
        'preview_data',
        'started_at',
        'finished_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'preview_data' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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

    public function mappingTemplate(): BelongsTo
    {
        return $this->belongsTo(XmlMappingTemplate::class, 'xml_mapping_template_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(ImportHistory::class);
    }

    public function failures(): HasMany
    {
        return $this->hasMany(FailedImport::class);
    }
}
