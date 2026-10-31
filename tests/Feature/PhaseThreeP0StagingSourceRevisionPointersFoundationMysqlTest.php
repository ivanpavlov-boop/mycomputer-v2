<?php

namespace Tests\Feature;

use App\Data\Suppliers\Imports\CanonicalSupplierImportSourceExecution;
use App\Data\Suppliers\Imports\CanonicalSupplierImportSourcePayloadReceipt;
use App\Data\Suppliers\Imports\ImportJobIdentity;
use App\Data\Suppliers\Imports\ResolvedSupplierImportSourceContext;
use App\Data\Suppliers\Imports\SupplierProductLogicalKey;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierImportMapping;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierSourceLocator;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierSourceProfileDescriptor;
use App\Models\ImportHistory;
use App\Models\ImportJob;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierFeed;
use App\Models\SupplierImportSourceProfile;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Models\XmlMappingTemplate;
use App\Repositories\Suppliers\SupplierImportSourceExecutionRepository;
use App\Repositories\Suppliers\SupplierImportSourcePayloadReceiptRepository;
use App\Services\Imports\XmlImportEngine;
use App\Services\Security\SsrfProtectionService;
use App\Services\Suppliers\SupplierCsvFeedImportService;
use Database\Migrations\Support\CanonicalSupplierPhaseThreeP0Oracle as Oracle;
use Database\Migrations\Support\CanonicalSupplierPhaseThreeP0Schema as P0;
use Database\Migrations\Support\CanonicalSupplierPhaseThreeP0SchemaException as SchemaFailure;
use Database\Migrations\Support\CanonicalSupplierPhaseThreeP0SchemaInspector as Inspector;
use Database\Migrations\Support\P0MigrationStep;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\Support\ExactSyntheticFeedSsrfProtectionService;
use Tests\TestCase;

require_once __DIR__.'/../../database/migrations/support/CanonicalSupplierPhaseThreeP0Schema.php';

final class PhaseThreeP0StagingSourceRevisionPointersFoundationMysqlTest extends TestCase
{
    private const GRANT = 'SUPPLIER_PHASE_THREE_P0_EMPTY_SCHEMA_DOWN_CONFIRMED';

    private const REVISIONS = 'supplier_product_source_revisions';

    // Transcribed from the approved contracts before executing this implementation.
    private const ENDPOINTS = [
        'P6' => [339, 'ea265e4717c2f61abba6731f08be576ff6fcd01fe6b673d61d41011a888056aa'],
        'P7' => [350, '765692fc06ba30ee87f27a7357edcb793927a52aef2375846a79257e5e8fcc83'],
        'P7_DOWN_1' => [349, '3f42d4567c985254acd7aaab903469c1035d870881adba431592abe6d509cd03'],
        'P7_DOWN_2' => [348, 'bf9efbbe90dc3add76109294b8de1801ce7a6e9af4684956415335a95485565a'],
        'P7_DOWN_3' => [348, '99b8610f13a307576198c2e1d77b5b903061d23ff82adbd57dae47ac703b7e00'],
    ];

    private int $fixtureSequence = 0;

    private array $recordedStates = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('P0-07 acceptance requires isolated MySQL 8.4.');
        }
        $this->assertStringStartsWith('8.4.', DB::scalar('SELECT VERSION()'));
        $this->assertMatchesRegularExpression('/test$/', DB::getDatabaseName());
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->assertEndpoint('P7');
    }

    protected function tearDown(): void
    {
        putenv(self::GRANT);
        unset($_ENV[self::GRANT], $_SERVER[self::GRANT]);
        DB::forgetExtension('phase_three_p0_schema_ddl');
        foreach (array_keys(DB::getConnections()) as $name) {
            DB::purge($name);
        }
        parent::tearDown();
    }

    public function test_fresh_discovery_exact_maps_defaults_creation_session_and_four_down_statements(): void
    {
        $default = DB::selectOne('SELECT DEFAULT_CHARACTER_SET_NAME AS charset, DEFAULT_COLLATION_NAME AS collation_name FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE()');
        $this->assertSame('utf8mb4', $default->charset);
        $this->assertContains($default->collation_name, ['utf8mb4_0900_ai_ci', 'utf8mb4_unicode_ci']);
        $down = $this->observe(fn () => $this->down());
        $this->assertCount(4, $down->completed);
        foreach ($down->completed as $i => $sql) {
            $this->assertSame(Oracle::operation('P0-07-DOWN-0'.($i + 1))['sql'], $sql);
        }
        $this->assertEndpoint('P6');
        $up = $this->observe(fn () => $this->migration()->up());
        $this->assertCount(4, $up->completed);
        $this->assertCount(2, $up->triggerSessions);
        foreach ($up->triggerSessions as $settings) {
            $this->assertSame('cp866', $settings['character_set_client']);
            $this->assertSame('cp866_general_ci', $settings['collation_connection']);
        }
        $this->assertSame($up->sessionBefore, $up->sessionAfter);
        $this->assertSame(1, $up->connections);
        $this->assertEndpoint('P7');
        $this->assertSame($default->collation_name, DB::scalar('SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE()'));
        $this->record('A1-fresh', ['default' => $default, 'up' => $up, 'down' => $down]);
    }

    public function test_compatible_populated_p6_preserves_every_old_column_and_nonempty_revisions_in_both_directions(): void
    {
        $this->down();
        $row = $this->revisionFixture('historical-evidence');
        // Legacy SKU is not inferred historical provenance or silently backfilled.
        DB::table('supplier_products')->where('id', $row['supplier_product_id'])->update(['supplier_sku' => 'legacy-current-sku']);
        DB::table(self::REVISIONS)->insert($row);
        SupplierProduct::query()->create(['supplier_id' => $row['supplier_id'], 'supplier_feed_id' => null, 'supplier_sku' => 'no-feed', 'raw_data' => [], 'payload_hash' => hash('sha256', 'legacy'), 'received_at' => now()]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'is_active' => true]);
        $admin->assignRole(Role::findOrCreate(User::ROLE_SUPER_ADMIN, 'web'));
        DB::table('supplier_product_attributes')->insert(['supplier_product_id' => $row['supplier_product_id'], 'source_type' => 'synthetic', 'raw_name' => 'manual', 'raw_value' => 'preserve']);
        DB::table('sessions')->insert(['id' => 'p007-preserve', 'user_id' => $admin->id, 'payload' => 'synthetic', 'last_activity' => 1]);
        $before = $this->rows();
        $this->migration()->up();
        $this->assertEndpoint('P7');
        $this->assertSame($before, $this->rows());
        $this->assertSame(0, DB::table('supplier_products')->whereNotNull('current_source_revision_id')->count());
        $this->assertSame(0, DB::table('supplier_products')->whereNotNull('supplier_product_identity_head_id')->count());
        $this->down();
        $this->assertEndpoint('P6');
        $this->assertSame($before, $this->rows());
        $admin = $admin->fresh();
        $this->assertTrue($admin->is_active);
        $this->assertTrue($admin->canAccessPanel(filament()->getPanel('admin')));
        $this->assertTrue($admin->canManageUsers());
        $this->zeroDdl('phase_three_p0_append_only_table_not_pristine', function (): void {
            $this->authorize();
            P0::runTerminalDowngradeStep(P0MigrationStep::P0_06);
        });
        $this->record('A2-A8-populated', ['oldColumnHashes' => $before, 'nonemptyRevisionsPreserved' => true]);
    }

    public function test_incompatible_populated_p6_supplier_feed_and_null_scope_refuse_before_any_ddl(): void
    {
        foreach (['supplier', 'feed', 'null-feed'] as $kind) {
            $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
            $this->down();
            $row = $this->revisionFixture('bad-'.$kind);
            $other = $this->revisionFixture('other-'.$kind);
            if ($kind === 'supplier') {
                $row['supplier_product_id'] = $other['supplier_product_id'];
            } else {
                DB::table('supplier_products')->where('id', $row['supplier_product_id'])
                    ->update(['supplier_feed_id' => $kind === 'null-feed' ? null : $other['supplier_feed_id']]);
            }
            DB::table(self::REVISIONS)->insert($row); // A real valid P6 ID-only graph.
            $before = $this->rows();
            $this->assertEndpoint('P6');
            $this->zeroDdl('phase_three_p0_pointer_scope_incompatible', fn () => $this->migration()->up());
            $this->assertSame($before, $this->rows());
            $this->assertEndpoint('P6');
        }
    }

    public function test_full_pair_publication_revision_transition_noop_workflow_and_caller_rollback(): void
    {
        $a = $this->revisionFixture("SKU-\u{00E9}");
        $aid = DB::table(self::REVISIONS)->insertGetId($a);
        $this->publish($a, $aid);
        $beforeA = (array) DB::table(self::REVISIONS)->find($aid);
        $b = $this->revisionFixture($a['supplier_sku_bytes'], $a);
        $bid = DB::table(self::REVISIONS)->insertGetId($b);
        DB::beginTransaction();
        $this->publish($b, $bid, ['name' => 'revision B projection']);
        $this->assertSame($bid, DB::table('supplier_products')->where('id', $a['supplier_product_id'])->value('current_source_revision_id'));
        $outside = $this->pdo();
        $this->assertSame($aid, (int) $outside->query('SELECT current_source_revision_id FROM supplier_products WHERE id='.$a['supplier_product_id'])->fetchColumn());
        DB::rollBack();
        $this->assertSame($aid, DB::table('supplier_products')->where('id', $a['supplier_product_id'])->value('current_source_revision_id'));
        $this->publish($b, $bid, ['name' => 'revision B projection']);
        $this->publish($b, $bid, ['name' => 'revision B projection']);
        DB::table('supplier_products')->where('id', $a['supplier_product_id'])->update(['product_id' => null, 'status' => 'new', 'synced_at' => now(), 'mapping_notes' => 'workflow only']);
        $this->assertSame($beforeA, (array) DB::table(self::REVISIONS)->find($aid));
        $this->assertSame($bid, DB::table('supplier_products')->where('id', $a['supplier_product_id'])->value('current_source_revision_id'));
        $this->record('A3', ['callerRollback' => true, 'structuralPublicationOnly' => true]);
    }

    public function test_insert_and_update_pair_scope_and_ownership_negatives_remain_enforced(): void
    {
        $a = $this->revisionFixture('A');
        $aid = DB::table(self::REVISIONS)->insertGetId($a);
        $b = $this->revisionFixture('B');
        $bid = DB::table(self::REVISIONS)->insertGetId($b);
        $plain = (array) DB::table('supplier_products')->find($a['supplier_product_id']);
        unset($plain['id']);
        foreach ([
            ['supplier_product_identity_head_id' => $a['supplier_product_identity_head_id']],
            ['current_source_revision_id' => $aid],
            ['supplier_product_identity_head_id' => $a['supplier_product_identity_head_id'], 'current_source_revision_id' => $aid, 'supplier_feed_id' => null],
            ['supplier_product_identity_head_id' => $a['supplier_product_identity_head_id'], 'current_source_revision_id' => $aid, 'supplier_id' => null],
            ['supplier_product_identity_head_id' => 99999999, 'current_source_revision_id' => 99999999],
        ] as $change) {
            $this->sqlRejected(fn () => DB::table('supplier_products')->insert([...$plain, 'supplier_sku' => uniqid('insert-'), ...$change]));
            $this->sqlRejected(fn () => DB::table('supplier_products')->where('id', $a['supplier_product_id'])->update($change));
        }
        foreach ([
            ['supplier_product_identity_head_id' => $b['supplier_product_identity_head_id'], 'current_source_revision_id' => $bid],
            ['supplier_product_identity_head_id' => $a['supplier_product_identity_head_id'], 'current_source_revision_id' => $bid],
            ['supplier_product_identity_head_id' => $a['supplier_product_identity_head_id'], 'current_source_revision_id' => $aid, 'supplier_id' => $b['supplier_id']],
            ['supplier_product_identity_head_id' => $a['supplier_product_identity_head_id'], 'current_source_revision_id' => $aid, 'supplier_feed_id' => $b['supplier_feed_id']],
        ] as $change) {
            $this->sqlRejected(fn () => DB::table('supplier_products')->where('id', $a['supplier_product_id'])->update($change));
        }
        $this->publish($a, $aid);
        foreach ([
            ['supplier_product_identity_head_id' => null, 'current_source_revision_id' => null],
            ['current_source_revision_id' => null],
            ['supplier_product_identity_head_id' => null],
            ['supplier_feed_id' => null],
            ['supplier_id' => $b['supplier_id']],
            ['supplier_product_identity_head_id' => $b['supplier_product_identity_head_id'], 'current_source_revision_id' => $bid],
        ] as $change) {
            $this->sqlRejected(fn () => DB::table('supplier_products')->where('id', $a['supplier_product_id'])->update($change), [1644]);
        }
        // A second parent with the same head cannot take ownership, even with its own valid revision.
        $c = $this->revisionFixture('A', $a);
        $clone = [...$plain, 'supplier_sku' => 'temporary-unique'];
        $pid = DB::table('supplier_products')->insertGetId($clone);
        $c['supplier_product_id'] = $pid;
        $cid = DB::table(self::REVISIONS)->insertGetId($c);
        $this->sqlRejected(fn () => $this->publish($c, $cid, ['supplier_sku' => 'A']), [1062]);
        $this->zeroDdl('phase_three_p0_pointers_not_pristine', fn () => $this->down());
    }

    public function test_publication_matches_binary_sku_and_all_seventeen_same_revision_fields_are_guarded(): void
    {
        foreach ([['A', 'a'], ["\u{00E9}", 'e'], ["\u{00E9}", "e\u{0301}"], ['A', 'A ']] as [$head, $different]) {
            $row = $this->revisionFixture($head);
            $id = DB::table(self::REVISIONS)->insertGetId($row);
            DB::table('supplier_products')->where('id', $row['supplier_product_id'])->update(['supplier_sku' => $different]);
            $this->sqlRejected(fn () => $this->publish($row, $id), [1644]);
            DB::table('supplier_products')->where('id', $row['supplier_product_id'])->update(['supplier_sku' => $head]);
            $this->publish($row, $id);
        }
        $row = $this->revisionFixture('guarded');
        $id = DB::table(self::REVISIONS)->insertGetId($row);
        $availability = DB::table('availability_statuses')->insertGetId(['code' => 'p007', 'name' => 'synthetic']);
        $strings = ['supplier_sku' => 'guarded', 'ean' => 'EAN', 'mpn' => 'MPN', 'name' => 'Name', 'brand_name' => 'Brand', 'category_name' => 'Category', 'external_availability_status' => 'Status', 'external_availability_label' => 'Label', 'currency' => 'EUR', 'payload_hash' => str_repeat('a', 64)];
        $typed = ['price' => '1.00', 'supplier_price_raw' => '2.00', 'recommended_price' => '3.00', 'quantity' => 4, 'availability_status_id' => $availability, 'raw_data' => '{"a":1}', 'received_at' => '2026-10-18 00:00:00'];
        DB::table('supplier_products')->where('id', $row['supplier_product_id'])->update([...$strings, ...$typed]);
        $this->publish($row, $id);
        $before = (array) DB::table('supplier_products')->find($row['supplier_product_id']);
        foreach ($strings as $field => $value) {
            $changed = $field === 'payload_hash' ? str_repeat('b', 64) : strtolower($value);
            if ($changed === $value) {
                $changed = strtoupper($value);
            }
            $this->sqlRejected(fn () => DB::table('supplier_products')->where('id', $row['supplier_product_id'])->update([$field => $changed]), [1644]);
            $this->sqlRejected(fn () => DB::table('supplier_products')->where('id', $row['supplier_product_id'])->update([$field => null]), [1644]);
            $this->assertSame($before, (array) DB::table('supplier_products')->find($row['supplier_product_id']));
        }
        foreach (['price' => '1.01', 'supplier_price_raw' => '2.01', 'recommended_price' => '3.01', 'quantity' => 5, 'availability_status_id' => null, 'raw_data' => '{"a":2}', 'received_at' => '2026-10-18 00:00:01'] as $field => $changed) {
            $this->sqlRejected(fn () => DB::table('supplier_products')->where('id', $row['supplier_product_id'])->update([$field => $changed]), [1644]);
            $this->assertSame($before, (array) DB::table('supplier_products')->find($row['supplier_product_id']));
        }
        DB::table('supplier_products')->where('id', $row['supplier_product_id'])->update([...$strings, ...$typed, 'price' => '1.000', 'raw_data' => '{ "a" : 1 }']);
        $this->assertSame($before, (array) DB::table('supplier_products')->find($row['supplier_product_id']));
        $this->record('A4-A5', ['guardedStrings' => array_keys($strings), 'guardedTypedFields' => array_keys($typed), 'storedEquivalentNoop' => true]);
    }

    public function test_inherited_actions_and_independent_attribute_changes_are_explicit_structural_limits(): void
    {
        $row = $this->revisionFixture('bound-actions');
        $availability = DB::table('availability_statuses')->insertGetId(['code' => 'p007-actions', 'name' => 'synthetic']);
        DB::table('supplier_products')->where('id', $row['supplier_product_id'])->update(['availability_status_id' => $availability]);
        $id = DB::table(self::REVISIONS)->insertGetId($row);
        $this->publish($row, $id);
        $this->sqlRejected(fn () => DB::table('supplier_feeds')->where('id', $row['supplier_feed_id'])->delete(), [1451]);
        $this->sqlRejected(fn () => DB::table('suppliers')->where('id', $row['supplier_id'])->delete(), [1451]);
        DB::table('availability_statuses')->where('id', $availability)->delete();
        $this->assertNull(DB::table('supplier_products')->where('id', $row['supplier_product_id'])->value('availability_status_id'));
        $pid = DB::table('supplier_products')->where('id', $row['supplier_product_id'])->value('product_id');
        DB::table('products')->where('id', $pid)->delete();
        $this->assertNull(DB::table('supplier_products')->where('id', $row['supplier_product_id'])->value('product_id'));
        $attribute = DB::table('supplier_product_attributes')->insertGetId(['supplier_product_id' => $row['supplier_product_id'], 'source_type' => 'synthetic', 'raw_name' => 'colour', 'raw_value' => 'red']);
        DB::table('supplier_product_attributes')->where('id', $attribute)->update(['raw_value' => 'blue']);
        $this->assertSame('blue', DB::table('supplier_product_attributes')->where('id', $attribute)->value('raw_value'));
        $this->assertSame($id, DB::table('supplier_products')->where('id', $row['supplier_product_id'])->value('current_source_revision_id'));
        $legacyFeed = SupplierFeed::factory()->create();
        $legacy = SupplierProduct::query()->create(['supplier_id' => $legacyFeed->supplier_id, 'supplier_feed_id' => $legacyFeed->id, 'supplier_sku' => 'legacy-delete', 'raw_data' => [], 'payload_hash' => str_repeat('a', 64), 'received_at' => now()]);
        DB::table('supplier_feeds')->where('id', $legacyFeed->id)->delete();
        $this->assertNull($legacy->fresh()->supplier_feed_id);
        $legacy->update(['name' => 'legacy mutable without feed', 'supplier_sku' => 'legacy-changed']);
        $this->assertSame('legacy mutable without feed', $legacy->fresh()->name);
        DB::table('suppliers')->where('id', $legacyFeed->supplier_id)->delete();
        $this->assertNull($legacy->fresh());
        $this->record('A6-D3', ['availabilitySetNullBypassesUpdateTrigger' => true, 'independentAttributesNotGuarded' => true, 'semanticAdmission' => 'NOT IMPLEMENTED']);
    }

    public function test_down_authority_unknown_objects_inbound_dependencies_and_wrong_enum_refuse_without_ddl(): void
    {
        $this->zeroDdl('phase_three_p0_downgrade_not_authorized', fn () => $this->migration()->down());
        $environment = app()->environment();
        app()->instance('env', 'production');
        try {
            $this->zeroDdl('phase_three_p0_downgrade_not_authorized', fn () => $this->down());
        } finally {
            app()->instance('env', $environment);
        }
        $this->zeroDdl('phase_three_p0_step_not_terminal', function (): void {
            $this->authorize();
            P0::runTerminalDowngradeStep(P0MigrationStep::P0_06);
        });
        DB::statement('CREATE TABLE p007_external (id bigint unsigned PRIMARY KEY, revision_id bigint unsigned NULL, FOREIGN KEY (revision_id) REFERENCES supplier_product_source_revisions(id))');
        try {
            $this->zeroDdl('phase_three_p0_pointer_has_inbound_dependencies', fn () => $this->down());
        } finally {
            DB::statement('DROP TABLE p007_external');
        }
        DB::statement('ALTER TABLE supplier_products ADD COLUMN p007_unknown int NULL');
        try {
            $this->zeroDdl('UNCLASSIFIED_P0_SCHEMA_STATE', fn () => $this->down());
        } finally {
            DB::statement('ALTER TABLE supplier_products DROP COLUMN p007_unknown');
        }
        $this->down();
        DB::statement('ALTER TABLE supplier_product_source_revisions DROP FOREIGN KEY fk_supplier_product_revision_product_id');
        $this->zeroDdl('phase_three_p0_forward_precondition_failed', fn () => $this->migration()->up());
    }

    public function test_each_partial_boundary_requires_fresh_authority_and_resumes_only_remaining_operations(): void
    {
        for ($boundary = 1; $boundary <= 3; $boundary++) {
            $trace = $this->observe(fn () => $this->failure('phase_three_p0_downgrade_ddl_failed', fn () => $this->down()), ['before' => $boundary + 1]);
            $this->assertCount($boundary, $trace->completed);
            $this->assertEndpoint('P7_DOWN_'.$boundary);
            $this->zeroDdl('phase_three_p0_downgrade_not_authorized', fn () => $this->migration()->down());
            $this->zeroDdl('phase_three_p0_step_not_terminal', function (): void {
                $this->authorize();
                P0::runTerminalDowngradeStep(P0MigrationStep::P0_06);
            });
            $resume = $this->observe(fn () => $this->down());
            $this->assertCount(4 - $boundary, $resume->completed);
            foreach ($resume->completed as $i => $sql) {
                $this->assertSame(Oracle::operation('P0-07-DOWN-0'.($boundary + $i + 1))['sql'], $sql);
            }
            $this->assertEndpoint('P6');
            $this->record('A9-resume', ['boundary' => $boundary, 'interruption' => $trace, 'resume' => $resume]);
            $this->migration()->up();
        }
    }

    public function test_down_unknown_acknowledgement_after_each_operation_never_retries_and_keeps_primary_evidence(): void
    {
        for ($boundary = 1; $boundary <= 4; $boundary++) {
            Log::spy();
            $trace = $this->observe(fn () => $this->failure('phase_three_p0_connection_outcome_unknown', fn () => $this->down()), ['after' => $boundary, 'release' => 'throw']);
            $this->assertCount($boundary, $trace->attempted);
            $this->assertCount($boundary, $trace->completed);
            $this->assertSame(1, $trace->connections);
            Log::shouldHaveReceived('info')->withArgs(fn ($event, $e) => $event === 'phase_three_p0_downgrade_evidence_v1'
                && $e['primary_outcome'] === 'phase_three_p0_connection_outcome_unknown'
                && count($e['completed_ddl_ids']) === $boundary - 1
                && $e['connection_status'] === 'UNCERTAIN'
                && in_array('phase_three_p0_schema_guard_release_unavailable', $e['secondary_codes'], true))->once();
            $this->assertEndpoint($boundary === 4 ? 'P6' : 'P7_DOWN_'.$boundary);
            $this->record('A9-unknown', ['boundary' => $boundary, 'trace' => $trace]);
            // A separate explicit authorized recovery after independent inspection.
            if ($boundary < 4) {
                $this->down();
            }
            $this->migration()->up();
        }
    }

    public function test_forward_interruptions_stop_after_each_committed_statement_without_automatic_continuation(): void
    {
        $this->down();
        foreach ([1 => 'P7_DOWN_3', 2 => 'P7_DOWN_2', 3 => 'P7_DOWN_1', 4 => 'P7'] as $boundary => $expected) {
            $trace = $this->observe(fn () => $this->failure('phase_three_p0_connection_outcome_unknown', fn () => $this->migration()->up()), ['after' => $boundary]);
            $this->assertCount($boundary, $trace->attempted);
            $this->assertCount($boundary, $trace->completed);
            $this->assertSame(1, $trace->connections);
            $this->assertEndpoint($expected);
            $this->zeroDdl('phase_three_p0_forward_precondition_failed', fn () => $this->migration()->up());
            $this->record('A10-forward-stop', ['boundary' => $boundary, 'trace' => $trace]);
            $this->down();
        }
    }

    public function test_session_restore_release_and_inspection_faults_preserve_primary_and_verified_state(): void
    {
        $this->down();
        foreach (['capture_enabled', 'protected_generation_admission_enabled', 'recovery_issuance_enabled', 'recovery_execution_enabled', 'monitor_schedule_enabled', 'observer_schedule_enabled', 'alert_delivery_enabled'] as $gate) {
            $key = 'supplier_snapshot_capture.'.$gate;
            $before = config($key);
            config()->set($key, true);
            try {
                $this->zeroDdl('phase_three_p0_protected_gate_enabled', fn () => $this->migration()->up());
                $this->zeroDdl('phase_three_p0_protected_gate_enabled', fn () => $this->down());
            } finally {
                config()->set($key, $before);
            }
        }
        $this->zeroDdl('phase_three_p0_schema_guard_unavailable', fn () => $this->migration()->up(), ['guard' => true]);
        $this->zeroDdl('phase_three_p0_schema_inspection_unavailable', fn () => $this->migration()->up(), ['inspection' => true]);
        $this->zeroDdl('phase_three_p0_connection_lost_pre_ddl', fn () => $this->migration()->up(), ['preDdl' => true]);
        $trace = $this->observe(fn () => $this->failure('phase_three_p0_forward_ddl_failed', fn () => $this->migration()->up()), ['before' => 1, 'restore' => true, 'release' => 'throw']);
        $this->assertCount(0, $trace->completed);
        $this->assertEndpoint('P6');
        $trace = $this->observe(fn () => $this->migration()->up(), ['release' => 'zero', 'nested' => true]);
        $this->assertCount(4, $trace->completed);
        $this->assertSame('phase_three_p0_nested_downgrade_forbidden', $trace->nestedError);
        $this->assertEndpoint('P7');
        Log::spy();
        $trace = $this->observe(fn () => $this->down(), ['release' => 'null']);
        Log::shouldHaveReceived('info')->withArgs(fn ($event, $e) => $event === 'phase_three_p0_downgrade_evidence_v1'
            && $e['primary_outcome'] === 'phase_three_p0_downgrade_succeeded' && $e['last_verified_state'] === 'P6'
            && in_array('phase_three_p0_schema_guard_release_unavailable', $e['secondary_codes'], true))->once();
        $this->assertEndpoint('P6');
        $this->record('A9-cleanup', ['trace' => $trace]);
    }

    public function test_real_competing_processes_enforce_mutex_and_stale_cas_rolls_back_its_entire_unit(): void
    {
        $this->assertTrue(extension_loaded('pcntl'));
        $this->assertTrue(function_exists('proc_open'));
        $this->withProcessLifecycle(function (array &$children, string $directory): void {
            $connection = <<<'PHP'
        set_exception_handler(static function (Throwable $error): never {
            fwrite(STDERR, 'P0-07 child failure: '.get_class($error).PHP_EOL);
            exit(70);
        });
        $pdo = new PDO(getenv('P007_CHILD_DSN'), getenv('P007_CHILD_USER'), getenv('P007_CHILD_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        PHP;
            $barrier = <<<'PHP'
        function waitFile(string $file): void {
            $deadline = microtime(true) + 30;
            while (! is_file($file)) {
                if (microtime(true) > $deadline) { throw new RuntimeException('barrier timeout'); }
                usleep(10000);
            }
        }
        PHP;
            $mutex = '<?php '.$connection.$barrier.<<<'PHP'
        $ready = $argv[1];
        if ((int) $pdo->query("SELECT GET_LOCK('mycomputer:phase-iii-p0-schema-ddl-v1', 0)")->fetchColumn() !== 1) { exit(3); }
        file_put_contents($ready, json_encode(['pid' => getmypid(), 'connectionId' => $pdo->query('SELECT CONNECTION_ID()')->fetchColumn()]));
        waitFile($ready.'.release');
        if ((int) $pdo->query("SELECT RELEASE_LOCK('mycomputer:phase-iii-p0-schema-ddl-v1')")->fetchColumn() !== 1) { exit(4); }
        PHP;
            $this->assertNotFalse(file_put_contents($directory.'/mutex.php', $mutex));
            $mutexId = $this->startProcessChild($children, $directory, 'mutex', 'mutex.php', [$directory.'/mutex-ready']);
            $this->waitFile($directory.'/mutex-ready');
            $this->zeroDdl('phase_three_p0_schema_guard_unavailable', fn () => $this->down());
            $this->assertSame(7, file_put_contents($directory.'/mutex-ready.release', 'release'));
            $this->assertSame(0, $this->finishProcessChild($children[$mutexId]));
            $a = $this->revisionFixture('race');
            $aid = DB::table(self::REVISIONS)->insertGetId($a);
            $this->publish($a, $aid);
            $b = $this->revisionFixture('race', $a);
            $c = $this->revisionFixture('race', $a);
            $script = '<?php '.$connection.$barrier.<<<'PHP'
        $input = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
        file_put_contents($argv[1].'.ready', 'ready');
        waitFile($argv[2]);
        $row = $input['row'];
        $pdo->beginTransaction();
        // Test-only caller lock order. No production writer or retry is introduced.
        $pdo->query('SELECT id FROM supplier_product_identity_heads WHERE id='.(int) $row['supplier_product_identity_head_id'].' FOR UPDATE')->fetchColumn();
        $pdo->query('SELECT id FROM supplier_products WHERE id='.(int) $row['supplier_product_id'].' FOR UPDATE')->fetchColumn();
        $insert = $pdo->prepare('INSERT INTO supplier_product_source_revisions ('.implode(',', array_keys($row)).') VALUES ('.implode(',', array_fill(0, count($row), '?')).')');
        $insert->execute(array_values($row));
        $revision = (int) $pdo->lastInsertId();
        $update = $pdo->prepare('UPDATE supplier_products SET current_source_revision_id=? WHERE id=? AND supplier_product_identity_head_id=? AND current_source_revision_id=?');
        $update->execute([$revision, $row['supplier_product_id'], $row['supplier_product_identity_head_id'], $input['old']]);
        $affected = $update->rowCount();
        if ($affected === 1) { $pdo->commit(); } else { $pdo->rollBack(); }
        file_put_contents($argv[1].'.result', json_encode(['affected' => $affected, 'revision' => $revision, 'rolledBack' => $affected === 0, 'connectionId' => $pdo->query('SELECT CONNECTION_ID()')->fetchColumn()], JSON_THROW_ON_ERROR));
        PHP;
            $this->assertSame(strlen($script), file_put_contents($directory.'/cas.php', $script));
            $casChildren = [];
            foreach ([$b, $c] as $i => $row) {
                $file = $directory.'/cas-'.$i.'.json';
                $this->assertNotFalse(file_put_contents($file, json_encode(['row' => $row, 'old' => $aid], JSON_THROW_ON_ERROR)));
                $casChildren[] = $this->startProcessChild($children, $directory, 'cas-'.$i, 'cas.php', [$file, $directory.'/go']);
            }
            foreach ([0, 1] as $i) {
                $this->waitFile($directory.'/cas-'.$i.'.json.ready');
            }
            $this->assertSame(2, file_put_contents($directory.'/go', 'go'));
            $exits = [];
            foreach ($casChildren as $id) {
                $exits[] = $this->finishProcessChild($children[$id]);
            }
            foreach ($exits as $exit) {
                $this->assertSame(0, $exit);
            }
            $results = [];
            foreach ([0, 1] as $i) {
                $result = json_decode(file_get_contents($directory.'/cas-'.$i.'.json.result'), true, flags: JSON_THROW_ON_ERROR);
                $results[] = $result;
                $this->assertSame($result['affected'], DB::table(self::REVISIONS)->where('id', $result['revision'])->count());
                $this->assertSame($result['affected'] === 0, $result['rolledBack']);
            }
            $affected = array_column($results, 'affected');
            sort($affected);
            $this->assertSame([0, 1], $affected);
            $this->assertNotSame($results[0]['connectionId'], $results[1]['connectionId']);
            $this->assertSame(2, DB::table(self::REVISIONS)->count());
            $this->record('A10-real-processes', ['mutexHolder' => json_decode(file_get_contents($directory.'/mutex-ready'), true), 'cas' => $results, 'productionWriter' => 'NOT IMPLEMENTED']);
        });
    }

    public function test_child_process_failures_reap_every_child_remove_owned_files_and_preserve_primary_error(): void
    {
        foreach ([1, 2, 'first-child-exit'] as $scenario) {
            $primary = new RuntimeException('controlled failure after child start');
            $report = [];
            try {
                $this->withProcessLifecycle(function (array &$children, string $directory) use ($scenario, $primary): void {
                    $script = '<?php file_put_contents($argv[1], "ready"); if (($argv[2] ?? "") === "exit") { exit(9); } while (true) { usleep(10000); }';
                    $this->assertSame(strlen($script), file_put_contents($directory.'/failure.php', $script));
                    $count = $scenario === 1 ? 1 : 2;
                    for ($i = 0; $i < $count; $i++) {
                        $this->startProcessChild($children, $directory, 'failure-'.$i, 'failure.php', [$directory.'/ready-'.$i, $scenario === 'first-child-exit' && $i === 0 ? 'exit' : 'wait']);
                        $this->waitFile($directory.'/ready-'.$i);
                    }
                    if ($scenario === 'first-child-exit') {
                        $exit = $this->finishProcessChild($children[0]);
                        $this->assertSame(0, $exit, 'Controlled first child exit');
                    }
                    throw $primary;
                }, $report);
                $this->fail('Controlled child failure was not propagated');
            } catch (\Throwable $error) {
                if ($scenario === 'first-child-exit') {
                    $this->assertInstanceOf(AssertionFailedError::class, $error);
                    $this->assertStringContainsString('Controlled first child exit', $error->getMessage());
                } else {
                    $this->assertSame($primary, $error);
                }
            }
            $this->assertTrue($report['directoryRemoved']);
            $this->assertSame([], $report['cleanupErrors']);
            $this->assertCount($scenario === 1 ? 1 : 2, $report['children']);
            foreach ($report['children'] as $child) {
                $this->assertFalse($child['running']);
                $this->assertFalse($child['pidAliveAfterReap']);
                $this->assertTrue($child['reaped']);
            }
            $this->record('A10-controlled-failure', ['scenario' => $scenario, 'lifecycle' => $report]);
        }
    }

    /** Own every child from proc_open through reap, including failures during setup. */
    private function withProcessLifecycle(callable $operation, array &$report = []): void
    {
        $directory = sys_get_temp_dir().'/p007-process-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($directory, 0700));
        $ownedPath = realpath($directory);
        $children = [];
        $primary = null;
        $cleanupErrors = [];
        try {
            $operation($children, $directory);
        } catch (\Throwable $error) {
            $primary = $error;
        } finally {
            // No assertion can interrupt cleanup of a later child.
            foreach ($children as &$child) {
                try {
                    if (is_resource($child['process'])) {
                        $this->finishProcessChild($child, terminate: true);
                    }
                } catch (\Throwable $error) {
                    $cleanupErrors[] = ['step' => 'reap', 'class' => get_class($error)];
                }
            }
            unset($child);
            $records = [];
            foreach ($children as $child) {
                $record = array_diff_key($child, ['process' => true]);
                foreach (['stdout', 'stderr'] as $stream) {
                    try {
                        $record[$stream] = file_get_contents($child[$stream]);
                        if ($record[$stream] === false) {
                            throw new RuntimeException('Child output unavailable');
                        }
                    } catch (\Throwable $error) {
                        $cleanupErrors[] = ['step' => 'read-'.$stream, 'class' => get_class($error)];
                    }
                }
                $records[] = $record;
            }
            // Never unlink files still in use by an unconfirmed child.
            if (! array_filter($children, fn ($child) => is_resource($child['process']))) {
                try {
                    if ($ownedPath === false || realpath($directory) !== $ownedPath || is_link($directory)) {
                        throw new RuntimeException('Owned process directory identity changed');
                    }
                    foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $file) {
                        if ((! $file->isFile() && ! $file->isLink()) || ! unlink($file->getPathname())) {
                            throw new RuntimeException('Owned process file cleanup failed');
                        }
                    }
                    if (! rmdir($directory)) {
                        throw new RuntimeException('Owned process directory cleanup failed');
                    }
                } catch (\Throwable $error) {
                    $cleanupErrors[] = ['step' => 'remove-owned-directory', 'class' => get_class($error)];
                }
            }
            $report = ['directory' => $directory, 'directoryRemoved' => ! file_exists($directory),
                'children' => $records, 'cleanupErrors' => $cleanupErrors,
                'primaryClass' => $primary === null ? null : get_class($primary),
                'credentials' => 'child environment only; never persisted'];
            try {
                $this->record('A10-lifecycle', $report);
            } catch (\Throwable $error) {
                $cleanupErrors[] = ['step' => 'record', 'class' => get_class($error)];
            }
        }
        if ($primary !== null) {
            throw $primary;
        }
        $this->assertSame([], $cleanupErrors, 'Child lifecycle cleanup failed');
        $this->assertTrue($report['directoryRemoved']);
        foreach ($records as $child) {
            $this->assertFalse($child['running']);
            $this->assertFalse($child['pidAliveAfterReap']);
            $this->assertTrue($child['reaped']);
        }
    }

    private function startProcessChild(array &$children, string $directory, string $name, string $script, array $arguments): int
    {
        $c = config('database.connections.'.DB::getDefaultConnection());
        $environment = ['P007_CHILD_DSN' => 'mysql:host='.$c['host'].';port='.$c['port'].';dbname='.$c['database'].';charset=utf8mb4',
            'P007_CHILD_USER' => (string) $c['username'], 'P007_CHILD_PASSWORD' => (string) $c['password']];
        $command = [PHP_BINARY, '-d', 'zend.exception_ignore_args=1', $directory.'/'.$script, ...$arguments];
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $directory.'/'.$name.'.out', 'w'], 2 => ['file', $directory.'/'.$name.'.err', 'w']], $pipes, $directory, $environment);
        if (! is_resource($process)) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            throw new RuntimeException('Child process creation failed');
        }
        $id = count($children);
        $children[$id] = ['process' => $process, 'command' => $command, 'pid' => null,
            'stdout' => $directory.'/'.$name.'.out', 'stderr' => $directory.'/'.$name.'.err',
            'running' => true, 'reaped' => false, 'exit' => null, 'close' => null, 'pidAliveAfterReap' => null];
        $status = proc_get_status($process);
        $children[$id]['pid'] = $status['pid'];
        if (! fclose($pipes[0])) {
            throw new RuntimeException('Child stdin close failed');
        }

        return $id;
    }

    private function finishProcessChild(array &$child, bool $terminate = false): int
    {
        $process = $child['process'];
        $status = proc_get_status($process);
        if ($terminate && $status['running']) {
            proc_terminate($process);
        }
        $deadline = microtime(true) + ($terminate ? 2 : 30);
        while ($status['running'] && microtime(true) < $deadline) {
            usleep(10000);
            $status = proc_get_status($process);
        }
        if ($terminate && $status['running']) {
            proc_terminate($process, 9);
            $deadline = microtime(true) + 2;
            do {
                usleep(10000);
                $status = proc_get_status($process);
            } while ($status['running'] && microtime(true) < $deadline);
        }
        if ($status['running']) {
            throw new RuntimeException('Child process did not stop within deadline');
        }
        $child['running'] = false;
        $child['exit'] = $status['exitcode'];
        $child['signaled'] = $status['signaled'];
        $child['termsig'] = $status['termsig'];
        $child['close'] = proc_close($process);
        $child['process'] = null;
        $child['reaped'] = true;
        $child['pidAliveAfterReap'] = posix_kill($child['pid'], 0);

        return $child['close'];
    }

    private function waitFile(string $file): void
    {
        $deadline = microtime(true) + 30;
        while (! is_file($file)) {
            if (microtime(true) > $deadline) {
                $this->fail('Child process barrier timed out: '.basename($file));
            }
            usleep(10000);
        }
    }

    public function test_real_xml_and_csv_legacy_import_paths_leave_pointers_null_and_bound_xml_fails(): void
    {
        Http::preventStrayRequests();
        $row = $this->revisionFixture('P007-XML');
        $feed = SupplierFeed::findOrFail($row['supplier_feed_id']);
        $url = 'https://feeds.example.test/p007.xml';
        $feed->update(['feed_url' => $url]);
        $template = XmlMappingTemplate::factory()->create(['supplier_id' => $feed->supplier_id]);
        $original = app(SsrfProtectionService::class);
        $this->app->instance(SsrfProtectionService::class, new ExactSyntheticFeedSsrfProtectionService($url));
        $job = fn () => ImportJob::query()->create(['supplier_id' => $feed->supplier_id, 'supplier_feed_id' => $feed->id, 'xml_mapping_template_id' => $template->id, 'type' => 'xml', 'mode' => 'manual', 'status' => 'pending']);
        $catalog = DB::table('products')->get()->toJson();
        $xmlPayload = '';
        Http::fake([$url => static function () use (&$xmlPayload) {
            return Http::response($xmlPayload);
        }]);
        try {
            foreach (['first', 'second'] as $name) {
                $xmlPayload = '<products><product><code>P007-XML</code><name>'.$name.'</name><price>1</price><stock>2</stock></product><product><code>new</code><name>new</name></product><product><code></code><name>invalid</name></product></products>';
                $import = app(XmlImportEngine::class)->import($job());
                $this->assertSame('completed_with_errors', $import->status);
                $this->assertSame($name, DB::table('supplier_products')->where('id', $row['supplier_product_id'])->value('name'));
                $this->assertSame(0, DB::table('supplier_products')->whereNotNull('current_source_revision_id')->count());
            }
            $rid = DB::table(self::REVISIONS)->insertGetId($row);
            $this->publish($row, $rid);
            $before = (array) DB::table('supplier_products')->find($row['supplier_product_id']);
            $xmlPayload = '<products><product><code>P007-XML</code><name>changed-bound</name></product></products>';
            $failedJob = $job();
            try {
                app(XmlImportEngine::class)->import($failedJob);
                $this->fail('Bound source mutation incorrectly reported import success.');
            } catch (QueryException $error) {
                $this->assertSame(1644, (int) $error->errorInfo[1]);
            }
            $this->assertSame('failed', $failedJob->fresh()->status);
            $this->assertSame($before, (array) DB::table('supplier_products')->find($row['supplier_product_id']));
        } finally {
            $this->app->instance(SsrfProtectionService::class, $original);
        }
        $csvUrl = 'https://feeds.example.test/p007.csv';
        $csvFeed = SupplierFeed::factory()->create(['feed_type' => 'csv', 'feed_url' => $csvUrl, 'mapping' => ['sku' => 'sku', 'name' => 'name', 'price' => 'price', 'quantity' => 'quantity']]);
        Http::fake([$csvUrl => Http::response("sku,name,price,quantity\nP007-CSV,CSV row,5,2\n,invalid,5,2\n")]);
        $this->app->instance(SsrfProtectionService::class, new ExactSyntheticFeedSsrfProtectionService($csvUrl));
        try {
            $job = ImportJob::query()->create(['supplier_id' => $csvFeed->supplier_id, 'supplier_feed_id' => $csvFeed->id, 'type' => 'csv', 'mode' => 'manual', 'status' => 'pending']);
            app(SupplierCsvFeedImportService::class)->import($csvFeed, $job);
            $this->assertSame('completed_with_errors', $job->fresh()->status);
            $csv = DB::table('supplier_products')->where('supplier_sku', 'P007-CSV')->sole();
            $this->assertNull($csv->current_source_revision_id);
            $this->assertNull($csv->supplier_product_identity_head_id);
        } finally {
            $this->app->instance(SsrfProtectionService::class, $original);
        }
        $this->assertSame($catalog, DB::table('products')->get()->toJson());
        // Actual APCOM parser/template coverage is additionally run unchanged in ApcomSupplierImportTest.
        $this->record('A7-import', ['xmlCreateUpdate' => true, 'csvCreate' => true, 'boundXmlRejected' => true, 'catalogUnchanged' => true]);
    }

    public function test_real_connection_loss_after_committed_ddl_stops_without_hidden_reconnect(): void
    {
        Log::spy();
        $trace = $this->observe(fn () => $this->failure('phase_three_p0_connection_outcome_unknown', fn () => $this->down()), ['killAfter' => 1]);
        $this->assertCount(1, $trace->attempted);
        $this->assertCount(1, $trace->completed);
        $this->assertSame(1, $trace->connections);
        $this->assertEndpoint('P7_DOWN_1');
        Log::shouldHaveReceived('info')->withArgs(fn ($event, $e) => $event === 'phase_three_p0_downgrade_evidence_v1'
            && $e['completed_ddl_ids'] === ['P0-07-DOWN-01'] && $e['last_verified_state'] === 'P7'
            && $e['primary_outcome'] === 'phase_three_p0_connection_outcome_unknown'
            && $e['connection_status'] === 'UNCERTAIN')->once();
        $resume = $this->observe(fn () => $this->down());
        $this->assertCount(3, $resume->completed);
        $this->assertNotSame($trace->connectionId, $resume->connectionId);
        $this->assertEndpoint('P6');
        $this->record('A9-real-connection-loss', ['interrupted' => $trace, 'separateAuthorizedResume' => $resume]);
    }

    public function test_each_pre_statement_failure_and_partial_metadata_drift_refuse_unverified_progress(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $trace = $this->observe(fn () => $this->failure('phase_three_p0_downgrade_ddl_failed', fn () => $this->down()), ['before' => $i]);
            $this->assertCount($i - 1, $trace->completed);
            $this->assertEndpoint($i === 1 ? 'P7' : 'P7_DOWN_'.($i - 1));
            DB::statement('ALTER TABLE supplier_products ADD COLUMN p007_unknown int NULL');
            try {
                $this->zeroDdl('UNCLASSIFIED_P0_SCHEMA_STATE', fn () => $this->down());
            } finally {
                DB::statement('ALTER TABLE supplier_products DROP COLUMN p007_unknown');
            }
            $this->down();
            $this->assertEndpoint('P6');
            $this->migration()->up();
        }
        $this->down();
        for ($i = 1; $i <= 4; $i++) {
            $trace = $this->observe(fn () => $this->failure('phase_three_p0_forward_ddl_failed', fn () => $this->migration()->up()), ['before' => $i]);
            $this->assertCount($i - 1, $trace->completed);
            $this->assertEndpoint(['P6', 'P7_DOWN_3', 'P7_DOWN_2', 'P7_DOWN_1'][$i - 1]);
            if ($i > 1) {
                $this->zeroDdl('phase_three_p0_forward_precondition_failed', fn () => $this->migration()->up());
                $this->down();
            }
        }
    }

    public function test_frozen_successor_schema_fixtures_are_never_downgraded_by_the_p007_step(): void
    {
        // Test-only empty suffix fixtures from the already approved immutable registry.
        // This does not dispatch or implement the P0-08/P0-09 migrations.
        foreach (['supplier_import_execution_claims', 'supplier_offer_snapshot_generations'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        $before = 'P7';
        foreach (['P8' => '4a8e646732f220aa1403acbb13a8eb67a3fa2845aaff31acd48e937919c98f48', 'P9' => '16e1dcd6521b19d31200a010fd532178e3684eacbc23907da8edabb630e31eeb'] as $state => $hash) {
            $this->installEmptySuffixFixture($before, $state);
            $actual = P0::classify(DB::connection()->getPdo());
            $this->assertSame($state, $actual['state']);
            $this->assertSame($hash, $actual['sha256']);
            $this->zeroDdl('phase_three_p0_step_not_terminal', fn () => $this->down());
            $this->zeroDdl('phase_three_p0_forward_precondition_failed', fn () => $this->migration()->up());
            $this->record('A8-suffix', ['state' => $actual, 'fixtureOnly' => true]);
            $before = $state;
        }
    }

    private function installEmptySuffixFixture(string $before, string $after): void
    {
        $old = Oracle::signaturesFor($before);
        $new = Oracle::signaturesFor($after);
        $quote = static fn (string $name): string => chr(96).str_replace(chr(96), chr(96).chr(96), $name).chr(96);
        $columns = $alter = $triggers = [];
        foreach ($new as $id => $record) {
            if (($old[$id]['sha256'] ?? null) === $record['sha256']) {
                continue;
            }
            $s = $record['signature'];
            $table = $s['table'] ?? $s['child_table'] ?? '';
            $this->assertContains($table, ['supplier_import_execution_claims', 'supplier_offer_snapshot_generations']);
            $name = $quote($s['name']);
            switch ($s['type']) {
                case 'column':
                    $this->assertArrayNotHasKey($id, $old);
                    $this->assertSame('SQL_NULL', $s['default_kind']);
                    $columns[$table][$s['ordinal']] = 'ADD COLUMN '.$name.' '.$s['column_type']
                        .($s['charset'] === null ? '' : ' CHARACTER SET '.$s['charset'].' COLLATE '.$s['collation'])
                        .($s['nullable'] ? ' NULL DEFAULT NULL' : ' NOT NULL');
                    break;
                case 'index':
                    $this->assertArrayNotHasKey($id, $old);
                    $parts = [];
                    foreach ($s['parts'] as $part) {
                        $this->assertNull($part['expression']);
                        $this->assertNull($part['sub_part']);
                        $parts[] = $quote($part['column']);
                    }
                    $alter[$table][] = 'ADD '.($s['non_unique'] === 0 ? 'UNIQUE ' : '').'KEY '.$name.' ('.implode(', ', $parts).')';
                    break;
                case 'check':
                    if (isset($old[$id])) {
                        $alter[$table][] = 'DROP CHECK '.$name;
                    }
                    // information_schema serializes quoted CHECK literals with escaped quotes.
                    $alter[$table][] = 'ADD CONSTRAINT '.$name.' CHECK ('.str_replace("\\'", "'", $s['clause']).')';
                    break;
                case 'foreign_key':
                    $this->assertArrayNotHasKey($id, $old);
                    $alter[$table][] = 'ADD CONSTRAINT '.$name.' FOREIGN KEY ('.implode(', ', array_map($quote, $s['child_columns'])).') REFERENCES '.$quote($s['parent_table']).' ('.implode(', ', array_map($quote, $s['parent_columns'])).') ON UPDATE '.$s['update_rule'].' ON DELETE '.$s['delete_rule'];
                    break;
                case 'trigger':
                    $this->assertArrayNotHasKey($id, $old);
                    $triggers[] = $s;
                    break;
                default:
                    $this->fail('Unexpected suffix fixture object: '.$id);
            }
        }
        $pdo = DB::connection()->getPdo();
        $settings = $pdo->query('SELECT @@SESSION.sql_mode AS sql_mode, @@SESSION.character_set_client AS character_set_client, @@SESSION.character_set_results AS character_set_results, @@SESSION.collation_connection AS collation_connection')->fetch(PDO::FETCH_ASSOC);
        try {
            $pdo->exec("SET SESSION sql_mode='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION', character_set_client='cp866', character_set_results='cp866', collation_connection='cp866_general_ci'");
            foreach ($columns as $table => $definitions) {
                ksort($definitions);
                $pdo->exec('ALTER TABLE '.$quote($table).' '.implode(', ', $definitions));
            }
            foreach ($alter as $table => $definitions) {
                $pdo->exec('ALTER TABLE '.$quote($table).' '.implode(', ', $definitions));
            }
            foreach ($triggers as $s) {
                $pdo->exec('CREATE TRIGGER '.$quote($s['name']).' '.$s['timing'].' '.$s['event'].' ON '.$quote($s['table']).' FOR EACH ROW '.$s['action_statement']);
            }
        } finally {
            foreach ($settings as $key => $value) {
                $pdo->exec('SET SESSION '.$key.'='.$pdo->quote($value));
            }
        }
    }

    public function test_actual_apcom_template_import_keeps_pair_null_and_does_not_write_catalog(): void
    {
        $this->seed();
        $supplier = Supplier::query()->where('slug', 'apcom')->sole();
        $feed = SupplierFeed::query()->where('supplier_id', $supplier->id)->where('feed_name', 'APCOM XML Product Feed')->sole();
        $template = XmlMappingTemplate::query()->where('supplier_id', $supplier->id)->where('name', 'APCOM XML Product Mapping')->sole();
        $url = 'https://feeds.example.test/p007-apcom.xml';
        $feed->update(['feed_url' => $url]);
        $original = app(SsrfProtectionService::class);
        $this->app->instance(SsrfProtectionService::class, new ExactSyntheticFeedSsrfProtectionService($url));
        Http::preventStrayRequests();
        $catalog = DB::table('products')->get()->toJson();
        $xmlPayload = '';
        Http::fake([$url => static function () use (&$xmlPayload) {
            return Http::response($xmlPayload);
        }]);
        try {
            foreach (['5.00', '6.00'] as $price) {
                $xmlPayload = '<xml><product><partno>P007-APCOM</partno><name>Synthetic APCOM</name><fd_price>'.$price.'</fd_price><stock>2</stock><manufacturer>Apple</manufacturer><Attributes><Attribute Name="RAM Memory" Value="16GB" /></Attributes></product></xml>';
                $job = ImportJob::query()->create(['supplier_id' => $supplier->id, 'supplier_feed_id' => $feed->id, 'xml_mapping_template_id' => $template->id, 'type' => 'xml', 'mode' => 'manual', 'status' => 'pending']);
                app(XmlImportEngine::class)->import($job);
                $this->assertSame('completed', $job->fresh()->status);
                $row = SupplierProduct::query()->where('supplier_sku', 'P007-APCOM')->sole();
                $this->assertSame($price, $row->price);
                $this->assertNull($row->getRawOriginal('supplier_product_identity_head_id'));
                $this->assertNull($row->getRawOriginal('current_source_revision_id'));
                $this->assertSame(1, DB::table('supplier_product_attributes')->where('supplier_product_id', $row->id)->where('raw_name', 'RAM Memory')->count());
            }
        } finally {
            $this->app->instance(SsrfProtectionService::class, $original);
        }
        $this->assertSame($catalog, DB::table('products')->get()->toJson());
        $this->record('A7-apcom', ['actualSeededTemplate' => true, 'createUpdatePairNull' => true, 'catalogUnchanged' => true]);
    }

    public function test_post_ddl_inspection_failure_and_metadata_mismatch_stop_at_the_observed_boundary(): void
    {
        $trace = $this->observe(fn () => $this->failure('phase_three_p0_schema_inspection_unavailable', fn () => $this->down()), ['postInspection' => true]);
        $this->assertCount(1, $trace->completed);
        $this->assertEndpoint('P7_DOWN_1');
        $this->down();
        $this->migration()->up();
        $trace = $this->observe(fn () => $this->failure('phase_three_p0_downgrade_postcondition_failed', fn () => $this->down()), ['driftAfter' => 1]);
        $this->assertCount(1, $trace->completed);
        $this->assertSame('UNCLASSIFIED_P0_SCHEMA_STATE', P0::classify(DB::connection()->getPdo())['state']);
        $this->zeroDdl('UNCLASSIFIED_P0_SCHEMA_STATE', fn () => $this->down());
        // Remove only the explicitly introduced synthetic drift; no automatic migration repair.
        DB::statement('ALTER TABLE supplier_products DROP COLUMN p007_test_drift');
        $this->assertEndpoint('P7_DOWN_1');
        $this->down();
        $trace = $this->observe(fn () => $this->failure('phase_three_p0_schema_session_restore_failed', fn () => $this->migration()->up()), ['restore' => true]);
        $this->assertCount(4, $trace->completed);
        $this->assertEndpoint('P7');
        $this->record('A9-postcondition', ['restoreAfterVerifiedEndpoint' => $trace]);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_28_090006_add_source_revision_pointers_to_supplier_products_table.php');
    }

    private function authorize(): void
    {
        putenv(self::GRANT.'=true');
        $_ENV[self::GRANT] = $_SERVER[self::GRANT] = 'true';
    }

    private function down(): void
    {
        $this->authorize();
        try {
            $this->migration()->down();
        } finally {
            $this->assertFalse(getenv(self::GRANT));
            $this->assertArrayNotHasKey(self::GRANT, $_ENV);
            $this->assertArrayNotHasKey(self::GRANT, $_SERVER);
        }
    }

    private function assertEndpoint(string $state): void
    {
        $observed = P0::classify(DB::connection()->getPdo());
        $this->assertSame([$state, str_contains($state, '_DOWN_') ? 'RECOGNIZED_PARTIAL' : 'NORMAL_PREFIX', ...self::ENDPOINTS[$state]],
            [$observed['state'], $observed['classification'], $observed['object_count'], $observed['sha256']]);
        $expected = array_column(Oracle::signaturesFor($state), 'signature');
        $actual = (new Inspector(DB::connection()->getPdo()))->enumerate();
        $default = DB::scalar('SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE()');
        if (! isset($this->recordedStates[$state])) {
            $this->record('raw-schema-'.$state, ['state' => $observed, 'databaseDefault' => $default, 'rawSignatures' => $actual]);
            $this->recordedStates[$state] = true;
        }
        foreach ($actual as &$signature) {
            if ($signature['type'] === 'trigger') {
                $this->assertSame($default, $signature['database_collation']);
                $signature['database_collation'] = 'ENVIRONMENT_DERIVED_DATABASE_COLLATION';
            }
        }
        unset($signature);
        $this->assertEqualsCanonicalizing($expected, $actual);
    }

    private function publish(array $row, int $revision, array $fields = []): void
    {
        DB::table('supplier_products')->where('id', $row['supplier_product_id'])->update([
            'supplier_product_identity_head_id' => $row['supplier_product_identity_head_id'], 'current_source_revision_id' => $revision, ...$fields,
        ]);
    }

    private function sqlRejected(callable $operation, array $codes = [1062, 1451, 1452, 1644, 3819]): void
    {
        $before = $this->rows(false);
        try {
            $operation();
            $this->fail('Invalid synthetic SQL was accepted.');
        } catch (QueryException $error) {
            $this->assertContains((int) $error->errorInfo[1], $codes);
        }
        $this->assertSame($before, $this->rows(false));
    }

    private function failure(string $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected fail-closed outcome: '.$code);
        } catch (SchemaFailure $error) {
            $this->assertSame($code, $error->primaryCode);
        }
    }

    private function zeroDdl(string $code, callable $operation, array $fault = []): void
    {
        $trace = $this->observe(fn () => $this->failure($code, $operation), $fault);
        $this->assertSame([], $trace->attempted);
        $this->assertSame([], $trace->completed);
        $this->record('zero-ddl', ['code' => $code, 'trace' => $trace]);
    }

    private function observe(callable $operation, array $fault = []): object
    {
        $trace = (object) ['attempted' => [], 'completed' => [], 'queries' => [], 'triggerSessions' => [], 'connections' => 0, 'fault' => $fault];
        DB::extend('phase_three_p0_schema_ddl', static function (array $config, string $name) use ($trace): MySqlConnection {
            $pdo = new class($config, $trace) extends PDO
            {
                private bool $lost = false;

                private int $settings = 0;

                public function __construct(private array $config, private object $trace)
                {
                    parent::__construct('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'].';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => false]);
                    $this->trace->connections++;
                    $this->trace->connectionId = parent::query('SELECT CONNECTION_ID()')->fetchColumn();
                    $this->trace->sessionBefore = $this->session();
                }

                public function exec(string $statement): int|false
                {
                    $ddl = preg_match('/\A(?:CREATE|ALTER|DROP) /i', ltrim($statement)) === 1;
                    if ($ddl) {
                        $this->trace->attempted[] = $statement;
                        if (($this->trace->fault['before'] ?? null) === count($this->trace->attempted)) {
                            $error = new PDOException('synthetic DDL rejection');
                            $error->errorInfo = ['HY000', 1000, 'synthetic'];
                            throw $error;
                        }
                    }
                    if (str_starts_with($statement, 'SET SESSION ')) {
                        $this->settings++;
                        if (($this->trace->fault['restore'] ?? false) && $this->settings > 4) {
                            throw new RuntimeException('synthetic restore failure');
                        }
                    }
                    if (str_starts_with(ltrim($statement), 'CREATE TRIGGER')) {
                        $this->trace->triggerSessions[] = $this->session();
                    }
                    $result = parent::exec($statement);
                    if ($ddl) {
                        $this->trace->completed[] = $statement;
                        if (($this->trace->fault['driftAfter'] ?? null) === count($this->trace->completed)) {
                            parent::exec('ALTER TABLE supplier_products ADD COLUMN p007_test_drift int NULL');
                        }
                        if (($this->trace->fault['killAfter'] ?? null) === count($this->trace->completed)) {
                            $c = $this->config;
                            $killer = new PDO('mysql:host='.$c['host'].';port='.$c['port'].';dbname='.$c['database'], $c['username'], $c['password']);
                            $killer->exec('KILL CONNECTION '.(int) $this->trace->connectionId);
                        }
                        if (($this->trace->fault['after'] ?? null) === count($this->trace->completed)) {
                            $this->lost = true;
                            $this->transport();
                        }
                    }

                    return $result;
                }

                public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
                {
                    $this->trace->queries[] = $query;
                    if (($this->trace->fault['nested'] ?? false) && $query === 'SELECT DATABASE()') {
                        $this->trace->fault['nested'] = false;
                        try {
                            P0::runForwardStep(P0MigrationStep::P0_07);
                        } catch (SchemaFailure $error) {
                            $this->trace->nestedError = $error->primaryCode;
                        }
                    }
                    if ($this->lost) {
                        $this->transport();
                    }
                    if (($this->trace->fault['guard'] ?? false) && str_starts_with($query, 'SELECT GET_LOCK(')) {
                        return parent::query('SELECT NULL');
                    }
                    if (($this->trace->fault['inspection'] ?? false) && $query === 'SELECT DATABASE()') {
                        throw new RuntimeException('synthetic inspection failure');
                    }
                    if (($this->trace->fault['postInspection'] ?? false) && count($this->trace->completed) > 0 && $query === 'SELECT DATABASE()') {
                        throw new RuntimeException('synthetic post-DDL inspection failure');
                    }
                    if (($this->trace->fault['preDdl'] ?? false) && $query === 'SELECT 1') {
                        $this->transport();
                    }
                    if (str_starts_with($query, 'SELECT RELEASE_LOCK(')) {
                        $this->trace->sessionAfter = $this->session();
                        $result = parent::query($query);

                        return match ($this->trace->fault['release'] ?? '') {
                            'zero' => parent::query('SELECT 0'),
                            'null' => parent::query('SELECT NULL'),
                            'throw' => throw new RuntimeException('synthetic release failure'),
                            default => $result,
                        };
                    }

                    try {
                        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
                    } catch (PDOException $error) {
                        $this->trace->pdoErrors[] = ['class' => $error::class, 'sqlstate' => $error->errorInfo[0] ?? $error->getCode(), 'driverCode' => $error->errorInfo[1] ?? null];
                        throw $error;
                    }
                }

                private function session(): array
                {
                    return parent::query('SELECT @@session.sql_mode AS sql_mode, @@session.character_set_client AS character_set_client, @@session.character_set_results AS character_set_results, @@session.collation_connection AS collation_connection')->fetch(PDO::FETCH_ASSOC);
                }

                private function transport(): never
                {
                    $error = new PDOException('synthetic missing DDL acknowledgement');
                    $error->errorInfo = ['08S01', 2013, 'synthetic'];
                    throw $error;
                }
            };

            return new MySqlConnection($pdo, $config['database'], '', [...$config, 'name' => $name]);
        });
        try {
            $operation();
        } finally {
            DB::forgetExtension('phase_three_p0_schema_ddl');
            DB::purge('phase_three_p0_schema_ddl');
            $this->record('observer', ['trace' => $trace]);
        }

        return $trace;
    }

    private function pdo(): PDO
    {
        $c = config('database.connections.'.DB::getDefaultConnection());

        return new PDO('mysql:host='.$c['host'].';port='.$c['port'].';dbname='.$c['database'].';charset=utf8mb4', $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => false]);
    }

    private function rows(bool $oldColumnsOnly = true): array
    {
        $result = [];
        foreach (DB::select("SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME") as $table) {
            $rows = DB::table($table->name)->get()->map(function ($row) use ($oldColumnsOnly, $table): string {
                $values = (array) $row;
                if ($oldColumnsOnly && $table->name === 'supplier_products') {
                    unset($values['supplier_product_identity_head_id'], $values['current_source_revision_id']);
                }
                ksort($values);

                return hash('sha256', serialize($values));
            })->all();
            sort($rows, SORT_STRING);
            $result[$table->name] = $rows;
        }

        return $result;
    }

    private function record(string $name, array $value): void
    {
        $directory = getenv('P007_EVIDENCE_DIR');
        if (! is_string($directory) || $directory === '') {
            return;
        }
        if (! is_dir($directory)) {
            $this->assertTrue(mkdir($directory, 0700, true));
        }
        $file = tempnam($directory, $name.'-');
        $this->assertNotFalse($file);
        $bytes = json_encode(['utc' => gmdate('c'), 'database' => DB::getDatabaseName(), ...$value], JSON_THROW_ON_ERROR);
        $this->assertSame(strlen($bytes), file_put_contents($file, $bytes));
    }

    private function revisionFixture(string $sku, ?array $prior = null): array
    {
        $source = 'revision-'.++$this->fixtureSequence;
        $feed = $prior !== null ? SupplierFeed::findOrFail($prior['supplier_feed_id']) : SupplierFeed::factory()->create(['feed_type' => 'csv', 'mapping' => ['sku' => 'code']]);
        $job = ImportJob::query()->create(['supplier_id' => $feed->supplier_id, 'supplier_feed_id' => $feed->id, 'type' => 'csv', 'mode' => 'queued', 'status' => 'pending']);
        $history = ImportHistory::startForImport($job, 'Synthetic P0-07 fixture; no import.');
        $locator = CanonicalSupplierSourceLocator::fromArray(['schema' => CanonicalSupplierSourceLocator::CONTRACT,
            'source_locator_contract_key' => 'test-source-locator', 'source_locator_contract_version' => '1',
            'scheme' => 'https', 'ascii_host' => 'feed.example.test', 'port' => null,
            'path_components' => [['position' => 0, 'classification' => 'source', 'value' => $source]], 'query_components' => []]);
        $descriptor = CanonicalSupplierSourceProfileDescriptor::fromContracts(
            supplierId: $feed->supplier_id, supplierFeedId: $feed->id, locator: $locator,
            sourceAccessScopeKey: 'source-access-v1:test.feed.'.$feed->id, feedType: 'csv', importerKey: 'csv-importer', importerVersion: '1',
            mapping: CanonicalSupplierImportMapping::fromArray(['schema' => CanonicalSupplierImportMapping::VERSION, 'feed_type' => 'csv', 'effective_mapping' => $feed->mapping]),
        );
        $id = DB::table('supplier_import_source_profiles')->insertGetId([...$descriptor->persistenceAttributes(), 'source_identity' => 'snapshot-source-v1:profile:'.md5($feed->id.':'.$source)]);
        $profile = (new SupplierImportSourceProfile)->setConnection(DB::getDefaultConnection())->newFromBuilder((array) DB::table('supplier_import_source_profiles')->find($id));
        $executionValue = CanonicalSupplierImportSourceExecution::fromContracts(
            ImportJobIdentity::fromArray(['schema' => ImportJobIdentity::VERSION, 'import_job_id' => $job->id, 'supplier_id' => $feed->supplier_id, 'supplier_feed_id' => $feed->id, 'xml_mapping_template_id' => null, 'import_type' => 'csv']),
            ResolvedSupplierImportSourceContext::fromProfile($profile), $history->id, '2026-10-18T00:00:00.123456Z',
        );
        $execution = DB::transaction(function () use ($executionValue) {
            $execution = (new SupplierImportSourceExecutionRepository)->resolveOrInsertWithinTransaction(DB::connection(), $executionValue);
            $receipt = CanonicalSupplierImportSourcePayloadReceipt::fromArray(['schema' => CanonicalSupplierImportSourcePayloadReceipt::VERSION, 'supplier_import_source_execution_id' => $execution->id,
                'source_execution_fingerprint' => $execution->getRawOriginal('source_execution_fingerprint'), 'accepted_payload_bytes' => 3, 'accepted_payload_sha256' => hash('sha256', 'abc')]);
            (new SupplierImportSourcePayloadReceiptRepository)->resolveOrInsertWithinTransaction(DB::connection(), $execution, $receipt);

            return $execution;
        });
        $key = SupplierProductLogicalKey::fromArray(['supplier_id' => $feed->supplier_id, 'supplier_feed_id' => $feed->id, 'supplier_sku_bytes' => $sku]);
        $headId = $prior['supplier_product_identity_head_id'] ?? DB::table('supplier_product_identity_heads')->insertGetId($key->persistenceAttributes());
        $product = Product::factory()->create(['name' => 'Protected synthetic product '.$source, 'meta_title' => 'Manual SEO '.$source]);
        $staging = $prior !== null ? SupplierProduct::findOrFail($prior['supplier_product_id']) : SupplierProduct::query()->create(['supplier_id' => $feed->supplier_id, 'supplier_feed_id' => $feed->id, 'product_id' => $product->id,
            'supplier_sku' => $sku, 'raw_data' => ['synthetic' => true, 'manual' => 'preserve'], 'payload_hash' => hash('sha256', $source), 'received_at' => now('UTC')]);

        // The four synthetic hashes below prove SQL format only, not deferred fingerprint semantics.
        return ['supplier_product_identity_head_id' => $headId, 'supplier_product_id' => $staging->id,
            'supplier_import_source_execution_id' => $execution->id, 'supplier_id' => $feed->supplier_id, 'supplier_feed_id' => $feed->id,
            'supplier_sku_bytes' => $key->persistenceAttributes()['supplier_sku_bytes'], 'source_identity' => $execution->getRawOriginal('source_identity'),
            'source_descriptor_fingerprint' => $execution->getRawOriginal('source_descriptor_fingerprint'), 'source_execution_fingerprint' => $execution->getRawOriginal('source_execution_fingerprint'),
            'source_member_identity_hash' => hash('sha256', 'synthetic-member-'.$source), 'source_row_fingerprint' => hash('sha256', 'synthetic-row-'.$source),
            'staging_projection_fingerprint' => hash('sha256', 'synthetic-projection-'.$source), 'revision_fingerprint' => hash('sha256', 'synthetic-revision-'.$source),
            'observed_at' => '2026-10-18 00:00:00.123456'];
    }
}
