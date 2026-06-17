<?php

namespace Tests\Feature;

use App\Filament\Resources\FeedExports\FeedExportResource;
use App\Filament\Resources\MarketingEvents\MarketingEventResource;
use App\Jobs\AnalyticsEventJob;
use App\Jobs\GenerateFeedJob;
use App\Models\ConversionLog;
use App\Models\MarketingEvent;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Marketing\ConversionTrackingService;
use App\Services\Marketing\FacebookCatalogService;
use App\Services\Marketing\MarketingEventService;
use App\Services\Marketing\MerchantFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class MarketingPlatformTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('scout.driver', 'database');
        $this->seed();
    }

    public function test_google_merchant_feed_generation(): void
    {
        Product::query()->where('sku', 'MC-LAP-001')->update([
            'ean' => '1234567890123',
            'mpn' => 'LEN-E16-G2',
        ]);

        $xml = app(MerchantFeedService::class)->xml();

        $this->assertStringContainsString('<g:id><![CDATA[MC-LAP-001]]></g:id>', $xml);
        $this->assertStringContainsString('<g:gtin><![CDATA[1234567890123]]></g:gtin>', $xml);
        $this->assertStringContainsString('<g:condition><![CDATA[new]]></g:condition>', $xml);
        $this->assertStringContainsString(' EUR', $xml);
        $this->assertStringNotContainsString(' BGN', $xml);
        $this->assertStringNotContainsString('purchase_price', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    public function test_facebook_catalog_feed_generation(): void
    {
        $xml = app(FacebookCatalogService::class)->xml();

        $this->assertStringContainsString('Facebook Catalog', $xml);
        $this->assertStringContainsString('<g:id><![CDATA[MC-LAP-001]]></g:id>', $xml);
        $this->assertStringContainsString(' EUR', $xml);
        $this->assertStringNotContainsString(' BGN', $xml);
        $this->assertStringNotContainsString('source_payload', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    public function test_public_feed_endpoints_return_valid_xml(): void
    {
        $this->get('/feeds/google-merchant.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        $this->get('/feeds/facebook-catalog.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function test_analytics_event_logging_sanitizes_internal_fields(): void
    {
        Queue::fake();

        $this->withHeader('X-Marketing-Session', 'marketing-session')
            ->postJson('/api/v1/marketing/events', [
                'event_name' => 'search',
                'source' => 'ga4',
                'payload' => [
                    'query' => 'rtx 5070',
                    'purchase_price' => 10,
                    'source_payload' => ['secret' => true],
                ],
            ])
            ->assertAccepted()
            ->assertJsonPath('data.event_name', 'search');

        Queue::assertPushed(AnalyticsEventJob::class);

        Queue::assertPushed(AnalyticsEventJob::class, function (AnalyticsEventJob $job): bool {
            $job->handle(app(MarketingEventService::class));

            return $job->eventName === 'search' && $job->source === 'ga4';
        });

        $event = MarketingEvent::query()->where('event_name', 'search')->firstOrFail();

        $this->assertSame('ga4', $event->source);
        $this->assertArrayNotHasKey('purchase_price', $event->payload);
        $this->assertArrayNotHasKey('source_payload', $event->payload);
    }

    public function test_add_to_cart_tracking_is_logged_server_side(): void
    {
        Queue::fake();
        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();

        $this->withHeaders([
            'X-Cart-Session' => 'cart-session',
            'X-Marketing-Session' => 'marketing-session',
        ])->postJson('/api/v1/cart/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertOk();

        Queue::assertPushed(AnalyticsEventJob::class, fn (AnalyticsEventJob $job): bool => $job->eventName === 'add_to_cart');
    }

    public function test_conversion_logging_and_purchase_event_generation(): void
    {
        $order = Order::query()->create([
            'order_number' => 'MC-TEST-1',
            'customer_email' => 'client@example.com',
            'customer_phone' => '+359888111222',
            'customer_name' => 'Test Client',
            'billing_address' => 'Sofia',
            'shipping_address' => 'Sofia',
            'subtotal' => 100,
            'shipping_price' => 10,
            'discount_total' => 0,
            'grand_total' => 110,
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'pending',
            'shipping_method' => 'address',
            'shipping_status' => 'pending',
            'status' => 'pending',
        ]);

        $order->items()->create([
            'product_name' => 'Test Product',
            'sku' => 'TEST-SKU',
            'quantity' => 1,
            'unit_price' => 100,
            'total_price' => 100,
        ]);

        app(ConversionTrackingService::class)->purchase($order);

        $this->assertDatabaseHas('conversion_logs', ['order_id' => $order->id, 'provider' => 'ga4', 'event_name' => 'purchase']);
        $this->assertDatabaseHas('conversion_logs', ['order_id' => $order->id, 'provider' => 'meta', 'event_name' => 'Purchase']);
        $this->assertEquals(2, ConversionLog::query()->where('order_id', $order->id)->count());
        $this->assertSame(Product::CATALOG_CURRENCY, ConversionLog::query()->where('order_id', $order->id)->firstOrFail()->payload['currency']);
    }

    public function test_feed_regeneration_api_requires_permission_and_creates_history(): void
    {
        Queue::fake();
        $admin = $this->adminUser();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/feeds/generate', ['feed_type' => 'google_merchant'])
            ->assertAccepted()
            ->assertJsonPath('data.feed_type', 'google_merchant')
            ->assertJsonPath('data.status', 'queued');

        Queue::assertPushed(GenerateFeedJob::class, fn (GenerateFeedJob $job): bool => $job->feedType === 'google_merchant');
    }

    public function test_analytics_admin_endpoints_are_protected(): void
    {
        $this->getJson('/api/v1/analytics/dashboard')->assertUnauthorized();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/analytics/dashboard')->assertForbidden();
    }

    public function test_admin_permissions_for_marketing_resources(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        $this->assertTrue(MarketingEventResource::canViewAny());
        $this->assertTrue(FeedExportResource::canViewAny());
    }

    private function adminUser(): User
    {
        $user = User::factory()->create();
        Permission::findOrCreate('manage marketing', 'web');
        $user->givePermissionTo('manage marketing');

        return $user;
    }
}
