<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CanonicalProductFamily extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'name_bg',
        'name_en',
        'description_bg',
        'description_en',
        'sort_order',
        'active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function supplierCategoryMappings(): HasMany
    {
        return $this->hasMany(SupplierCategoryMapping::class);
    }
}
