<?php

namespace App\Models;

use App\Exceptions\ImportHistoryReferenceProtectedException;
use App\Models\Concerns\GuardsImportHistoryIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;

class ImportJob extends Model
{
    use GuardsImportHistoryIdentity;

    private const HISTORY_FOREIGN_KEYS = ['import_histories_import_job_id_foreign'];

    protected $fillable = [
        'supplier_id',
        'supplier_feed_id',
        'xml_mapping_template_id',
        'type',
        'mode',
        'status',
        'preview_limit',
        'total_rows',
        'processed_rows',
        'failed_rows',
        'preview_data',
        'started_at',
        'finished_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'preview_data' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (ImportJob $job): void {
            if ($job->isDirty(['id', 'supplier_id', 'supplier_feed_id']) && $job->hasImportHistoryReferences()) {
                throw ImportHistoryReferenceProtectedException::importJobIdentity();
            }
        });
    }

    public function hasImportHistoryReferences(): bool
    {
        $key = $this->getRawOriginal($this->getKeyName()) ?? $this->getKey();

        return $key !== null
            && ImportHistory::query()->where('import_job_id', $key)->exists();
    }

    public function delete()
    {
        if ($this->exists && $this->hasImportHistoryReferences()) {
            throw ImportHistoryReferenceProtectedException::importJobDeletion();
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
                throw ImportHistoryReferenceProtectedException::importJobDeletion($exception);
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
        return ['id', 'supplier_id', 'supplier_feed_id'];
    }

    protected function importHistoryIdentityException(): ImportHistoryReferenceProtectedException
    {
        return ImportHistoryReferenceProtectedException::importJobIdentity();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function feed(): BelongsTo
    {
        return $this->belongsTo(SupplierFeed::class, 'supplier_feed_id');
    }

    public function mappingTemplate(): BelongsTo
    {
        return $this->belongsTo(XmlMappingTemplate::class, 'xml_mapping_template_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(ImportHistory::class);
    }

    public function failures(): HasMany
    {
        return $this->hasMany(FailedImport::class);
    }
}
