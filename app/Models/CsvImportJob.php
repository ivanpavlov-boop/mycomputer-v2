<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CsvImportJob extends Model
{
    protected $fillable = [
        'type',
        'status',
        'file_path',
        'original_filename',
        'mapping',
        'mode',
        'total_rows',
        'processed_rows',
        'failed_rows',
        'preview_data',
        'started_at',
        'finished_at',
        'error_message',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'mapping' => 'array',
            'preview_data' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function failures(): HasMany
    {
        return $this->hasMany(CsvImportFailure::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
