<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CsvExportJob extends Model
{
    protected $fillable = [
        'type',
        'status',
        'filters',
        'file_path',
        'total_rows',
        'processed_rows',
        'started_at',
        'finished_at',
        'error_message',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
