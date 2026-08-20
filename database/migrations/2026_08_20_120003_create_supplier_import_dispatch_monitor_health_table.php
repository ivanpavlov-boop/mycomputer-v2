<?php

use Database\Migrations\Support\CanonicalSupplierSnapshotSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/support/CanonicalSupplierSnapshotSchema.php';

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_import_dispatch_monitor_health', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            CanonicalSupplierSnapshotSchema::ascii($table->string('monitor_identity', 64));
            $table->unsignedBigInteger('monitor_generation')->default(0);
            $table->unsignedBigInteger('last_successful_monitor_generation')->default(0);
            CanonicalSupplierSnapshotSchema::ascii($table->char('monitor_owner_token_hash', 64))->nullable();
            $table->timestamp('monitor_lease_acquired_at', 6)->nullable();
            $table->timestamp('monitor_lease_expires_at', 6)->nullable();
            $table->unsignedBigInteger('cycle_sequence')->default(0);
            $table->timestamp('last_successful_cycle_at', 6)->nullable();
            $table->timestamp('last_successful_sink_health_at', 6)->nullable();
            CanonicalSupplierSnapshotSchema::ascii($table->string('last_successful_sink_contract_key', 128))->nullable();
            CanonicalSupplierSnapshotSchema::ascii($table->string('observer_identity', 64));
            $table->unsignedBigInteger('observer_sequence')->default(0);
            $table->unsignedBigInteger('observed_monitor_generation')->default(0);
            $table->unsignedBigInteger('observed_cycle_sequence')->default(0);
            $table->timestamp('last_successful_observer_at', 6)->nullable();
            CanonicalSupplierSnapshotSchema::ascii($table->string('integrity_state', 16))->default('unknown');
            CanonicalSupplierSnapshotSchema::ascii($table->string('last_failure_code', 64))->nullable();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->timestamp('updated_at', 6)->useCurrent()->useCurrentOnUpdate();

            $table->unique('monitor_identity', 'uq_import_dispatch_monitor_identity');
            $table->unique('observer_identity', 'uq_import_dispatch_observer_identity');
        });

        CanonicalSupplierSnapshotSchema::addChecks('supplier_import_dispatch_monitor_health', [
            'chk_import_dispatch_monitor_singleton' => 'id = 1',
            'chk_import_dispatch_monitor_identity' => <<<'SQL'
                BINARY monitor_identity = BINARY _ascii'supplier-import-dispatch-watchdog-v1'
                AND OCTET_LENGTH(monitor_identity) = 36
                AND BINARY observer_identity = BINARY _ascii'supplier-import-dispatch-observer-v1'
                AND OCTET_LENGTH(observer_identity) = 36
                SQL,
            'chk_import_dispatch_monitor_integrity_state' => <<<'SQL'
                BINARY integrity_state IN (
                    BINARY _ascii'healthy', BINARY _ascii'stale',
                    BINARY _ascii'failed', BINARY _ascii'unknown'
                )
                SQL,
            'chk_import_dispatch_monitor_owner_tuple' => <<<'SQL'
                (
                    monitor_owner_token_hash IS NULL
                    AND monitor_lease_acquired_at IS NULL
                    AND monitor_lease_expires_at IS NULL
                )
                OR (
                    monitor_owner_token_hash IS NOT NULL
                    AND OCTET_LENGTH(monitor_owner_token_hash) = 64
                    AND REGEXP_LIKE(monitor_owner_token_hash, _ascii'^[0-9a-f]{64}$', 'c')
                    AND monitor_lease_acquired_at IS NOT NULL
                    AND monitor_lease_expires_at IS NOT NULL
                    AND monitor_lease_acquired_at < monitor_lease_expires_at
                )
                SQL,
            'chk_import_dispatch_monitor_generation' => 'last_successful_monitor_generation <= monitor_generation',
            'chk_import_dispatch_monitor_success_tuple' => <<<'SQL'
                (
                    cycle_sequence = 0
                    AND last_successful_monitor_generation = 0
                    AND last_successful_cycle_at IS NULL
                    AND last_successful_sink_health_at IS NULL
                    AND last_successful_sink_contract_key IS NULL
                )
                OR (
                    cycle_sequence > 0
                    AND last_successful_monitor_generation > 0
                    AND last_successful_cycle_at IS NOT NULL
                    AND last_successful_sink_health_at IS NOT NULL
                    AND last_successful_cycle_at = last_successful_sink_health_at
                    AND last_successful_sink_contract_key IS NOT NULL
                )
                SQL,
            'chk_import_dispatch_monitor_observer_tuple' => <<<'SQL'
                (
                    observer_sequence = 0
                    AND observed_monitor_generation = 0
                    AND observed_cycle_sequence = 0
                    AND last_successful_observer_at IS NULL
                )
                OR (
                    observer_sequence > 0
                    AND observed_monitor_generation > 0
                    AND observed_cycle_sequence > 0
                    AND last_successful_observer_at IS NOT NULL
                    AND observed_monitor_generation <= last_successful_monitor_generation
                    AND observed_cycle_sequence <= cycle_sequence
                )
                SQL,
            'chk_import_dispatch_monitor_stored_healthy' => <<<'SQL'
                integrity_state <> 'healthy'
                OR (
                    cycle_sequence > 0
                    AND last_successful_monitor_generation > 0
                    AND last_successful_cycle_at IS NOT NULL
                    AND last_successful_sink_health_at IS NOT NULL
                    AND last_successful_cycle_at = last_successful_sink_health_at
                    AND last_successful_sink_contract_key IS NOT NULL
                    AND last_failure_code IS NULL
                )
                SQL,
        ]);

        DB::table('supplier_import_dispatch_monitor_health')->insert([
            'id' => 1,
            'monitor_identity' => 'supplier-import-dispatch-watchdog-v1',
            'observer_identity' => 'supplier-import-dispatch-observer-v1',
            'integrity_state' => 'unknown',
        ]);
    }

    public function down(): void
    {
        CanonicalSupplierSnapshotSchema::assertDestructiveDownAllowed();
        Schema::drop('supplier_import_dispatch_monitor_health');
    }
};
