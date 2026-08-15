<?php

namespace App\Models;

use App\Exceptions\ImportHistoryTransitionRejectedException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ImportHistory extends Model
{
    private static bool $importerMutationAllowed = false;

    protected $fillable = [
        'import_job_id',
        'supplier_id',
        'supplier_feed_id',
        'event',
        'level',
        'message',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    protected function performInsert(Builder $query)
    {
        self::assertImporterMutationAllowed('created');

        return parent::performInsert($query);
    }

    protected function performUpdate(Builder $query)
    {
        self::assertImporterMutationAllowed('updated');
        $this->assertGenerationIdentityIsImmutable();

        return parent::performUpdate($query);
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        self::assertImporterMutationAllowed('updated');

        if (in_array($column, ['id', 'import_job_id', 'supplier_id', 'supplier_feed_id'], true)) {
            throw new LogicException('Import history generation identity is immutable.');
        }

        return parent::incrementOrDecrement($column, $amount, $extra, $method);
    }

    public function delete()
    {
        throw new LogicException('Import history generation evidence cannot be deleted.');
    }

    public function forceDelete()
    {
        return $this->delete();
    }

    /** @param array<string, mixed> $context */
    public static function startForImport(ImportJob $job, string $message, array $context = []): self
    {
        $jobKey = $job->getRawOriginal($job->getKeyName()) ?? $job->getKey();

        return $job->getConnection()->transaction(function () use ($job, $jobKey, $message, $context): self {
            $lockedJob = $job->newQueryWithoutRelationships()
                ->whereKey($jobKey)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedFeed = $lockedJob->feed()->lockForUpdate()->firstOrFail();

            if ((int) $lockedFeed->supplier_id !== (int) $lockedJob->supplier_id) {
                throw new LogicException('Import job and supplier feed identity do not match.');
            }

            return self::withImporterMutation(fn (): self => self::on($lockedJob->getConnectionName())->create([
                'import_job_id' => $lockedJob->id,
                'supplier_id' => $lockedJob->supplier_id,
                'supplier_feed_id' => $lockedJob->supplier_feed_id,
                'event' => 'started',
                'level' => 'info',
                'message' => $message,
                'context' => $context,
            ]));
        });
    }

    /** @param array<string, mixed> $context */
    public function transitionForImport(string $event, string $level, ?string $message = null, array $context = []): self
    {
        if (! in_array($event, ['finished', 'failed'], true)
            || ! in_array($level, ['info', 'warning', 'error'], true)) {
            throw new LogicException('Invalid import history transition.');
        }

        $this->assertGenerationIdentityIsImmutable();

        return self::withImporterMutation(function () use ($event, $level, $message, $context): self {
            return $this->getConnection()->transaction(function () use ($event, $level, $message, $context): self {
                $terminal = (new self)->forceFill([
                    'event' => $event,
                    'level' => $level,
                    'message' => $message,
                    'context' => $context,
                    'updated_at' => $this->freshTimestamp(),
                ]);
                $attributes = $terminal->getAttributes();
                $affectedRows = $this->newModelQuery()
                    ->whereKey($this->getKey())
                    ->where('event', 'started')
                    ->toBase()
                    ->update($attributes);

                if ($affectedRows !== 1) {
                    $persisted = $this->newModelQuery()->whereKey($this->getKey())->first();

                    if ($persisted !== null) {
                        $this->setRawAttributes($persisted->getAttributes(), true);
                        $this->setRelations([]);
                    }

                    throw $affectedRows === 0
                        ? ImportHistoryTransitionRejectedException::alreadyConsumed()
                        : ImportHistoryTransitionRejectedException::unexpectedAffectedRows();
                }

                return $this->refresh();
            });
        });
    }

    private static function withImporterMutation(callable $callback): mixed
    {
        if (self::$importerMutationAllowed) {
            throw new LogicException('Nested import history mutation is not allowed.');
        }

        self::$importerMutationAllowed = true;

        try {
            return $callback();
        } finally {
            self::$importerMutationAllowed = false;
        }
    }

    private static function assertImporterMutationAllowed(string $operation): void
    {
        if (! self::$importerMutationAllowed) {
            $message = $operation === 'created'
                ? 'Import history can only be created by an import engine.'
                : 'Import history can only be updated by its import engine.';

            throw new LogicException($message);
        }
    }

    private function assertGenerationIdentityIsImmutable(): void
    {
        foreach (['id', 'import_job_id', 'supplier_id', 'supplier_feed_id'] as $identityField) {
            if ($this->isDirty($identityField)) {
                throw new LogicException('Import history generation identity is immutable.');
            }
        }
    }

    public function importJob(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function feed(): BelongsTo
    {
        return $this->belongsTo(SupplierFeed::class, 'supplier_feed_id');
    }
}
