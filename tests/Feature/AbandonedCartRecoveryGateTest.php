<?php

namespace Tests\Feature;

use App\Exceptions\CartRecoveryInvalidException;
use App\Jobs\ProcessAbandonedCartEmailJob;
use App\Jobs\ProcessAbandonedCartJob;
use App\Models\AbandonedCartRecord;
use App\Models\Cart;
use App\Models\EmailLog;
use App\Models\Product;
use App\Services\Email\EmailMarketingService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCartRecoveryCapabilities;
use Tests\TestCase;

class AbandonedCartRecoveryGateTest extends TestCase
{
    use InteractsWithCartRecoveryCapabilities;
    use RefreshDatabase;

    public function test_disabled_recovery_commands_services_and_jobs_make_zero_writes(): void
    {
        config()->set('commerce.abandoned_cart_recovery.enabled', false);
        config()->set('email-marketing.provider', 'log');

        $product = Product::factory()->supplierPublished()->create();
        $cart = Cart::query()->create([
            'session_id' => $this->cartSession('disabled-recovery'),
            'customer_email' => 'disabled-recovery@example.test',
            'status' => 'active',
            'expires_at' => now()->addDay(),
            'updated_at' => now()->subHours(2),
        ]);
        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => $product->effectivePrice(),
            'total_price' => $product->effectivePrice(),
        ]);
        $record = AbandonedCartRecord::query()->create([
            'session_id' => $this->cartSession('disabled-recovery-record'),
            'email' => 'disabled-record@example.test',
            'cart_snapshot' => ['items' => []],
            'cart_total' => 0,
            'items_count' => 0,
            'last_cart_activity_at' => now()->subHours(2),
            'status' => 'pending',
        ]);
        $capability = $this->issueRecoveryCapability($record);

        $before = $this->recoveryState();
        $service = app(EmailMarketingService::class);

        $this->assertSame(0, $service->detectAbandonedCarts());
        $this->assertSame(0, $service->processDueAbandonedCarts());
        $this->assertNull($service->recordAbandonedCart($cart));
        $this->assertNull($service->processAbandonedCart($record));
        $this->assertThrows(
            fn () => $service->restoreCartFromCapability($capability),
            CartRecoveryInvalidException::class,
        );

        $this->postJson('/api/v1/cart/recover', $this->recoveryRequest($capability))
            ->assertNotFound()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertJsonPath('error.code', 'cart_recovery_unavailable');

        $this->assertSame(0, Artisan::call('carts:detect-abandoned'));
        $this->assertStringContainsString('disabled', Artisan::output());
        $this->assertSame(0, Artisan::call('carts:process-abandoned'));
        $this->assertStringContainsString('disabled', Artisan::output());

        (new ProcessAbandonedCartJob(cartId: $cart->id))->handle($service);
        (new ProcessAbandonedCartJob(recordId: $record->id))->handle($service);
        (new ProcessAbandonedCartEmailJob($record->id))->handle($service);

        $this->assertSame($before, $this->recoveryState());
        $this->assertSame(0, EmailLog::query()->count());
    }

    public function test_disabled_recovery_schedule_is_not_registered(): void
    {
        config()->set('commerce.abandoned_cart_recovery.enabled', false);

        $commands = collect(app(Schedule::class)->events())
            ->pluck('command')
            ->filter()
            ->implode("\n");

        $this->assertStringNotContainsString('carts:detect-abandoned', $commands);
        $this->assertStringNotContainsString('carts:process-abandoned', $commands);
    }

    private function recoveryState(): array
    {
        return [
            'abandoned_cart_records' => DB::table('abandoned_cart_records')->count(),
            'email_logs' => DB::table('email_logs')->count(),
            'marketing_events' => DB::table('marketing_events')->count(),
            'cart_statuses' => Cart::query()->orderBy('id')->pluck('status', 'id')->all(),
            'record_state' => AbandonedCartRecord::query()
                ->orderBy('id')
                ->get(['id', 'status', 'emails_sent', 'last_email_sent_at'])
                ->map->toArray()
                ->all(),
        ];
    }
}
