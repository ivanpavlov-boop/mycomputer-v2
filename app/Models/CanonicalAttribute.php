<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CanonicalAttribute extends Model
{
    public const TYPES = [
        'text',
        'number',
        'boolean',
        'select',
        'multiselect',
        'dimension',
        'weight',
        'capacity',
        'frequency',
        'power',
        'resolution',
    ];

    protected $fillable = [
        'code',
        'name',
        'group_name',
        'type',
        'unit',
        'is_filterable',
        'is_comparable',
        'is_required',
        'category_scope',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_filterable' => 'boolean',
            'is_comparable' => 'boolean',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'category_scope' => 'array',
        ];
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(AttributeAlias::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(CanonicalAttributeValue::class)->orderBy('sort_order');
    }

    public function supplierAttributes(): HasMany
    {
        return $this->hasMany(SupplierProductAttribute::class);
    }

    public function catalogAssignments(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }
}
