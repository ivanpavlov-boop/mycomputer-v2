<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierImportRun;
use App\Models\SupplierProduct;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use JsonException;
use Tests\TestCase;

class ControlledSupplierScheduleFreezeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'catalog_sync.create_enabled' => true,
            'catalog_sync.update_enabled' => false,
            'catalog_sync.sync_all_enabled' => false,
            'catalog_sync.auto_enabled' => false,
        ]);
    }

    public function test_command_is_registered_with_controlled_schedule_only_options(): void
    {
        $command = Artisan::all()['suppliers:controlled-schedule-freeze'];
        $definition = $command->getDefinition();

        $this->assertStringContainsString('controlled supplier schedule freeze', strtolower((string) $command->getDescription()));
        $this->assertStringContainsString('no imports', strtolower((string) $command->getDescription()));
        $this->assertStringContainsString('catalog sync', strtolower((string) $command->getDescription()));

        foreach ([
            'supplier', 'dry-run', 'apply', 'confirm-supplier', 'confirm-action', 'confirm-write-scope',
            'confirm-scheduler-stopped', 'expected-supplier-id', 'expected-schedule-enabled',
            'expected-schedule-type', 'expected-import-enabled', 'expected-staged-count',
            'expected-linked-count', 'expected-unlinked-count', 'expected-last-import-at', 'reason',
            'format', 'summary-only', 'issue-sample-limit',
        ] as $option) {
            $this->assertTrue($definition->hasOption($option));
        }

        foreach ([
            'sync', 'sync-all', 'create', 'update-products', 'delete', 'unlink', 'link', 'import',
            'fetch', 'dispatch', 'queue', 'download-images', 'enable-automatic-sync', 'enable-update-sync',
        ] as $option) {
            $this->assertFalse($definition->hasOption($option));
        }
    }

    /** @throws JsonException */
    public function test_dry_run_is_the_default_and_makes_no_changes(): void
    {
        Bus::fake();
        Http::fake();
        $supplier = $this->supplier();
        $before = $this->protectedCounts();

        $payload = $this->commandJson(['--supplier' => $supplier->slug]);

        $this->assertSame('controlled-supplier-schedule-freeze-v1', $payload['schema_version']);
        $this->assertSame('dry_run', $payload['mode']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['apply_requested']);
        $this->assertTrue($payload['can_apply']);
        $this->assertFalse($payload['transaction_attempted']);
        $this->assertFalse($payload['transaction_committed']);
        $this->assertSame(true, $payload['observed_state_before']['schedule_enabled']);
        $this->assertSame(false, $payload['planned_state_after']['schedule_enabled']);
        $this->assertSame($this->zeroRecordsChanged(), $payload['records_changed']);
        $this->assertSame($before, $this->protectedCounts());
        $this->assertTrue(Supplier::query()->findOrFail($supplier->id)->schedule_enabled);
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_explicit_dry_run_is_not_combined_with_apply(): void
    {
        $supplier = $this->supplier();
        $before = $this->protectedCounts();

        $status = Artisan::call('suppliers:controlled-schedule-freeze', [
            '--supplier' => $supplier->slug,
            '--apply' => true,
            '--dry-run' => true,
            '--format' => 'json',
        ]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('either --apply or --dry-run', Artisan::output());
        $this->assertSame($before, $this->protectedCounts());
    }

    public function test_apply_requires_all_operator_confirmations_and_reason(): void
    {
        $supplier = $this->supplier();
        $status = Artisan::call('suppliers:controlled-schedule-freeze', [
            '--supplier' => $supplier->slug,
            '--apply' => true,
            '--format' => 'json',
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $status);
        $this->assertContains('supplier_confirmation_mismatch', $payload['refusal_reasons']);
        $this->assertContains('action_confirmation_mismatch', $payload['refusal_reasons']);
        $this->assertContains('write_scope_confirmation_mismatch', $payload['refusal_reasons']);
        $this->assertContains('scheduler_stop_not_confirmed', $payload['refusal_reasons']);
        $this->assertContains('expected_supplier_id_mismatch', $payload['refusal_reasons']);
        $this->assertContains('reason_required', $payload['refusal_reasons']);
        $this->assertSame(true, Supplier::query()->findOrFail($supplier->id)->schedule_enabled);
    }

    public function test_confirmation_and_expected_state_mismatches_refuse_without_writes(): void
    {
        $supplier = $this->supplier();
        $base = $this->applyArguments($supplier);
        $cases = [
            ['--confirm-supplier' => 'wrong-supplier', 'reason' => 'supplier_confirmation_mismatch'],
            ['--confirm-action' => 'freeze-now', 'reason' => 'action_confirmation_mismatch'],
            ['--confirm-write-scope' => 'supplier-only', 'reason' => 'write_scope_confirmation_mismatch'],
            ['--confirm-scheduler-stopped' => false, 'reason' => 'scheduler_stop_not_confirmed'],
            ['--expected-supplier-id' => $supplier->id + 100, 'reason' => 'expected_supplier_id_mismatch'],
            ['--expected-schedule-enabled' => 'false', 'reason' => 'expected_schedule_state_mismatch'],
            ['--expected-schedule-type' => 'twice_daily', 'reason' => 'expected_schedule_type_mismatch'],
            ['--expected-import-enabled' => 'false', 'reason' => 'expected_import_state_mismatch'],
            ['--expected-staged-count' => 4, 'reason' => 'expected_staged_count_mismatch'],
            ['--expected-linked-count' => 4, 'reason' => 'expected_linked_count_mismatch'],
            ['--expected-unlinked-count' => 4, 'reason' => 'expected_unlinked_count_mismatch'],
            ['--expected-last-import-at' => '2026-01-01T00:00:00+00:00', 'reason' => 'expected_last_import_at_mismatch'],
        ];

        foreach ($cases as $case) {
            $arguments = array_merge($base, array_diff_key($case, ['reason' => true]));
            $payload = $this->commandJson($arguments, 1);

            $this->assertContains($case['reason'], $payload['refusal_reasons'], 'Missing refusal: '.$case['reason']);
            $this->assertFalse($payload['transaction_committed']);
        }

        $this->assertTrue(Supplier::query()->findOrFail($supplier->id)->schedule_enabled);
    }

    public function test_dry_run_reports_already_disabled_and_apply_refuses_it(): void
    {
        $supplier = $this->supplier(['schedule_enabled' => false]);

        $dryRun = $this->commandJson(['--supplier' => $supplier->slug]);
        $this->assertTrue($dryRun['success']);
        $this->assertFalse($dryRun['can_apply']);
        $this->assertSame('schedule_already_disabled', $dryRun['verdict']);

        $apply = $this->commandJson($this->applyArguments($supplier), 1);
        $this->assertContains('schedule_already_disabled', $apply['refusal_reasons']);
        $this->assertFalse($apply['transaction_committed']);
    }

    public function test_active_import_refuses_apply_without_changing_schedule(): void
    {
        $supplier = $this->supplier();
        SupplierImportRun::query()->create([
            'supplier_id' => $supplier->id,
            'trigger_type' => 'manual',
            'import_type' => 'xml',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $payload = $this->commandJson($this->applyArguments($supplier), 1);

        $this->assertContains('import_currently_running', $payload['refusal_reasons']);
        $this->assertSame('active_import_detected', $payload['verdict']);
        $this->assertTrue(Supplier::query()->findOrFail($supplier->id)->schedule_enabled);
    }

    public function test_unknown_import_state_refuses_apply_without_changing_schedule(): void
    {
        $schema = DB::connection()->getSchemaBuilder();

        Schema::shouldReceive('hasTable')
            ->andReturnUsing(fn (string $table): bool => $table !== 'import_jobs' && $schema->hasTable($table));
        Schema::shouldReceive('hasColumn')
            ->andReturnUsing(fn (string $table, string $column): bool => $schema->hasColumn($table, $column));
        Schema::shouldReceive('getColumnListing')
            ->andReturnUsing(fn (string $table): array => $schema->getColumnListing($table));

        $supplier = $this->supplier();
        $payload = $this->commandJson($this->applyArguments($supplier), 1);

        $this->assertContains('import_state_unknown', $payload['refusal_reasons']);
        $this->assertFalse($payload['transaction_committed']);
        $this->assertTrue(Supplier::query()->findOrFail($supplier->id)->schedule_enabled);
    }

    public function test_unsafe_catalog_sync_flags_refuse_apply_without_changing_flags_or_schedule(): void
    {
        $supplier = $this->supplier();

        foreach ([
            'create_enabled' => false,
            'update_enabled' => true,
            'sync_all_enabled' => true,
            'auto_enabled' => true,
        ] as $flag => $value) {
            config(['catalog_sync.'.$flag => $value]);
            $payload = $this->commandJson($this->applyArguments($supplier), 1);

            $this->assertContains('unsafe_catalog_sync_flags', $payload['refusal_reasons']);
            $this->assertFalse($payload['transaction_committed']);
        }

        config([
            'catalog_sync.create_enabled' => true,
            'catalog_sync.update_enabled' => false,
            'catalog_sync.sync_all_enabled' => false,
            'catalog_sync.auto_enabled' => false,
        ]);
        $this->assertTrue(Supplier::query()->findOrFail($supplier->id)->schedule_enabled);
    }

    public function test_invalid_and_ambiguous_supplier_fail_safely(): void
    {
        $this->assertContains('invalid_supplier', $this->commandJson(['--supplier' => 'not-a-real-supplier'], 1)['refusal_reasons']);

        Supplier::factory()->create(['company_name' => 'Duplicate Supplier', 'slug' => 'duplicate-one', 'schedule_enabled' => true]);
        Supplier::factory()->create(['company_name' => 'Duplicate Supplier', 'slug' => 'duplicate-two', 'schedule_enabled' => true]);

        $payload = $this->commandJson(['--supplier' => 'Duplicate Supplier'], 1);
        $this->assertContains('ambiguous_supplier', $payload['refusal_reasons']);
    }

    /** @throws JsonException */
    public function test_successful_apply_changes_only_one_supplier_schedule_and_dispatches_nothing(): void
    {
        Bus::fake();
        Http::fake();
        $supplier = $this->supplier();
        $product = Product::withoutEvents(fn (): Product => Product::factory()->create());
        $this->stagedProduct($supplier, null, ['product_id' => $product->id]);
        $this->stagedProduct($supplier, 'UNLINKED-001');
        $before = $this->protectedCounts();

        $payload = $this->commandJson($this->applyArguments($supplier));

        $this->assertTrue($payload['success']);
        $this->assertSame('apply', $payload['mode']);
        $this->assertFalse($payload['dry_run']);
        $this->assertTrue($payload['transaction_attempted']);
        $this->assertTrue($payload['transaction_committed']);
        $this->assertSame(1, $payload['attempted_supplier_changes']);
        $this->assertSame(1, $payload['committed_supplier_changes']);
        $this->assertSame(1, $payload['schedule_state_changed']);
        $this->assertSame(1, $payload['records_changed']['suppliers']);
        foreach ($payload['records_changed'] as $table => $count) {
            if ($table !== 'suppliers') {
                $this->assertSame(0, $count, $table.' was unexpectedly changed.');
            }
        }

        $afterSupplier = Supplier::query()->findOrFail($supplier->id);
        $this->assertFalse($afterSupplier->schedule_enabled);
        $this->assertTrue($afterSupplier->import_enabled);
        $this->assertSame('daily', $afterSupplier->schedule_type);
        $this->assertSame($before, $this->protectedCounts());
        $this->assertSame(2, SupplierProduct::query()->where('supplier_id', $supplier->id)->count());
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_failed_postcondition_rolls_back_schedule_change(): void
    {
        $supplier = $this->supplier();
        $mutatePostcondition = true;
        DB::listen(function (QueryExecuted $query) use (&$mutatePostcondition, $supplier): void {
            $sql = strtolower(preg_replace('/\s+/', ' ', $query->sql) ?? '');

            if (! $mutatePostcondition || ! str_contains($sql, 'update') || ! str_contains($sql, 'suppliers') || ! str_contains($sql, 'schedule_enabled')) {
                return;
            }

            $mutatePostcondition = false;
            DB::table('suppliers')->where('id', $supplier->id)->update(['import_enabled' => false]);
        });

        try {
            $payload = $this->commandJson($this->applyArguments($supplier), 1);
        } finally {
            $mutatePostcondition = false;
        }

        $this->assertContains('postcondition_failed', $payload['refusal_reasons']);
        $this->assertFalse($payload['transaction_committed']);
        $this->assertTrue(Supplier::query()->findOrFail($supplier->id)->schedule_enabled);
        $this->assertTrue(Supplier::query()->findOrFail($supplier->id)->import_enabled);
    }

    public function test_second_apply_is_refused_after_the_schedule_is_frozen(): void
    {
        $supplier = $this->supplier();
        $this->assertSame(0, Artisan::call('suppliers:controlled-schedule-freeze', $this->applyArguments($supplier)));

        $second = $this->commandJson($this->applyArguments($supplier), 1);

        $this->assertContains('schedule_already_disabled', $second['refusal_reasons']);
        $this->assertFalse($second['transaction_committed']);
        $this->assertSame(0, $second['committed_supplier_changes']);
    }

    public function test_json_is_redacted_and_table_output_is_readable(): void
    {
        $supplier = $this->supplier([
            'website' => 'https://private.example.test/feed?token=DO_NOT_OUTPUT',
            'notes' => 'password=DO_NOT_OUTPUT',
        ]);

        $json = Artisan::call('suppliers:controlled-schedule-freeze', [
            '--supplier' => $supplier->slug,
            '--format' => 'json',
        ]);
        $this->assertSame(0, $json);
        $encoded = Artisan::output();
        $this->assertStringNotContainsString('DO_NOT_OUTPUT', $encoded);
        json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

        $table = Artisan::call('suppliers:controlled-schedule-freeze', [
            '--supplier' => $supplier->slug,
            '--summary-only' => true,
        ]);
        $this->assertSame(0, $table);
        $output = Artisan::output();
        $this->assertStringContainsString('Controlled supplier schedule freeze dry-run', $output);
        $this->assertStringContainsString('Dry-run only', $output);
        $this->assertStringContainsString('Schedule before', $output);
        $this->assertStringContainsString('No database records', $output);
        $this->assertStringNotContainsString('DO_NOT_OUTPUT', $output);
    }

    /** @param array<string, mixed> $overrides */
    private function supplier(array $overrides = []): Supplier
    {
        return Supplier::factory()->create(array_merge([
            'company_name' => 'Controlled Audit Supplier',
            'slug' => 'controlled-audit-supplier',
            'status' => 'active',
            'import_enabled' => true,
            'schedule_enabled' => true,
            'schedule_type' => 'daily',
            'last_import_at' => null,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function stagedProduct(Supplier $supplier, ?string $sku, array $overrides = []): SupplierProduct
    {
        return SupplierProduct::query()->create(array_merge([
            'supplier_id' => $supplier->id,
            'supplier_sku' => $sku,
            'name' => 'Controlled audit staging row',
            'price' => 100,
            'quantity' => 1,
            'currency' => 'EUR',
            'raw_data' => ['source' => 'fixture'],
            'payload_hash' => sha1((string) str()->uuid()),
            'received_at' => now(),
            'status' => 'new',
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function applyArguments(Supplier $supplier): array
    {
        $staged = SupplierProduct::query()->where('supplier_id', $supplier->id)->count();
        $linked = SupplierProduct::query()->where('supplier_id', $supplier->id)->whereNotNull('product_id')->count();

        return [
            '--supplier' => $supplier->slug,
            '--apply' => true,
            '--confirm-supplier' => $supplier->slug,
            '--confirm-action' => 'freeze-for-audit',
            '--confirm-write-scope' => 'schedule-enabled-only',
            '--confirm-scheduler-stopped' => true,
            '--expected-supplier-id' => $supplier->id,
            '--expected-schedule-enabled' => 'true',
            '--expected-schedule-type' => $supplier->schedule_type,
            '--expected-import-enabled' => 'true',
            '--expected-staged-count' => $staged,
            '--expected-linked-count' => $linked,
            '--expected-unlinked-count' => $staged - $linked,
            '--reason' => 'deterministic read-only audit stability',
            '--format' => 'json',
        ];
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    private function commandJson(array $arguments, int $expectedStatus = 0): array
    {
        $status = Artisan::call('suppliers:controlled-schedule-freeze', array_merge(['--format' => 'json'], $arguments));
        $output = Artisan::output();
        $this->assertSame($expectedStatus, $status, $output);

        try {
            return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->fail($exception->getMessage()."\n".$output);
        }
    }

    /** @return array<string, int> */
    private function protectedCounts(): array
    {
        return [
            'suppliers' => Supplier::query()->count(),
            'supplier_products' => SupplierProduct::query()->count(),
            'products' => Product::query()->count(),
        ];
    }

    /** @return array<string, int> */
    private function zeroRecordsChanged(): array
    {
        $recordsChanged = [
            'attribute_values' => 0,
            'canonical_product_families' => 0,
            'categories' => 0,
            'catalog_sync' => 0,
            'catalog_sync_batches' => 0,
            'catalog_sync_logs' => 0,
            'category_product_attributes' => 0,
            'product_attribute_values' => 0,
            'product_attributes' => 0,
            'products' => 0,
            'supplier_category_mappings' => 0,
            'supplier_products' => 0,
            'suppliers' => 0,
        ];

        ksort($recordsChanged);

        return $recordsChanged;
    }
}
