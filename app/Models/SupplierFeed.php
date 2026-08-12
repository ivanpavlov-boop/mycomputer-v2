<?php

namespace App\Models;

use App\Exceptions\ImportHistoryReferenceProtectedException;
use App\Models\Concerns\GuardsImportHistoryIdentity;
use Database\Factories\SupplierFeedFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;

class SupplierFeed extends Model
{
    /** @use HasFactory<SupplierFeedFactory> */
    use GuardsImportHistoryIdentity, HasFactory;

    private const HISTORY_FOREIGN_KEYS = [
        'import_histories_import_job_id_foreign',
        'import_histories_supplier_feed_id_foreign',
    ];

    protected $fillable = [
        'supplier_id',
        'feed_name',
        'feed_type',
        'feed_url',
        'username',
        'password',
        'update_interval',
        'mapping',
        'last_sync_at',
        'last_error',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'mapping' => 'array',
            'last_sync_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (SupplierFeed $feed): void {
            if ($feed->isDirty(['id', 'supplier_id']) && $feed->hasImportHistoryReferences()) {
                throw ImportHistoryReferenceProtectedException::supplierFeedIdentity();
            }
        });
    }

    public function hasImportHistoryReferences(): bool
    {
        $key = $this->getRawOriginal($this->getKeyName()) ?? $this->getKey();

        if ($key === null) {
            return false;
        }

        return ImportHistory::query()
            ->where('supplier_feed_id', $key)
            ->orWhereHas('importJob', fn ($query) => $query->where('supplier_feed_id', $key))
            ->exists();
    }

    public function delete()
    {
        if ($this->exists && $this->hasImportHistoryReferences()) {
            throw ImportHistoryReferenceProtectedException::supplierFeedDeletion();
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
                throw ImportHistoryReferenceProtectedException::supplierFeedDeletion($exception);
            }

            throw $exception;
        }
    }

    public function forceDelete()
    {
        return $this->delete();
    }

    protected function performUpdate(Builder $query)
    {
        $dirtyIdentityFields = array_keys(array_filter(
            $this->getDirty(),
            fn (mixed $value, string $field): bool => in_array($field, $this->importHistoryIdentityFields(), true),
            ARRAY_FILTER_USE_BOTH,
        ));

        return $this->guardImportHistoryIdentityMutation(
            $dirtyIdentityFields,
            fn () => parent::performUpdate($query),
        );
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        return $this->guardImportHistoryIdentityMutation(
            [(string) $column, ...array_keys($extra)],
            fn () => parent::incrementOrDecrement($column, $amount, $extra, $method),
        );
    }

    /** @return array<int, string> */
    protected function importHistoryIdentityFields(): array
    {
        return ['id', 'supplier_id'];
    }

    protected function importHistoryIdentityException(): ImportHistoryReferenceProtectedException
    {
        return ImportHistoryReferenceProtectedException::supplierFeedIdentity();
    }

    public function getNameAttribute(): string
    {
        return $this->feed_name;
    }

    public function getTypeAttribute(): string
    {
        return $this->feed_type;
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierFeedItem::class);
    }

    public function supplierProducts(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }

    public function importJobs(): HasMany
    {
        return $this->hasMany(ImportJob::class);
    }

    public function importHistories(): HasMany
    {
        return $this->hasMany(ImportHistory::class);
    }
}
