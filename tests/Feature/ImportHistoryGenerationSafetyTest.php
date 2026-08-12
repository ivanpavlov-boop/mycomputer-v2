<?php

namespace Tests\Feature;

use App\Exceptions\ImportHistoryTransitionRejectedException;
use App\Filament\Resources\ImportHistories\ImportHistoryResource;
use App\Filament\Resources\ImportHistories\Pages\ListImportHistories;
use App\Models\ImportHistory;
use App\Models\ImportJob;
use App\Models\Supplier;
use App\Models\SupplierFeed;
use App\Models\User;
use App\Policies\ImportHistoryPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

final class ImportHistoryGenerationSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_history_filament_resource_is_list_and_view_only(): void
    {
        [$user, $history] = $this->historyFixture();
        $this->actingAs($user);

        $this->assertSame(['index', 'view'], array_keys(ImportHistoryResource::getPages()));
        $this->assertTrue(ImportHistoryResource::canViewAny());
        $this->assertTrue(ImportHistoryResource::canView($history));
        $this->assertFalse(ImportHistoryResource::canCreate());
        $this->assertFalse(ImportHistoryResource::canEdit($history));
        $this->assertFalse(ImportHistoryResource::canDelete($history));
        $this->assertFalse(ImportHistoryResource::canDeleteAny());
        $this->assertFalse(ImportHistoryResource::canForceDelete($history));
        $this->assertFalse(ImportHistoryResource::canForceDeleteAny());
        $this->assertFalse(ImportHistoryResource::canRestore($history));
        $this->assertFalse(ImportHistoryResource::canRestoreAny());
        $routeNames = collect(Route::getRoutes()->getRoutesByName())->keys();
        $this->assertNotContains('filament.admin.resources.import-histories.create', $routeNames);
        $this->assertNotContains('filament.admin.resources.import-histories.edit', $routeNames);

        Livewire::test(ListImportHistories::class)
            ->assertCanSeeTableRecords([$history])
            ->assertTableActionExists('view', null, $history)
            ->assertTableActionDoesNotExist('edit', null, $history)
            ->assertTableActionDoesNotExist('delete', null, $history)
            ->assertTableBulkActionDoesNotExist('delete');

        $this->get(ImportHistoryResource::getUrl('view', ['record' => $history]))
            ->assertOk()
            ->assertSee('XML import started.');
    }

    public function test_backend_policy_denies_every_manual_mutation_even_for_super_admin(): void
    {
        [$user, $history] = $this->historyFixture();
        $policy = new ImportHistoryPolicy;

        $this->assertTrue($policy->viewAny($user));
        $this->assertTrue($policy->view($user, $history));
        $this->assertFalse($policy->create($user));
        $this->assertFalse($policy->update($user, $history));
        $this->assertFalse($policy->delete($user, $history));
        $this->assertFalse($policy->deleteAny($user));
        $this->assertFalse($policy->restore($user, $history));
        $this->assertFalse($policy->restoreAny($user));
        $this->assertFalse($policy->forceDelete($user, $history));
        $this->assertFalse($policy->forceDeleteAny($user));
    }

    public function test_only_importer_owned_creation_and_terminal_transition_are_allowed(): void
    {
        [, $history, $job] = $this->historyFixture();
        $generationId = $history->id;

        $history->transitionForImport('finished', 'warning', 'XML import finished.', ['failed_rows' => 1]);
        $history->refresh();

        $this->assertSame($generationId, $history->id);
        $this->assertSame('finished', $history->event);
        $this->assertSame('warning', $history->level);
        $this->assertSame(['failed_rows' => 1], $history->context);
        $this->assertSame(1, ImportHistory::query()->where('import_job_id', $job->id)->count());

        try {
            ImportHistory::query()->create([
                'import_job_id' => $job->id,
                'supplier_id' => $job->supplier_id,
                'event' => 'started',
                'level' => 'info',
            ]);
            $this->fail('Expected manual creation to be rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('only be created by an import engine', $exception->getMessage());
        }
    }

    public function test_stale_terminal_transitions_cannot_overwrite_the_winning_terminal_state(): void
    {
        foreach ([
            ['finished', 'warning', 'Winner finished.', ['failed_rows' => 1], 'failed', 'error'],
            ['failed', 'error', 'Winner failed.', ['exception' => 'synthetic'], 'finished', 'info'],
        ] as [$winningEvent, $winningLevel, $winningMessage, $winningContext, $losingEvent, $losingLevel]) {
            [, $history, $job] = $this->historyFixture();
            $winner = ImportHistory::query()->findOrFail($history->id);
            $staleLoser = ImportHistory::query()->findOrFail($history->id);

            $winner->transitionForImport($winningEvent, $winningLevel, $winningMessage, $winningContext);
            $winningState = $this->historyDatabaseState($history);

            try {
                $staleLoser->transitionForImport($losingEvent, $losingLevel, 'Stale terminal overwrite.');
                $this->fail('Expected the stale terminal transition to be rejected.');
            } catch (ImportHistoryTransitionRejectedException $exception) {
                $this->assertSame('Import history terminal transition was already consumed.', $exception->getMessage());
            }

            $this->assertSame($winningState, $this->historyDatabaseState($history));
            $this->assertSame($winningEvent, $staleLoser->event);
            $this->assertSame($winningMessage, $staleLoser->message);
            $this->assertSame($winningContext, $staleLoser->context);
            $this->assertSame(1, ImportHistory::query()->where('import_job_id', $job->id)->count());

            $this->assertImportHistoryOperationRejected(
                fn () => $staleLoser->transitionForImport('finished', 'info', 'Repeated terminal transition.'),
                'already consumed',
            );
            $this->assertImportHistoryOperationRejected(
                fn () => $staleLoser->transitionForImport('started', 'info', 'Terminal to active transition.'),
                'invalid import history transition',
            );
            $this->assertImportHistoryOperationRejected(
                fn () => $staleLoser->forceFill(['message' => 'Importer context leaked.'])->saveQuietly(),
                'only be updated by its import engine',
            );
        }
    }

    public function test_started_marker_uses_locked_persisted_parent_identity(): void
    {
        $originalSupplier = Supplier::factory()->create();
        $originalFeed = SupplierFeed::factory()->create(['supplier_id' => $originalSupplier->id]);
        $job = ImportJob::query()->create([
            'supplier_id' => $originalSupplier->id,
            'supplier_feed_id' => $originalFeed->id,
            'type' => 'xml',
            'mode' => 'manual',
            'status' => 'running',
        ]);
        $staleJob = ImportJob::query()->findOrFail($job->id);
        $currentSupplier = Supplier::factory()->create();
        $currentFeed = SupplierFeed::factory()->create(['supplier_id' => $currentSupplier->id]);

        ImportJob::query()->findOrFail($job->id)->update([
            'supplier_id' => $currentSupplier->id,
            'supplier_feed_id' => $currentFeed->id,
        ]);

        $history = ImportHistory::startForImport($staleJob, 'Locked identity marker.');

        $this->assertSame($job->id, $history->import_job_id);
        $this->assertSame($currentSupplier->id, $history->supplier_id);
        $this->assertSame($currentFeed->id, $history->supplier_feed_id);
        $this->assertDatabaseMissing('import_histories', [
            'id' => $history->id,
            'supplier_id' => $originalSupplier->id,
        ]);
    }

    public function test_started_marker_fails_closed_when_parent_deletion_wins(): void
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
        $staleJob = ImportJob::query()->findOrFail($job->id);
        $historyCount = ImportHistory::query()->count();

        $job->delete();

        try {
            ImportHistory::startForImport($staleJob, 'Missing parent marker.');
            $this->fail('Expected marker creation to reject a deleted import job.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($historyCount, ImportHistory::query()->count());
        $this->assertDatabaseMissing('import_histories', ['import_job_id' => $job->id]);
    }

    public function test_quiet_and_event_suppressed_creation_are_rejected_outside_importer_context(): void
    {
        [, $history, $job] = $this->historyFixture();
        $attributes = [
            'import_job_id' => $job->id,
            'supplier_id' => $job->supplier_id,
            'supplier_feed_id' => $job->supplier_feed_id,
            'event' => 'started',
            'level' => 'info',
        ];
        $count = ImportHistory::query()->count();
        $generationId = (int) ImportHistory::query()->max('id');

        foreach ([
            'builder createQuietly' => fn () => ImportHistory::query()->createQuietly($attributes),
            'model saveQuietly creation' => function () use ($attributes): void {
                (new ImportHistory($attributes))->saveQuietly();
            },
            'withoutEvents create' => fn () => ImportHistory::withoutEvents(
                fn () => ImportHistory::query()->create($attributes),
            ),
            'forceCreateQuietly' => fn () => ImportHistory::query()->forceCreateQuietly($attributes),
        ] as $operation => $callback) {
            $this->assertImportHistoryOperationRejected($callback, 'only be created by an import engine');
            $this->assertSame($count, ImportHistory::query()->count(), $operation);
            $this->assertSame($generationId, (int) ImportHistory::query()->max('id'), $operation);
            $this->assertDatabaseHas('import_histories', ['id' => $history->id]);
        }
    }

    public function test_quiet_and_event_suppressed_updates_preserve_the_complete_generation_row(): void
    {
        [, $history] = $this->historyFixture();
        $before = $this->historyDatabaseState($history);
        $generationId = (int) ImportHistory::query()->max('id');

        foreach ([
            'saveQuietly' => function () use ($history): void {
                $history->fresh()->forceFill(['message' => 'Unsupported quiet update.'])->saveQuietly();
            },
            'updateQuietly' => fn () => $history->fresh()->updateQuietly(['message' => 'Unsupported quiet update.']),
            'withoutEvents save' => function () use ($history): void {
                ImportHistory::withoutEvents(function () use ($history): void {
                    $history->fresh()->forceFill(['message' => 'Unsupported eventless update.'])->save();
                });
            },
            'increment' => fn () => $history->fresh()->increment('id'),
            'incrementQuietly' => fn () => $history->fresh()->incrementQuietly('id'),
        ] as $operation => $callback) {
            $this->assertImportHistoryOperationRejected($callback, 'only be updated by its import engine');
            $this->assertSame($before, $this->historyDatabaseState($history), $operation);
            $this->assertSame($generationId, (int) ImportHistory::query()->max('id'), $operation);
        }
    }

    public function test_normal_quiet_event_suppressed_and_force_deletion_are_rejected(): void
    {
        [, $history] = $this->historyFixture();
        $before = $this->historyDatabaseState($history);
        $generationId = (int) ImportHistory::query()->max('id');

        foreach ([
            'delete' => fn () => $history->fresh()->delete(),
            'deleteQuietly' => fn () => $history->fresh()->deleteQuietly(),
            'withoutEvents delete' => fn () => ImportHistory::withoutEvents(fn () => $history->fresh()->delete()),
            'forceDelete' => fn () => $history->fresh()->forceDelete(),
            'forceDestroy' => fn () => ImportHistory::forceDestroy($history->id),
        ] as $operation => $callback) {
            $this->assertImportHistoryOperationRejected($callback, 'generation evidence cannot be deleted');
            $this->assertSame($before, $this->historyDatabaseState($history), $operation);
            $this->assertSame($generationId, (int) ImportHistory::query()->max('id'), $operation);
        }
    }

    public function test_generation_identity_is_immutable_inside_importer_context_and_context_is_cleared(): void
    {
        [, $history, $job] = $this->historyFixture();
        $otherSupplier = Supplier::factory()->create();
        $otherFeed = SupplierFeed::factory()->create(['supplier_id' => $otherSupplier->id]);
        $otherJob = ImportJob::query()->create([
            'supplier_id' => $otherSupplier->id,
            'supplier_feed_id' => $otherFeed->id,
            'type' => 'xml',
            'mode' => 'manual',
            'status' => 'running',
        ]);
        $before = $this->historyDatabaseState($history);
        $generationId = (int) ImportHistory::query()->max('id');

        foreach ([
            'id' => $history->id + 10_000,
            'import_job_id' => $otherJob->id,
            'supplier_id' => $otherSupplier->id,
            'supplier_feed_id' => $otherFeed->id,
        ] as $field => $value) {
            $candidate = $history->fresh();
            $candidate->forceFill([$field => $value]);

            $this->assertImportHistoryOperationRejected(
                fn () => $candidate->transitionForImport('finished', 'info', 'Unsupported identity transition.'),
                'generation identity is immutable',
            );
            $this->assertSame($before, $this->historyDatabaseState($history), $field);
            $this->assertSame($generationId, (int) ImportHistory::query()->max('id'), $field);
        }

        $this->assertImportHistoryOperationRejected(
            function () use ($history): void {
                $history->fresh()->forceFill(['message' => 'Context must be cleared.'])->saveQuietly();
            },
            'only be updated by its import engine',
        );

        $history->transitionForImport('finished', 'info', 'XML import finished.');
        $this->assertSame($generationId, $history->refresh()->id);
        $this->assertSame('finished', $history->event);
        $this->assertSame(1, ImportHistory::query()->where('import_job_id', $job->id)->count());
        $this->assertImportHistoryOperationRejected(
            function () use ($history): void {
                $history->fresh()->forceFill(['message' => 'Context must remain cleared.'])->saveQuietly();
            },
            'only be updated by its import engine',
        );
    }

    public function test_production_code_has_no_direct_query_or_raw_history_destruction_path(): void
    {
        $patterns = [
            'direct import history table mutation' => '~DB::table\(\s*[\'\"]import_histories[\'\"]\s*\)(?:(?!;).)*->\s*(?:insert|insertOrIgnore|update|updateOrInsert|upsert|delete|truncate)\s*\(~s',
            'direct import history builder mutation' => '~ImportHistory::(?:(?!;).)*->\s*(?:insert|insertOrIgnore|update|updateOrInsert|upsert|delete|truncate)\s*\(~s',
            'direct import history relationship mutation' => '~importHistories\(\)(?:(?!;).)*->\s*(?:create|insert|update|upsert|delete|truncate)\s*\(~s',
            'raw import history mutation' => '~\b(?:delete\s+from|update|insert\s+into|replace\s+into|truncate(?:\s+table)?)\s+[`\"]?import_histories\b~i',
            'direct supplier table destruction' => '~DB::table\(\s*[\'\"]suppliers[\'\"]\s*\)(?:(?!;).)*->\s*(?:delete|truncate)\s*\(~s',
            'direct supplier builder destruction' => '~Supplier::(?:(?!;).)*->\s*(?:delete|forceDelete|truncate)\s*\(~s',
            'raw supplier destruction' => '~\b(?:delete\s+from|truncate(?:\s+table)?)\s+[`\"]?suppliers\b~i',
            'direct historical parent table destruction' => '~DB::table\(\s*[\'\"](?:import_jobs|supplier_feeds)[\'\"]\s*\)(?:(?!;).)*->\s*(?:delete|truncate)\s*\(~s',
            'raw historical parent destruction' => '~\b(?:delete\s+from|truncate(?:\s+table)?)\s+[`\"]?(?:import_jobs|supplier_feeds)\b~i',
        ];
        $violations = [];

        foreach (File::allFiles(app_path()) as $file) {
            $source = File::get($file->getPathname());

            foreach ($patterns as $label => $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    $violations[] = $file->getRelativePathname().': '.$label;
                }
            }
        }

        $this->assertSame([], $violations);

        $historySource = File::get(app_path('Models/ImportHistory.php'));
        $this->assertSame(1, preg_match_all(
            '~->where\(\'event\', \'started\'\)\s*->toBase\(\)\s*->update\(\$attributes\)~',
            $historySource,
        ), 'The importer-only terminal compare-and-set must be the sole deliberate query-builder mutation.');
    }

    public function test_generation_deletion_identity_mutation_and_supplier_cascade_are_rejected(): void
    {
        [, $history] = $this->historyFixture();
        $otherSupplier = Supplier::factory()->create();

        foreach (['delete', 'id', 'supplier'] as $operation) {
            try {
                if ($operation === 'delete') {
                    $history->fresh()->delete();
                } else {
                    $candidate = $history->fresh();
                    if ($operation === 'id') {
                        $candidate->id = $candidate->id + 10_000;
                    } else {
                        $candidate->supplier_id = $otherSupplier->id;
                    }
                    $candidate->save();
                }
                $this->fail("Expected import history {$operation} rejection.");
            } catch (LogicException $exception) {
                $this->assertStringContainsString('Import history', $exception->getMessage());
            }
        }

        try {
            $history->supplier()->firstOrFail()->delete();
            $this->fail('Expected supplier cascade protection.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('cannot be deleted', $exception->getMessage());
        }

        $this->assertDatabaseHas('import_histories', [
            'id' => $history->id,
            'supplier_id' => $history->supplier_id,
        ]);
    }

    /** @return array{0: User, 1: ImportHistory, 2: ImportJob} */
    private function historyFixture(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $user->assignRole(User::ROLE_SUPER_ADMIN);
        $supplier = Supplier::factory()->create();
        $feed = SupplierFeed::factory()->create(['supplier_id' => $supplier->id]);
        $job = ImportJob::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_feed_id' => $feed->id,
            'type' => 'xml',
            'mode' => 'manual',
            'status' => 'running',
        ]);
        $history = ImportHistory::startForImport($job, 'XML import started.');

        return [$user, $history, $job];
    }

    /** @return array<string, mixed> */
    private function historyDatabaseState(ImportHistory $history): array
    {
        return (array) DB::table('import_histories')->where('id', $history->id)->firstOrFail();
    }

    private function assertImportHistoryOperationRejected(callable $operation, string $messageFragment): void
    {
        try {
            $operation();
            $this->fail('Expected import history operation to be rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsStringIgnoringCase($messageFragment, $exception->getMessage());
        }
    }
}
