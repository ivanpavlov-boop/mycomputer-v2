<?php

namespace Tests\Feature;

use App\Exceptions\ImportHistoryReferenceProtectedException;
use App\Exceptions\ImportHistoryTransitionRejectedException;
use App\Models\ImportHistory;
use App\Models\ImportJob;
use App\Models\Supplier;
use App\Models\SupplierFeed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use Tests\TestCase;

final class ImportHistoryForeignKeyMigrationTest extends TestCase
{
    public function test_upgrade_rollback_and_reapply_preserve_rows_and_delete_rules(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        $supplier = Supplier::factory()->create();
        $feed = SupplierFeed::factory()->create(['supplier_id' => $supplier->id]);
        $job = ImportJob::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_feed_id' => $feed->id,
            'type' => 'xml',
            'mode' => 'manual',
            'status' => 'running',
        ]);
        $history = ImportHistory::startForImport($job, 'Migration integrity marker.');
        $nullableHistoryId = DB::table('import_histories')->insertGetId([
            'import_job_id' => null,
            'supplier_id' => $supplier->id,
            'supplier_feed_id' => null,
            'event' => 'started',
            'level' => 'info',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $migration = require database_path('migrations/2026_08_13_090000_restrict_import_history_parent_foreign_keys.php');
        $finalRulesRestored = true;

        try {
            $this->assertSame([
                'import_job_id' => 'RESTRICT',
                'supplier_feed_id' => 'RESTRICT',
                'supplier_id' => 'RESTRICT',
            ], $this->historyForeignKeyDeleteRules());
            $this->assertGeneratedConstraintNames();

            $migration->down();
            $finalRulesRestored = false;
            $this->assertSame([
                'import_job_id' => 'SET NULL',
                'supplier_feed_id' => 'SET NULL',
                'supplier_id' => 'CASCADE',
            ], $this->historyForeignKeyDeleteRules());
            $this->assertHistoryRowsPreserved($history->id, $nullableHistoryId, $job->id, $supplier->id, $feed->id);

            $migration->up();
            $finalRulesRestored = true;
            $this->assertSame([
                'import_job_id' => 'RESTRICT',
                'supplier_feed_id' => 'RESTRICT',
                'supplier_id' => 'RESTRICT',
            ], $this->historyForeignKeyDeleteRules());
            $this->assertHistoryRowsPreserved($history->id, $nullableHistoryId, $job->id, $supplier->id, $feed->id);

            foreach (['import_jobs' => $job->id, 'supplier_feeds' => $feed->id, 'suppliers' => $supplier->id] as $table => $id) {
                try {
                    DB::table($table)->where('id', $id)->delete();
                    $this->fail("Expected {$table} database deletion to be restricted.");
                } catch (QueryException $exception) {
                    $this->assertStringContainsStringIgnoringCase('foreign', $exception->getMessage());
                }
            }

        } finally {
            if (! $finalRulesRestored) {
                $migration->up();
            }

            DB::table('import_histories')->whereIn('id', [$history->id, $nullableHistoryId])->delete();
            DB::table('import_jobs')->where('id', $job->id)->delete();
            DB::table('supplier_feeds')->where('id', $feed->id)->delete();
            DB::table('suppliers')->where('id', $supplier->id)->delete();
        }
    }

    public function test_terminal_compare_and_set_uses_two_mysql_connections(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires MySQL.');
        }

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        config(['database.connections.import_history_concurrency' => config('database.connections.mysql')]);
        DB::purge('import_history_concurrency');

        try {
            foreach ([
                ['finished', 'info', 'Cross-connection finished winner.', 'failed', 'error'],
                ['failed', 'error', 'Cross-connection failed winner.', 'finished', 'info'],
            ] as [$winningEvent, $winningLevel, $winningMessage, $losingEvent, $losingLevel]) {
                [$supplier, $feed, $job] = $this->parentFixture();
                $history = ImportHistory::startForImport($job, 'Cross-connection marker.');
                $winner = ImportHistory::query()->findOrFail($history->id);
                $staleLoser = (new ImportHistory)
                    ->setConnection('import_history_concurrency')
                    ->newQuery()
                    ->findOrFail($history->id);

                $this->assertSame('started', $winner->event);
                $this->assertSame('started', $staleLoser->event);
                $winner->transitionForImport($winningEvent, $winningLevel, $winningMessage, [
                    'winner' => $winningEvent,
                ]);

                try {
                    $staleLoser->transitionForImport($losingEvent, $losingLevel, 'Cross-connection stale loser.');
                    $this->fail('Expected cross-connection stale transition rejection.');
                } catch (ImportHistoryTransitionRejectedException $exception) {
                    $this->assertSame('Import history terminal transition was already consumed.', $exception->getMessage());
                }

                $this->assertDatabaseHas('import_histories', [
                    'id' => $history->id,
                    'import_job_id' => $job->id,
                    'supplier_id' => $supplier->id,
                    'supplier_feed_id' => $feed->id,
                    'event' => $winningEvent,
                    'message' => $winningMessage,
                ]);
                $this->assertSame($winningEvent, $staleLoser->event);
                $this->assertSame($winningMessage, $staleLoser->message);
                $this->assertSame(['winner' => $winningEvent], $staleLoser->context);
                $this->assertSame(1, ImportHistory::query()->where('import_job_id', $job->id)->count());
                $this->assertNotNull(DB::connection('import_history_concurrency')->selectOne('SELECT 1 AS lock_released'));
                $this->assertImporterContextIsClosed($job, $supplier, $feed);
                $this->deleteFixture($supplier, $feed, $job);
            }
        } finally {
            DB::disconnect('import_history_concurrency');
            DB::purge('import_history_concurrency');
        }
    }

    public function test_parent_deletion_races_use_a_second_mysql_connection(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires MySQL.');
        }

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        config(['database.connections.import_history_concurrency' => config('database.connections.mysql')]);
        DB::purge('import_history_concurrency');

        try {
            foreach (['supplier', 'job', 'feed_direct', 'feed_indirect'] as $parentType) {
                [$supplier, $feed, $job] = $this->parentFixture();
                $parent = match ($parentType) {
                    'supplier' => $supplier,
                    'job' => $job,
                    default => $feed,
                };
                $markerId = null;
                $active = true;

                Event::listen('eloquent.deleting: '.$parent::class, function (Model $candidate) use (
                    &$active,
                    &$markerId,
                    $parent,
                    $parentType,
                    $job,
                ): void {
                    if (! $active || ! $candidate->is($parent)) {
                        return;
                    }

                    $active = false;
                    $concurrentJob = (new ImportJob)
                        ->setConnection('import_history_concurrency')
                        ->newQuery()
                        ->findOrFail($job->id);
                    $history = ImportHistory::startForImport($concurrentJob, 'Committed cross-connection marker.');
                    $markerId = $history->id;

                    if ($parentType === 'feed_indirect') {
                        DB::connection('import_history_concurrency')
                            ->table('import_histories')
                            ->where('id', $history->id)
                            ->update(['supplier_feed_id' => null]);
                    }
                });

                try {
                    $parent->delete();
                    $this->fail("Expected the {$parentType} deletion race to be rejected.");
                } catch (ImportHistoryReferenceProtectedException $exception) {
                    $this->assertInstanceOf(QueryException::class, $exception->getPrevious());
                } finally {
                    $active = false;
                }

                $this->assertNotNull($markerId);
                $this->assertDatabaseHas('suppliers', ['id' => $supplier->id]);
                $this->assertDatabaseHas('supplier_feeds', ['id' => $feed->id]);
                $this->assertDatabaseHas('import_jobs', ['id' => $job->id]);
                $this->assertDatabaseHas('import_histories', [
                    'id' => $markerId,
                    'import_job_id' => $job->id,
                    'supplier_id' => $supplier->id,
                    'supplier_feed_id' => $parentType === 'feed_indirect' ? null : $feed->id,
                ]);
                $this->assertSame(
                    $markerId,
                    (int) ImportHistory::query()->where('supplier_id', $supplier->id)->max('id'),
                );
                $this->assertNotNull(DB::connection('import_history_concurrency')->selectOne('SELECT 1 AS lock_released'));
                $this->assertImporterContextIsClosed($job, $supplier, $feed);
                $this->deleteFixture($supplier, $feed, $job);
            }
        } finally {
            DB::disconnect('import_history_concurrency');
            DB::purge('import_history_concurrency');
        }
    }

    public function test_marker_creation_fails_when_parent_deletion_wins_on_the_other_connection(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires MySQL.');
        }

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        config(['database.connections.import_history_concurrency' => config('database.connections.mysql')]);
        DB::purge('import_history_concurrency');

        try {
            foreach (['supplier', 'job', 'feed'] as $parentType) {
                [$supplier, $feed, $job] = $this->parentFixture();
                $staleConcurrentJob = (new ImportJob)
                    ->setConnection('import_history_concurrency')
                    ->newQuery()
                    ->findOrFail($job->id);
                $parent = match ($parentType) {
                    'supplier' => $supplier,
                    'job' => $job,
                    'feed' => $feed,
                };

                $this->assertTrue((bool) $parent->delete());

                try {
                    ImportHistory::startForImport($staleConcurrentJob, 'Marker after parent deletion.');
                    $this->fail('Expected marker creation to fail after parent deletion won.');
                } catch (ModelNotFoundException) {
                    $this->addToAssertionCount(1);
                }

                $this->assertDatabaseMissing('import_histories', ['import_job_id' => $job->id]);
                $this->assertNotNull(DB::connection('import_history_concurrency')->selectOne('SELECT 1 AS lock_released'));
                $this->deleteFixture($supplier, $feed, $job);
            }
        } finally {
            DB::disconnect('import_history_concurrency');
            DB::purge('import_history_concurrency');
        }
    }

    /** @return array<string, string> */
    private function historyForeignKeyDeleteRules(): array
    {
        if (DB::getDriverName() === 'sqlite') {
            $rules = [];
            foreach (DB::select('PRAGMA foreign_key_list(import_histories)') as $foreignKey) {
                $rules[$foreignKey->from] = strtoupper($foreignKey->on_delete);
            }
            ksort($rules);

            return $rules;
        }

        $rows = DB::select(<<<'SQL'
            SELECT kcu.COLUMN_NAME AS column_name, rc.DELETE_RULE AS delete_rule
            FROM information_schema.REFERENTIAL_CONSTRAINTS rc
            INNER JOIN information_schema.KEY_COLUMN_USAGE kcu
                ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
                AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                AND kcu.TABLE_NAME = rc.TABLE_NAME
            WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
                AND rc.TABLE_NAME = 'import_histories'
            SQL);
        $rules = [];
        foreach ($rows as $foreignKey) {
            $rules[$foreignKey->column_name] = strtoupper($foreignKey->delete_rule);
        }
        ksort($rules);

        return $rules;
    }

    private function assertGeneratedConstraintNames(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->addToAssertionCount(1);

            return;
        }

        $names = collect(DB::select(<<<'SQL'
            SELECT CONSTRAINT_NAME AS constraint_name
            FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
                AND TABLE_NAME = 'import_histories'
            ORDER BY CONSTRAINT_NAME
            SQL))->pluck('constraint_name')->all();

        $this->assertSame([
            'import_histories_import_job_id_foreign',
            'import_histories_supplier_feed_id_foreign',
            'import_histories_supplier_id_foreign',
        ], $names);
    }

    private function assertHistoryRowsPreserved(
        int $historyId,
        int $nullableHistoryId,
        int $jobId,
        int $supplierId,
        int $feedId,
    ): void {
        $this->assertDatabaseHas('import_histories', [
            'id' => $historyId,
            'import_job_id' => $jobId,
            'supplier_id' => $supplierId,
            'supplier_feed_id' => $feedId,
        ]);
        $this->assertDatabaseHas('import_histories', [
            'id' => $nullableHistoryId,
            'import_job_id' => null,
            'supplier_id' => $supplierId,
            'supplier_feed_id' => null,
        ]);
    }

    /** @return array{0: Supplier, 1: SupplierFeed, 2: ImportJob} */
    private function parentFixture(): array
    {
        $supplier = Supplier::factory()->create();
        $feed = SupplierFeed::factory()->create(['supplier_id' => $supplier->id]);
        $job = ImportJob::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_feed_id' => $feed->id,
            'type' => 'xml',
            'mode' => 'manual',
            'status' => 'running',
        ]);

        return [$supplier, $feed, $job];
    }

    private function assertImporterContextIsClosed(ImportJob $job, Supplier $supplier, SupplierFeed $feed): void
    {
        $count = DB::connection('import_history_concurrency')->table('import_histories')->count();

        try {
            ImportHistory::on('import_history_concurrency')->create([
                'import_job_id' => $job->id,
                'supplier_id' => $supplier->id,
                'supplier_feed_id' => $feed->id,
                'event' => 'started',
                'level' => 'info',
            ]);
            $this->fail('Expected importer authorization context to be closed.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('only be created by an import engine', $exception->getMessage());
        }

        $this->assertSame(
            $count,
            DB::connection('import_history_concurrency')->table('import_histories')->count(),
        );
    }

    private function deleteFixture(Supplier $supplier, SupplierFeed $feed, ImportJob $job): void
    {
        DB::table('import_histories')->where('import_job_id', $job->id)->delete();
        DB::table('import_jobs')->where('id', $job->id)->delete();
        DB::table('supplier_feeds')->where('id', $feed->id)->delete();
        DB::table('suppliers')->where('id', $supplier->id)->delete();
    }
}
