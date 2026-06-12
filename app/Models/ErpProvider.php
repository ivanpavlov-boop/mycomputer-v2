<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ErpProvider extends Model
{
    public const STATUSES = ['active', 'inactive'];

    public const CODES = ['manual', 'mock', 'microinvest', 'erp_net', 'business_navigator'];

    protected $fillable = ['name', 'code', 'status', 'credentials', 'settings'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'settings' => 'array',
        ];
    }

    public function syncJobs(): HasMany
    {
        return $this->hasMany(ErpSyncJob::class, 'provider_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ErpDocument::class, 'provider_id');
    }

    public function productMappings(): HasMany
    {
        return $this->hasMany(ErpProductMapping::class, 'provider_id');
    }

    public function customerMappings(): HasMany
    {
        return $this->hasMany(ErpCustomerMapping::class, 'provider_id');
    }
}
