<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PcCompatibilityRule extends Model
{
    public const RULE_TYPES = [
        'cpu_motherboard',
        'ram_motherboard',
        'gpu_psu',
        'case_motherboard',
        'cooler_cpu',
        'storage_motherboard',
    ];

    public const OPERATORS = ['equals', 'contains', 'gte', 'lte'];

    protected $fillable = [
        'rule_type',
        'source_attribute',
        'target_attribute',
        'operator',
        'value',
        'priority',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'priority' => 'integer'];
    }
}
