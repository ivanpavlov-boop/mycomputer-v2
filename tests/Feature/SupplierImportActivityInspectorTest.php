<?php

namespace Tests\Feature;

use App\Models\ImportJob;
use App\Models\Supplier;
use App\Models\SupplierFeed;
use App\Models\SupplierImportRun;
use App\Services\Suppliers\Onboarding\SupplierImportActivityInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SupplierImportActivityInspectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_and_legacy_active_statuses_are_fail_closed(): void
    {
        foreach (['pending', 'queued', 'running', 'processing', 'started'] as $status) {
            $supplier = Supplier::factory()->create();
            $feed = SupplierFeed::factory()->create(['supplier_id' => $supplier->id]);
            ImportJob::query()->create([
                'supplier_id' => $supplier->id,
                'supplier_feed_id' => $feed->id,
                'type' => 'xml',
                'mode' => 'manual',
                'status' => $status,
            ]);

            $result = app(SupplierImportActivityInspector::class)->inspect($supplier->id);
            $this->assertSame('active', $result['state'], $status);
            $this->assertSame(1, $result['active_count'], $status);
        }
    }

    public function test_all_known_terminal_statuses_are_inactive(): void
    {
        foreach (['previewed', 'completed', 'completed_with_errors', 'completed_with_warnings', 'failed', 'skipped', 'cancelled'] as $status) {
            $supplier = Supplier::factory()->create();
            $feed = SupplierFeed::factory()->create(['supplier_id' => $supplier->id]);
            ImportJob::query()->create([
                'supplier_id' => $supplier->id,
                'supplier_feed_id' => $feed->id,
                'type' => 'xml',
                'mode' => 'manual',
                'status' => $status,
            ]);

            $result = app(SupplierImportActivityInspector::class)->inspect($supplier->id);
            $this->assertSame('clear', $result['state'], $status);
            $this->assertSame(0, $result['active_count'], $status);
            $this->assertSame(0, $result['unknown_state_count'], $status);
        }

        foreach (SupplierImportRun::STATUSES as $status) {
            $supplier = Supplier::factory()->create();
            SupplierImportRun::query()->create([
                'supplier_id' => $supplier->id,
                'trigger_type' => 'manual',
                'status' => $status,
            ]);
            $expected = in_array($status, ['pending', 'running'], true) ? 'active' : 'clear';
            $this->assertSame($expected, app(SupplierImportActivityInspector::class)->inspect($supplier->id)['state'], $status);
        }
    }

    public function test_invented_status_remains_unknown(): void
    {
        $supplier = Supplier::factory()->create();
        $feed = SupplierFeed::factory()->create(['supplier_id' => $supplier->id]);
        ImportJob::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_feed_id' => $feed->id,
            'type' => 'xml',
            'mode' => 'manual',
            'status' => 'invented_terminal_state',
        ]);

        $result = app(SupplierImportActivityInspector::class)->inspect($supplier->id);
        $this->assertSame('unknown', $result['state']);
        $this->assertSame(1, $result['unknown_state_count']);
    }
}
