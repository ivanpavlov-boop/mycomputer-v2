<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class CartRecoveryCapabilityMigrationTest extends TestCase
{
    public function test_migration_invalidates_legacy_values_preserves_audit_and_refuses_lossy_rollback(): void
    {
        $original = config('database.default');
        $database = tempnam(sys_get_temp_dir(), 'cart-recovery-migration-');
        $this->assertNotFalse($database);

        config()->set('database.connections.cart_recovery_migration', [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        config()->set('database.default', 'cart_recovery_migration');
        DB::purge('cart_recovery_migration');

        try {
            Schema::create('abandoned_cart_records', function (Blueprint $table): void {
                $table->id();
                $table->json('cart_snapshot');
                $table->string('status')->default('pending');
                $table->unsignedTinyInteger('emails_sent')->default(0);
                $table->timestamp('last_email_sent_at')->nullable();
                $table->timestamp('last_cart_activity_at')->nullable();
                $table->string('recovery_token', 80)->nullable()->unique();
                $table->timestamp('recovery_token_expires_at')->nullable()->index();
                $table->timestamps();
            });

            DB::table('abandoned_cart_records')->insert([
                'cart_snapshot' => json_encode(['items' => [['product_id' => 41]]], JSON_THROW_ON_ERROR),
                'status' => 'emailed_once',
                'emails_sent' => 1,
                'last_email_sent_at' => '2026-07-31 10:00:00',
                'last_cart_activity_at' => '2026-07-31 09:00:00',
                'recovery_token' => 'legacy-plaintext-capability',
                'recovery_token_expires_at' => '2026-08-02 10:00:00',
                'created_at' => '2026-07-31 09:00:00',
                'updated_at' => '2026-07-31 10:00:00',
            ]);

            $migration = require base_path(
                'database/migrations/2026_08_01_090000_replace_plaintext_abandoned_cart_recovery_token_with_hash.php',
            );
            $migration->up();

            $this->assertTrue(Schema::hasColumn('abandoned_cart_records', 'recovery_capability_hash'));
            $this->assertTrue(Schema::hasColumn('abandoned_cart_records', 'recovery_capability_expires_at'));
            $this->assertFalse(Schema::hasColumn('abandoned_cart_records', 'recovery_token'));
            $this->assertFalse(Schema::hasColumn('abandoned_cart_records', 'recovery_token_expires_at'));

            $row = DB::table('abandoned_cart_records')->sole();
            $this->assertSame('emailed_once', $row->status);
            $this->assertSame(1, (int) $row->emails_sent);
            $this->assertSame('2026-07-31 10:00:00', $row->last_email_sent_at);
            $this->assertSame(['items' => [['product_id' => 41]]], json_decode($row->cart_snapshot, true, flags: JSON_THROW_ON_ERROR));
            $this->assertNull($row->recovery_capability_hash);
            $this->assertNull($row->recovery_capability_expires_at);

            DB::table('abandoned_cart_records')->where('id', $row->id)->update([
                'recovery_capability_hash' => str_repeat('a', 64),
                'recovery_capability_expires_at' => '2026-08-02 10:00:00',
            ]);
            DB::table('abandoned_cart_records')->insert([
                'cart_snapshot' => '[]',
                'status' => 'pending',
                'emails_sent' => 0,
                'recovery_capability_hash' => null,
                'created_at' => '2026-07-31 09:00:00',
                'updated_at' => '2026-07-31 09:00:00',
            ]);

            try {
                DB::table('abandoned_cart_records')->where('id', 2)->update([
                    'recovery_capability_hash' => str_repeat('a', 64),
                ]);
                $this->fail('The capability hash unique index was not enforced.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }

            $this->expectException(RuntimeException::class);
            $migration->down();
        } finally {
            DB::disconnect('cart_recovery_migration');
            DB::purge('cart_recovery_migration');
            config()->set('database.default', $original);
            @unlink($database);
        }
    }

    public function test_empty_table_rollback_restores_only_nullable_legacy_columns(): void
    {
        $original = config('database.default');
        $database = tempnam(sys_get_temp_dir(), 'cart-recovery-rollback-');
        $this->assertNotFalse($database);

        config()->set('database.connections.cart_recovery_rollback', [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        config()->set('database.default', 'cart_recovery_rollback');
        DB::purge('cart_recovery_rollback');

        try {
            Schema::create('abandoned_cart_records', function (Blueprint $table): void {
                $table->id();
                $table->timestamp('last_cart_activity_at')->nullable();
                $table->string('recovery_token', 80)->nullable()->unique();
                $table->timestamp('recovery_token_expires_at')->nullable()->index();
            });

            $migration = require base_path(
                'database/migrations/2026_08_01_090000_replace_plaintext_abandoned_cart_recovery_token_with_hash.php',
            );
            $migration->up();
            $this->assertSame(0, DB::table('abandoned_cart_records')->count());

            $migration->down();

            $this->assertTrue(Schema::hasColumn('abandoned_cart_records', 'recovery_token'));
            $this->assertTrue(Schema::hasColumn('abandoned_cart_records', 'recovery_token_expires_at'));
            $this->assertFalse(Schema::hasColumn('abandoned_cart_records', 'recovery_capability_hash'));
            $this->assertFalse(Schema::hasColumn('abandoned_cart_records', 'recovery_capability_expires_at'));
        } finally {
            DB::disconnect('cart_recovery_rollback');
            DB::purge('cart_recovery_rollback');
            config()->set('database.default', $original);
            @unlink($database);
        }
    }
}
