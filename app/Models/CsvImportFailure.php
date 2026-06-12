<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CsvImportFailure extends Model
{
    protected $fillable = [
        'csv_import_job_id',
        'row_number',
        'error_type',
        'error_message',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'raw_data' => 'array',
        ];
    }

    public function importJob(): BelongsTo
    {
        return $this->belongsTo(CsvImportJob::class, 'csv_import_job_id');
    }
}
