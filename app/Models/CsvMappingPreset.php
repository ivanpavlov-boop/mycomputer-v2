<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CsvMappingPreset extends Model
{
    protected $fillable = [
        'name',
        'type',
        'mapping',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'mapping' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
