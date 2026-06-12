<?php

namespace Tests\Feature;

use App\Jobs\ProcessBackInStockAlertJob;
use App\Jobs\ProcessPriceDropAlertJob;
use App\Jobs\ProcessReviewRequestJob;
use App\Jobs\SendEmailJob;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\EmailSubscriber;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPriceAlert;
use App\Models\ProductStockAlert;
use App\Services\Email\EmailMarketingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EmailMarketingAutomationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('scout.driver', 'database');
        config()->set('email-marketing.provider', 'log');
        $this->seed();
    }

    public function test_subscribe_unsubscribe_and_duplicate_subscription(): void
    {
        $this->postJson('/api/v1/newsletter/subscribe', [
            'email' => 'client@example.com',
            'first_name' => 'Ivan',
            'source' => 'newsletter',
            'gdpr_consent' => true,
        ])->assertCreated()->assertJsonPath('data.status', 'subscribed');

        $this->postJson('/api/v1/newsletter/subscribe', [
            'email' => 'client@example.com',
            'first_name' => 'Petar',
            'source' => 'popup',
            'gdpr_consent' => true,
        ])->assertCreated();

        $this->assertSame(1, EmailSubscriber::query()->where('email', 'client@example.com')->count());
        $this->assertDatabaseHas('email_subscribers', ['email' => 'client@example.com', 'first_name' => 'Petar', 'source' => 'popup']);

        $this->postJson('/api/v1/newsletter/unsubscribe', ['email' => 'client@example.com'])
            ->assertOk()
            ->assertJsonPath('data.status', 'unsubscribed');

        $this->getJson('/api/v1/newsletter/status?email=client@example.com')
            ->assertOk()
            ->assertJsonPath('data.status', 'unsubscribed');
    }

    public function test_registration_queues_welcome_email(): void
    {
        Queue::fake();

        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Ivan',
            'last_name' => 'Petrov',
            'email' => 'welcome@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertCreated();

        Queue::assertPushed(SendEmailJob::class, fn (SendEmailJob $job): bool => $job->email === 'welcome@example.com' && $job->type === 'welcome');
        $this->assertDatabaseHas('email_subscribers', ['email' => 'welcome@example.com', 'source' => 'account']);
    }

    public function test_abandoned_cart_detection_creates_record(): void
    {
        $product = Product::query()->firstOrFail();
        $cart = Cart::query()->create([
            'session_id' => 'email-cart',
            'customer_email' => 'cart@example.com',
            'status' => 'active',
        ]);
        CartItem::query()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => $product->price,
            'total_price' => $product->price,
        ]);

        app(EmailMarketingService::class)->recordAbandonedCart($cart);

        $this->assertDatabaseHas('abandoned_cart_records', ['session_id' => 'email-cart', 'email' => 'cart@example.com']);
    }

    public function test_review_request_dispatches_email_job(): void
    {
        Queue::fake();
        $order = Order::query()->create([
            'order_number' => 'MC-EMAIL-1',
            'customer_email' => 'review@example.com',
            'customer_phone' => '0888123456',
            'customer_name' => 'Review Client',
            'billing_address' => 'Sofia',
            'shipping_address' => 'Sofia',
            'subtotal' => 100,
            'shipping_price' => 0,
            'discount_total' => 0,
            'grand_total' => 100,
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'pending',
            'shipping_method' => 'address',
            'shipping_status' => 'delivered',
            'status' => 'completed',
        ]);

        (new ProcessReviewRequestJob($order->id))->handle(app(EmailMarketingService::class));

        Queue::assertPushed(SendEmailJob::class, fn (SendEmailJob $job): bool => $job->email === 'review@example.com' && $job->type === 'review_request');
    }

    public function test_price_drop_and_stock_alerts_dispatch_emails(): void
    {
        Queue::fake();
        $product = Product::query()->firstOrFail();
        ProductPriceAlert::query()->create(['email' => 'price@example.com', 'product_id' => $product->id, 'target_price' => $product->price + 10]);
        ProductStockAlert::query()->create(['email' => 'stock@example.com', 'product_id' => $product->id]);
        $product->update(['stock_status' => 'in_stock']);

        (new ProcessPriceDropAlertJob($product->id))->handle(app(EmailMarketingService::class));
        (new ProcessBackInStockAlertJob($product->id))->handle(app(EmailMarketingService::class));

        Queue::assertPushed(SendEmailJob::class, fn (SendEmailJob $job): bool => $job->email === 'price@example.com' && $job->type === 'price_drop');
        Queue::assertPushed(SendEmailJob::class, fn (SendEmailJob $job): bool => $job->email === 'stock@example.com' && $job->type === 'back_in_stock');
    }

    public function test_suppression_handling_skips_marketing_email(): void
    {
        EmailSubscriber::query()->create([
            'email' => 'suppressed@example.com',
            'source' => 'newsletter',
            'status' => 'suppressed',
        ]);

        $log = app(EmailMarketingService::class)->send('suppressed@example.com', 'price_drop', []);

        $this->assertSame('skipped', $log->status);
        $this->assertDatabaseHas('email_logs', ['email' => 'suppressed@example.com', 'status' => 'skipped']);
    }

    public function test_queue_email_dispatch_can_be_processed(): void
    {
        (new SendEmailJob('queue@example.com', 'welcome', []))->handle(app(EmailMarketingService::class));

        $this->assertDatabaseHas('email_logs', ['email' => 'queue@example.com', 'type' => 'welcome', 'status' => 'sent']);
    }
}
