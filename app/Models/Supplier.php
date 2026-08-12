<?php

namespace App\Models;

use App\Exceptions\ImportHistoryReferenceProtectedException;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;

class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    private const HISTORY_FOREIGN_KEYS = [
        'import_histories_import_job_id_foreign',
        'import_histories_supplier_id_foreign',
    ];

    protected $fillable = [
        'company_name',
        'slug',
        'contact_person',
        'email',
        'phone',
        'website',
        'notes',
        'priority',
        'sync_strategy',
        'msrp_strategy',
        'vat_mode',
        'vat_rate',
        'import_enabled',
        'schedule_enabled',
        'schedule_type',
        'morning_import_time',
        'evening_import_time',
        'timezone',
        'stagger_minutes',
        'last_import_at',
        'next_import_at',
        'maximum_product_drop_percent',
        'minimum_product_count',
        'allow_destructive_sync',
        'last_import_notification_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'import_enabled' => 'boolean',
            'schedule_enabled' => 'boolean',
            'last_import_at' => 'datetime',
            'next_import_at' => 'datetime',
            'allow_destructive_sync' => 'boolean',
            'last_import_notification_at' => 'datetime',
            'vat_rate' => 'decimal:2',
        ];
    }

    public function hasImportHistoryReferences(): bool
    {
        $key = $this->getRawOriginal($this->getKeyName()) ?? $this->getKey();

        return $key !== null
            && ImportHistory::query()->where('supplier_id', $key)->exists();
    }

    public function delete()
    {
        if ($this->exists && $this->hasImportHistoryReferences()) {
            throw ImportHistoryReferenceProtectedException::supplierDeletion();
        }

        try {
            return parent::delete();
        } catch (QueryException $exception) {
            if (ImportHistoryReferenceProtectedException::matchesHistoricalForeignKeyRestriction(
                $exception,
                $this->getTable(),
                self::HISTORY_FOREIGN_KEYS,
                $this->hasImportHistoryReferences(),
            )) {
                throw ImportHistoryReferenceProtectedException::supplierDeletion($exception);
            }

            throw $exception;
        }
    }

    public function forceDelete()
    {
        return $this->delete();
    }

    public function getNameAttribute(): string
    {
        return $this->company_name;
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function feeds(): HasMany
    {
        return $this->hasMany(SupplierFeed::class);
    }

    public function supplierProducts(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }

    public function xmlMappingTemplates(): HasMany
    {
        return $this->hasMany(XmlMappingTemplate::class);
    }

    public function importJobs(): HasMany
    {
        return $this->hasMany(ImportJob::class);
    }

    public function importHistories(): HasMany
    {
        return $this->hasMany(ImportHistory::class);
    }

    public function productSupplierOffers(): HasMany
    {
        return $this->hasMany(ProductSupplierOffer::class);
    }

    public function supplierProductAttributes(): HasMany
    {
        return $this->hasMany(SupplierProductAttribute::class);
    }

    public function importRuns(): HasMany
    {
        return $this->hasMany(SupplierImportRun::class);
    }

    public function pricingRules(): HasMany
    {
        return $this->hasMany(PricingRule::class);
    }

    public function exclusionRules(): HasMany
    {
        return $this->hasMany(SupplierExclusionRule::class);
    }
}
