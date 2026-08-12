<?php

namespace Tests\Feature;

use App\Exceptions\ImportHistoryReferenceProtectedException;
use App\Filament\Resources\ImportJobs\ImportJobResource;
use App\Filament\Resources\ImportJobs\Pages\EditImportJob;
use App\Filament\Resources\ImportJobs\Pages\ListImportJobs;
use App\Filament\Resources\SupplierFeeds\Pages\EditSupplierFeed;
use App\Filament\Resources\SupplierFeeds\Pages\ListSupplierFeeds;
use App\Filament\Resources\SupplierFeeds\SupplierFeedResource;
use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\ImportHistory;
use App\Models\ImportJob;
use App\Models\Supplier;
use App\Models\SupplierFeed;
use App\Models\User;
use App\Policies\ImportPolicy;
use App\Policies\SupplierFeedPolicy;
use App\Policies\SupplierPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

final class ImportHistoryParentDeletionProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_referenced_parent_model_delete_quiet_delete_and_force_delete_are_rejected(): void
    {
        [, $feed, $job, $history] = $this->referencedFixture();
        $generationId = $history->id;

        foreach (['delete', 'deleteQuietly', 'forceDelete'] as $method) {
            $this->assertReferenceProtection(
                fn () => $job->fresh()->{$method}(),
                'import job referenced by import history',
            );
            $this->assertReferenceProtection(
                fn () => $feed->fresh()->{$method}(),
                'supplier feed referenced by import history',
            );

            $this->assertHistoryIdentity($history, $job, $feed, $generationId);
        }
    }

    public function test_referenced_supplier_normal_quiet_event_suppressed_and_force_delete_are_rejected(): void
    {
        [$supplier, $feed, $job, $history] = $this->referencedFixture();
        $supplierState = (array) DB::table('suppliers')->where('id', $supplier->id)->firstOrFail();
        $historyState = (array) DB::table('import_histories')->where('id', $history->id)->firstOrFail();
        $historyCount = ImportHistory::query()->where('supplier_id', $supplier->id)->count();
        $generationId = (int) ImportHistory::query()->where('supplier_id', $supplier->id)->max('id');

        foreach ([
            'delete' => fn () => $supplier->fresh()->delete(),
            'deleteQuietly' => fn () => $supplier->fresh()->deleteQuietly(),
            'withoutEvents delete' => fn () => Supplier::withoutEvents(fn () => $supplier->fresh()->delete()),
            'forceDelete' => fn () => $supplier->fresh()->forceDelete(),
            'forceDestroy' => fn () => Supplier::forceDestroy($supplier->id),
        ] as $operation => $callback) {
            $this->assertReferenceProtection($callback, 'supplier referenced by import history');
            $this->assertSame($supplierState, (array) DB::table('suppliers')->where('id', $supplier->id)->firstOrFail(), $operation);
            $this->assertSame($historyState, (array) DB::table('import_histories')->where('id', $history->id)->firstOrFail(), $operation);
            $this->assertSame($historyCount, ImportHistory::query()->where('supplier_id', $supplier->id)->count(), $operation);
            $this->assertSame($generationId, (int) ImportHistory::query()->where('supplier_id', $supplier->id)->max('id'), $operation);
            $this->assertHistoryIdentity($history, $job, $feed, $generationId);
        }
    }

    public function test_referenced_parent_generation_identity_cannot_be_reassigned(): void
    {
        [, $feed, $job, $history] = $this->referencedFixture();
        $otherSupplier = Supplier::factory()->create();
        $otherFeed = SupplierFeed::factory()->create(['supplier_id' => $otherSupplier->id]);
        $generationId = $history->id;

        $this->assertReferenceProtection(function () use ($job, $otherSupplier): void {
            $candidate = $job->fresh();
            $candidate->supplier_id = $otherSupplier->id;
            $candidate->save();
        }, 'Import job generation identity');
        $this->assertReferenceProtection(function () use ($job, $otherFeed): void {
            $candidate = $job->fresh();
            $candidate->supplier_feed_id = $otherFeed->id;
            $candidate->save();
        }, 'Import job generation identity');
        $this->assertReferenceProtection(function () use ($feed, $otherSupplier): void {
            $candidate = $feed->fresh();
            $candidate->supplier_id = $otherSupplier->id;
            $candidate->save();
        }, 'Supplier feed generation identity');

        $this->assertHistoryIdentity($history, $job, $feed, $generationId);
        $this->assertDatabaseHas('import_jobs', [
            'id' => $job->id,
            'supplier_id' => $job->supplier_id,
            'supplier_feed_id' => $feed->id,
        ]);
        $this->assertDatabaseHas('supplier_feeds', [
            'id' => $feed->id,
            'supplier_id' => $feed->supplier_id,
        ]);
    }

    public function test_referenced_import_job_identity_is_guarded_without_model_events(): void
    {
        [, $feed, $job, $history] = $this->referencedFixture();
        $otherSupplier = Supplier::factory()->create();
        $otherFeed = SupplierFeed::factory()->create(['supplier_id' => $otherSupplier->id]);

        $operations = [
            'normal save' => function (ImportJob $candidate) use ($otherSupplier): void {
                $candidate->supplier_id = $otherSupplier->id;
                $candidate->save();
            },
            'saveQuietly' => function (ImportJob $candidate) use ($otherSupplier): void {
                $candidate->supplier_id = $otherSupplier->id;
                $candidate->saveQuietly();
            },
            'updateQuietly' => fn (ImportJob $candidate) => $candidate->updateQuietly(['supplier_feed_id' => $otherFeed->id]),
            'withoutEvents save' => function (ImportJob $candidate) use ($otherSupplier): void {
                ImportJob::withoutEvents(function () use ($candidate, $otherSupplier): void {
                    $candidate->supplier_id = $otherSupplier->id;
                    $candidate->save();
                });
            },
            'forceFill quiet save' => fn (ImportJob $candidate) => $candidate->forceFill(['supplier_feed_id' => $otherFeed->id])->saveQuietly(),
            'relationship reassignment' => function (ImportJob $candidate) use ($otherSupplier): void {
                $candidate->supplier()->associate($otherSupplier);
                $candidate->save();
            },
            'increment identity' => fn (ImportJob $candidate) => $candidate->increment('supplier_id'),
            'decrementQuietly identity' => fn (ImportJob $candidate) => $candidate->decrementQuietly('supplier_feed_id'),
        ];

        foreach ($operations as $operation => $callback) {
            $candidate = $job->fresh();
            $this->assertIdentityMutationRejected($candidate, $callback, 'Import job generation identity');
            $this->assertSame($job->supplier_id, $candidate->supplier_id, $operation);
            $this->assertSame($job->supplier_feed_id, $candidate->supplier_feed_id, $operation);
            $this->assertHistoryIdentity($history, $job, $feed, $history->id);
        }

        $this->assertTrue($job->fresh()->updateQuietly(['status' => 'completed']));
        $this->assertDatabaseHas('import_jobs', ['id' => $job->id, 'status' => 'completed']);

        $unreferenced = $this->importJob($otherSupplier, $otherFeed);
        $thirdSupplier = Supplier::factory()->create();
        $thirdFeed = SupplierFeed::factory()->create(['supplier_id' => $thirdSupplier->id]);
        $this->assertTrue($unreferenced->updateQuietly([
            'supplier_id' => $thirdSupplier->id,
            'supplier_feed_id' => $thirdFeed->id,
        ]));
    }

    public function test_referenced_supplier_feed_identity_is_guarded_without_model_events(): void
    {
        [, $feed, $job, $history] = $this->referencedFixture();
        $otherSupplier = Supplier::factory()->create();

        $operations = [
            'normal save' => function (SupplierFeed $candidate) use ($otherSupplier): void {
                $candidate->supplier_id = $otherSupplier->id;
                $candidate->save();
            },
            'saveQuietly' => function (SupplierFeed $candidate) use ($otherSupplier): void {
                $candidate->supplier_id = $otherSupplier->id;
                $candidate->saveQuietly();
            },
            'updateQuietly' => fn (SupplierFeed $candidate) => $candidate->updateQuietly(['supplier_id' => $otherSupplier->id]),
            'withoutEvents save' => function (SupplierFeed $candidate) use ($otherSupplier): void {
                SupplierFeed::withoutEvents(function () use ($candidate, $otherSupplier): void {
                    $candidate->supplier_id = $otherSupplier->id;
                    $candidate->save();
                });
            },
            'forceFill quiet save' => fn (SupplierFeed $candidate) => $candidate->forceFill(['supplier_id' => $otherSupplier->id])->saveQuietly(),
            'relationship reassignment' => function (SupplierFeed $candidate) use ($otherSupplier): void {
                $candidate->supplier()->associate($otherSupplier);
                $candidate->save();
            },
            'increment identity' => fn (SupplierFeed $candidate) => $candidate->increment('supplier_id'),
            'decrementQuietly identity' => fn (SupplierFeed $candidate) => $candidate->decrementQuietly('supplier_id'),
        ];

        foreach ($operations as $operation => $callback) {
            $candidate = $feed->fresh();
            $this->assertIdentityMutationRejected($candidate, $callback, 'Supplier feed generation identity');
            $this->assertSame($feed->supplier_id, $candidate->supplier_id, $operation);
            $this->assertHistoryIdentity($history, $job, $feed, $history->id);
        }

        $this->assertTrue($feed->fresh()->updateQuietly(['status' => 'active']));
        $this->assertDatabaseHas('supplier_feeds', ['id' => $feed->id, 'status' => 'active']);

        $unreferenced = SupplierFeed::factory()->create(['supplier_id' => $feed->supplier_id]);
        $this->assertTrue($unreferenced->updateQuietly(['supplier_id' => $otherSupplier->id]));
    }

    public function test_supplier_feed_indirect_history_reference_is_protected_when_direct_reference_is_null(): void
    {
        [, $feed, $job, $history] = $this->referencedFixture();
        DB::table('import_histories')->where('id', $history->id)->update(['supplier_feed_id' => null]);

        $this->assertTrue($feed->fresh()->hasImportHistoryReferences());
        $this->assertReferenceProtection(
            fn () => $feed->fresh()->delete(),
            'supplier feed referenced by import history',
        );
        $this->assertDatabaseHas('import_histories', [
            'id' => $history->id,
            'import_job_id' => $job->id,
            'supplier_feed_id' => null,
        ]);
    }

    public function test_policies_deny_referenced_parent_deletion_for_super_admin_and_viewer(): void
    {
        [$supplier, $feed, $job] = $this->referencedFixture();
        $unreferencedFeed = SupplierFeed::factory()->create(['supplier_id' => $supplier->id]);
        $unreferencedJob = $this->importJob($supplier, $unreferencedFeed);
        $superAdmin = $this->user(User::ROLE_SUPER_ADMIN);
        $viewer = $this->user(User::ROLE_VIEWER_AUDITOR);
        $importPolicy = new ImportPolicy;
        $feedPolicy = new SupplierFeedPolicy;
        $supplierPolicy = new SupplierPolicy;
        $unreferencedSupplier = Supplier::factory()->create();

        $this->assertFalse($importPolicy->delete($superAdmin, $job));
        $this->assertFalse($importPolicy->forceDelete($superAdmin, $job));
        $this->assertFalse($feedPolicy->delete($superAdmin, $feed));
        $this->assertFalse($feedPolicy->forceDelete($superAdmin, $feed));
        $this->assertTrue($importPolicy->delete($superAdmin, $unreferencedJob));
        $this->assertTrue($feedPolicy->delete($superAdmin, $unreferencedFeed));
        $this->assertFalse($supplierPolicy->delete($superAdmin, $supplier));
        $this->assertFalse($supplierPolicy->forceDelete($superAdmin, $supplier));
        $this->assertTrue($supplierPolicy->delete($superAdmin, $unreferencedSupplier));
        $this->assertFalse($importPolicy->delete($viewer, $unreferencedJob));
        $this->assertFalse($feedPolicy->delete($viewer, $unreferencedFeed));
        $this->assertFalse($supplierPolicy->delete($viewer, $unreferencedSupplier));

        $this->actingAs($superAdmin);
        $this->assertFalse(ImportJobResource::canDelete($job));
        $this->assertFalse(SupplierFeedResource::canDelete($feed));
        $this->assertTrue(ImportJobResource::canDelete($unreferencedJob));
        $this->assertTrue(SupplierFeedResource::canDelete($unreferencedFeed));
        $this->assertTrue(ImportJobResource::canDeleteAny());
        $this->assertTrue(SupplierFeedResource::canDeleteAny());
        $this->assertFalse(SupplierResource::canDelete($supplier));
        $this->assertTrue(SupplierResource::canDelete($unreferencedSupplier));
        $this->assertTrue(SupplierResource::canDeleteAny());

        $this->actingAs($viewer);
        $this->assertFalse(ImportJobResource::canDelete($unreferencedJob));
        $this->assertFalse(SupplierFeedResource::canDelete($unreferencedFeed));
        $this->assertFalse(ImportJobResource::canDeleteAny());
        $this->assertFalse(SupplierFeedResource::canDeleteAny());
        $this->assertFalse(SupplierResource::canDelete($unreferencedSupplier));
        $this->assertFalse(SupplierResource::canDeleteAny());
    }

    public function test_filament_hides_referenced_parent_delete_and_bulk_delete_skips_it(): void
    {
        [$supplier, $feed, $job, $history] = $this->referencedFixture();
        $unreferencedFeed = SupplierFeed::factory()->create(['supplier_id' => $supplier->id]);
        $unreferencedJob = $this->importJob($supplier, $unreferencedFeed);
        $unreferencedSupplier = Supplier::factory()->create();
        $generationId = $history->id;
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN));

        Livewire::test(EditImportJob::class, ['record' => $job->getKey()])
            ->assertActionHidden('delete');
        Livewire::test(EditSupplierFeed::class, ['record' => $feed->getKey()])
            ->assertActionHidden('delete');
        Livewire::test(EditImportJob::class, ['record' => $unreferencedJob->getKey()])
            ->assertActionVisible('delete');
        Livewire::test(EditSupplierFeed::class, ['record' => $unreferencedFeed->getKey()])
            ->assertActionVisible('delete');
        Livewire::test(EditSupplier::class, ['record' => $supplier->getKey()])
            ->assertActionHidden('delete');
        Livewire::test(EditSupplier::class, ['record' => $unreferencedSupplier->getKey()])
            ->assertActionVisible('delete');

        Livewire::test(ListImportJobs::class)
            ->assertTableBulkActionExists('delete')
            ->callTableBulkAction('delete', collect([$job, $unreferencedJob]));

        $this->assertDatabaseHas('import_jobs', ['id' => $job->id]);
        $this->assertDatabaseMissing('import_jobs', ['id' => $unreferencedJob->id]);

        Livewire::test(ListSupplierFeeds::class)
            ->assertTableBulkActionExists('delete')
            ->callTableBulkAction('delete', collect([$feed, $unreferencedFeed]));

        $this->assertDatabaseHas('supplier_feeds', ['id' => $feed->id]);
        $this->assertDatabaseMissing('supplier_feeds', ['id' => $unreferencedFeed->id]);

        Livewire::test(ListSuppliers::class)
            ->assertTableBulkActionExists('delete')
            ->callTableBulkAction('delete', collect([$supplier, $unreferencedSupplier]));

        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id]);
        $this->assertDatabaseMissing('suppliers', ['id' => $unreferencedSupplier->id]);
        $this->assertHistoryIdentity($history, $job, $feed, $generationId);
    }

    public function test_unreferenced_parent_deletion_remains_available(): void
    {
        $supplier = Supplier::factory()->create();
        $feed = SupplierFeed::factory()->create(['supplier_id' => $supplier->id]);
        $job = $this->importJob($supplier, $feed);

        $this->assertTrue($job->deleteQuietly());
        $this->assertDatabaseMissing('import_jobs', ['id' => $job->id]);
        $this->assertTrue($feed->forceDelete());
        $this->assertDatabaseMissing('supplier_feeds', ['id' => $feed->id]);
        $this->assertTrue($supplier->deleteQuietly());
        $this->assertDatabaseMissing('suppliers', ['id' => $supplier->id]);
    }

    public function test_database_restrictions_close_marker_creation_parent_deletion_races(): void
    {
        foreach (['supplier', 'job', 'feed'] as $parentType) {
            $supplier = Supplier::factory()->create();
            $feed = SupplierFeed::factory()->create(['supplier_id' => $supplier->id]);
            $job = $this->importJob($supplier, $feed);
            $parent = match ($parentType) {
                'supplier' => $supplier,
                'job' => $job,
                'feed' => $feed,
            };
            $active = true;

            Event::listen('eloquent.deleting: '.$parent::class, function (Model $candidate) use (&$active, $parent, $job): void {
                if (! $active || ! $candidate->is($parent)) {
                    return;
                }

                $active = false;
                ImportHistory::startForImport($job, 'Concurrent synthetic import started.');
            });

            try {
                $parent->delete();
                $this->fail("Expected the {$parentType} deletion race to be rejected.");
            } catch (ImportHistoryReferenceProtectedException $exception) {
                $this->assertInstanceOf(QueryException::class, $exception->getPrevious());
            } finally {
                $active = false;
            }

            $history = ImportHistory::query()->where('import_job_id', $job->id)->sole();
            $this->assertDatabaseHas('suppliers', ['id' => $supplier->id]);
            $this->assertDatabaseHas('supplier_feeds', ['id' => $feed->id]);
            $this->assertDatabaseHas('import_jobs', ['id' => $job->id]);
            $this->assertDatabaseHas('import_histories', [
                'id' => $history->id,
                'import_job_id' => $job->id,
                'supplier_id' => $supplier->id,
                'supplier_feed_id' => $feed->id,
            ]);
            $this->assertSame($history->id, (int) ImportHistory::query()->where('supplier_id', $supplier->id)->max('id'));
        }
    }

    public function test_unrelated_query_exception_during_delete_is_not_translated(): void
    {
        $supplier = Supplier::factory()->create();
        $feed = SupplierFeed::factory()->create(['supplier_id' => $supplier->id]);
        $job = $this->importJob($supplier, $feed);
        $active = true;

        Event::listen('eloquent.deleting: '.Supplier::class, function (Supplier $candidate) use (&$active, $supplier, $job): void {
            if (! $active || ! $candidate->is($supplier)) {
                return;
            }

            $active = false;
            DB::table('import_histories')->insert([
                'import_job_id' => $job->id,
                'supplier_id' => PHP_INT_MAX,
                'supplier_feed_id' => $job->supplier_feed_id,
                'event' => 'started',
                'level' => 'info',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $supplier->delete();
            $this->fail('Expected the unrelated insert constraint failure.');
        } catch (QueryException $exception) {
            $this->assertStringContainsStringIgnoringCase('insert into', $exception->getSql());
        } finally {
            $active = false;
        }

        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id]);
        $this->assertDatabaseCount('import_histories', 0);
    }

    /** @return array{0: Supplier, 1: SupplierFeed, 2: ImportJob, 3: ImportHistory} */
    private function referencedFixture(): array
    {
        $supplier = Supplier::factory()->create();
        $feed = SupplierFeed::factory()->create(['supplier_id' => $supplier->id]);
        $job = $this->importJob($supplier, $feed);
        $history = ImportHistory::startForImport($job, 'Synthetic import started.');

        return [$supplier, $feed, $job, $history];
    }

    private function importJob(Supplier $supplier, SupplierFeed $feed): ImportJob
    {
        return ImportJob::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_feed_id' => $feed->id,
            'type' => 'xml',
            'mode' => 'manual',
            'status' => 'running',
        ]);
    }

    private function user(string $role): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['role' => $role]);
        $user->assignRole($role);

        return $user;
    }

    private function assertReferenceProtection(callable $operation, string $messageFragment): void
    {
        try {
            $operation();
            $this->fail('Expected import history reference protection.');
        } catch (ImportHistoryReferenceProtectedException $exception) {
            $this->assertStringContainsStringIgnoringCase($messageFragment, $exception->getMessage());
        }
    }

    private function assertIdentityMutationRejected(Model $candidate, callable $operation, string $messageFragment): void
    {
        try {
            $operation($candidate);
            $this->fail('Expected import history identity protection.');
        } catch (ImportHistoryReferenceProtectedException $exception) {
            $this->assertStringContainsStringIgnoringCase($messageFragment, $exception->getMessage());
        }
    }

    private function assertHistoryIdentity(
        ImportHistory $history,
        ImportJob $job,
        SupplierFeed $feed,
        int $generationId,
    ): void {
        $this->assertDatabaseHas('import_histories', [
            'id' => $history->id,
            'import_job_id' => $job->id,
            'supplier_id' => $job->supplier_id,
            'supplier_feed_id' => $feed->id,
        ]);
        $this->assertSame($generationId, (int) ImportHistory::query()->max('id'));
    }
}
