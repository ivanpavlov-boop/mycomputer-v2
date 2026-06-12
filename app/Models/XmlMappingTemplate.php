<?php

namespace App\Models;

use Database\Factories\XmlMappingTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class XmlMappingTemplate extends Model
{
    /** @use HasFactory<XmlMappingTemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'name',
        'description',
        'root_path',
        'field_map',
        'validation_rules',
        'defaults',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'field_map' => 'array',
            'validation_rules' => 'array',
            'defaults' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function importJobs(): HasMany
    {
        return $this->hasMany(ImportJob::class);
    }
}
