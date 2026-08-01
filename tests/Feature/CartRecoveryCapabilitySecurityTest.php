<?php

namespace Tests\Feature;

use App\Models\AbandonedCartRecord;
use App\Models\Cart;
use App\Models\EmailLog;
use App\Models\Product;
use App\Models\User;
use App\Services\CartRecovery\CartRecoveryCapabilityService;
use App\Services\Email\Contracts\EmailProviderInterface;
use App\Services\Email\EmailMarketingService;
use App\Services\Email\Providers\LogEmailProvider;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use LogicException;
use RuntimeException;
use Tests\Concerns\InteractsWithCartRecoveryCapabilities;
use Tests\TestCase;

class CartRecoveryCapabilitySecurityTest extends TestCase
{
    use InteractsWithCartRecoveryCapabilities;
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('scout.driver', 'database');
        config()->set('commerce.abandoned_cart_recovery.enabled', true);
        config()->set('email-marketing.provider', 'log');
        config()->set('email-marketing.abandoned_cart.frontend_recovery_url', 'https://storefront.example/cart/recover');

        $this->product = Product::factory()->create([
            'price' => 125,
            'regular_price' => 125,
            'promo_price' => null,
            'quantity' => 20,
        ]);
    }

    public function test_capability_is_256_bit_base64url_and_only_its_sha256_hash_is_persisted(): void
    {
        $record = $this->record();
        $issued = app(CartRecoveryCapabilityService::class)->issue($record);
        $capability = $issued->value();
        $decoded = base64_decode(strtr($capability, '-_', '+/').'=', true);
        $databaseRow = (array) DB::table('abandoned_cart_records')->find($record->id);

        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $capability);
        $this->assertIsString($decoded);
        $this->assertSame(32, strlen($decoded));
        $this->assertSame(hash('sha256', $capability), $databaseRow['recovery_capability_hash']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $databaseRow['recovery_capability_hash']);
        $this->assertStringNotContainsString($capability, json_encode($databaseRow, JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('recovery_capability_hash', $record->fresh()->toArray());
        $this->assertStringNotContainsString($capability, $record->fresh()->toJson());
        $this->assertStringNotContainsString($issued->hash(), $record->fresh()->toJson());
        $this->assertSame(['capability' => '[REDACTED]'], $issued->__debugInfo());

        $this->expectException(LogicException::class);
        serialize($issued);
    }

    public function test_hash_is_unique_and_each_issue_rotates_the_previous_capability(): void
    {
        $record = $this->record();
        $service = app(CartRecoveryCapabilityService::class);
        $first = $service->issue($record);
        $firstHash = $record->fresh()->getRawOriginal('recovery_capability_hash');
        $second = $service->issue($record);
        $secondHash = $record->fresh()->getRawOriginal('recovery_capability_hash');

        $this->assertNotSame($first->value(), $second->value());
        $this->assertNotSame($firstHash, $secondHash);
        $this->assertSame($second->hash(), $secondHash);

        $this->postJson('/api/v1/cart/recover', ['capability' => $first->value()])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'cart_recovery_unavailable');

        $duplicate = $this->record();

        $this->expectException(QueryException::class);
        $duplicate->forceFill([
            'recovery_capability_hash' => $secondHash,
            'recovery_capability_expires_at' => now()->addDay(),
        ])->save();
    }

    public function test_detection_creates_no_capability_until_a_reminder_is_prepared(): void
    {
        $cart = Cart::query()->create([
            'session_id' => $this->cartSession('capability-detection'),
            'customer_email' => 'detection@example.test',
            'status' => 'active',
            'expires_at' => now()->addDays(14),
            'updated_at' => now()->subHours(2),
        ]);
        $cart->items()->create([
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => 125,
            'total_price' => 125,
        ]);
        DB::table('carts')->where('id', $cart->id)->update(['updated_at' => now()->subHours(2)]);

        app(EmailMarketingService::class)->detectAbandonedCarts();

        $record = AbandonedCartRecord::query()->sole();
        $this->assertNull($record->recovery_capability_hash);
        $this->assertNull($record->recovery_capability_expires_at);
    }

    public function test_reminders_rotate_capabilities_without_persisting_provider_secrets(): void
    {
        $provider = new CapturingCartRecoveryEmailProvider;
        app()->instance(EmailProviderInterface::class, $provider);
        $service = app(EmailMarketingService::class);
        $record = $this->record();

        $service->processAbandonedCart($record);
        $firstUrl = $provider->messages[0]['data']['recoveryUrl'];
        $firstCapability = Str::after($firstUrl, '#');
        $firstHash = $record->fresh()->getRawOriginal('recovery_capability_hash');

        $service->processAbandonedCart($record->fresh());
        $secondUrl = $provider->messages[1]['data']['recoveryUrl'];
        $secondCapability = Str::after($secondUrl, '#');
        $secondHash = $record->fresh()->getRawOriginal('recovery_capability_hash');

        $this->assertSame('https://storefront.example/cart/recover#'.$firstCapability, $firstUrl);
        $this->assertSame('https://storefront.example/cart/recover#'.$secondCapability, $secondUrl);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $firstCapability);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $secondCapability);
        $this->assertNotSame($firstCapability, $secondCapability);
        $this->assertNotSame($firstHash, $secondHash);

        $persisted = json_encode([
            'email_logs' => EmailLog::query()->pluck('payload')->all(),
            'marketing_events' => DB::table('marketing_events')->pluck('payload')->all(),
            'jobs' => DB::table('jobs')->pluck('payload')->all(),
            'failed_jobs' => DB::table('failed_jobs')->pluck('payload')->all(),
            'notifications' => DB::table('notifications')->pluck('data')->all(),
        ], JSON_THROW_ON_ERROR);

        foreach ([$firstCapability, $secondCapability, $firstUrl, $secondUrl, $firstHash, $secondHash] as $secret) {
            $this->assertStringNotContainsString($secret, $persisted);
        }

        $this->assertTrue(EmailLog::query()->get()->every(
            fn (EmailLog $log): bool => ($log->payload['html_persisted'] ?? null) === false,
        ));

        $this->postJson('/api/v1/cart/recover', ['capability' => $firstCapability])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'cart_recovery_unavailable');

        $this->postJson('/api/v1/cart/recover', ['capability' => $secondCapability])
            ->assertOk();
        $this->assertNull($record->fresh()->recovery_capability_hash);
        $this->assertNull($record->fresh()->recovery_capability_expires_at);
    }

    public function test_provider_failure_revokes_new_capability_and_persists_only_safe_audit(): void
    {
        $provider = new FailingCartRecoveryEmailProvider;
        app()->instance(EmailProviderInterface::class, $provider);
        $record = $this->record();

        try {
            app(EmailMarketingService::class)->processAbandonedCart($record);
            $this->fail('The controlled provider failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Abandoned Cart reminder provider failed.', $exception->getMessage());
            $this->assertNotNull($provider->recoveryUrl);
            $this->assertStringNotContainsString($provider->recoveryUrl, (string) $exception);
            $this->assertStringNotContainsString(
                Str::after($provider->recoveryUrl, '#'),
                (string) $exception,
            );
        }

        $record->refresh();
        $this->assertNull($record->recovery_capability_hash);
        $this->assertNull($record->recovery_capability_expires_at);
        $this->assertSame('failed', EmailLog::query()->sole()->status);

        $serialized = json_encode(EmailLog::query()->sole()->payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('/cart/recover#', $serialized);
        $this->assertStringNotContainsString('cart_snapshot', $serialized);
        $this->assertStringNotContainsString('session_id', $serialized);
    }

    public function test_definitive_provider_failure_cannot_reflect_capability_into_email_log(): void
    {
        $provider = new ReflectingFailedCartRecoveryEmailProvider;
        app()->instance(EmailProviderInterface::class, $provider);
        $record = $this->record();

        $log = app(EmailMarketingService::class)->processAbandonedCart($record);

        $this->assertSame('failed', $log?->status);
        $this->assertNotNull($provider->recoveryUrl);
        $this->assertNull($record->fresh()->recovery_capability_hash);
        $this->assertNull($record->fresh()->recovery_capability_expires_at);

        $serialized = json_encode($log?->payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($provider->recoveryUrl, $serialized);
        $this->assertStringNotContainsString(Str::after($provider->recoveryUrl, '#'), $serialized);
        $this->assertSame([
            'provider' => 'controlled-failure',
            'status' => 'failed',
        ], $log?->payload['provider_result']);
    }

    public function test_log_provider_allowlists_sensitive_context(): void
    {
        Log::spy();
        $capability = str_repeat('A', 43);
        $url = 'https://storefront.example/cart/recover#'.$capability;

        (new LogEmailProvider)->send(
            'private@example.test',
            'Sensitive subject',
            'emails.marketing.abandoned-cart-1',
            ['recoveryUrl' => $url, 'record' => $this->record()],
            [
                'type' => 'abandoned_cart_1',
                'sensitive' => true,
                'reminder_stage' => 1,
                'abandoned_cart_record_id' => 123,
            ],
        );

        Log::shouldHaveReceived('info')->once()->withArgs(
            function (string $message, array $context) use ($capability, $url): bool {
                $serialized = json_encode($context, JSON_THROW_ON_ERROR);

                return $message === 'Sensitive email provider send.'
                    && ! str_contains($serialized, $capability)
                    && ! str_contains($serialized, $url)
                    && ! str_contains($serialized, 'private@example.test');
            },
        );
    }

    public function test_api_accepts_capability_only_in_body_and_legacy_paths_are_absent(): void
    {
        $record = $this->record();
        $capability = $this->issueRecoveryCapability($record);

        $this->postJson('/api/v1/cart/recover/'.$capability)
            ->assertNotFound();
        $this->postJson('/api/v1/cart/recover?capability='.$capability)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'cart_recovery_unavailable');
        $this->withHeader('X-Cart-Recovery', $capability)
            ->postJson('/api/v1/cart/recover')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'cart_recovery_unavailable');

        $response = $this->postJson('/api/v1/cart/recover', ['capability' => $capability])
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Pragma', 'no-cache');

        $this->assertStringNotContainsString($capability, $response->getContent());
        $this->assertStringNotContainsString(hash('sha256', $capability), $response->getContent());
    }

    public function test_all_public_failure_states_share_one_neutral_response(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $crossUser = $this->record($owner);
        $crossUserCapability = $this->issueRecoveryCapability($crossUser);
        Sanctum::actingAs($other);

        foreach ([null, '', 'malformed', str_repeat('Z', 43), $crossUserCapability] as $capability) {
            $response = $this->postJson('/api/v1/cart/recover', ['capability' => $capability]);

            $response->assertNotFound()->assertExactJson([
                'success' => false,
                'error' => [
                    'code' => 'cart_recovery_unavailable',
                    'message' => 'Recovery link is unavailable.',
                    'details' => null,
                ],
            ]);
        }

        $this->assertNull($crossUser->fresh()->restored_at);
        $this->assertNotNull($crossUser->fresh()->recovery_capability_hash);
    }

    public function test_suppression_and_expiry_clear_active_capability_state(): void
    {
        $service = app(EmailMarketingService::class);
        $suppressed = $this->record();
        $expired = $this->record();
        $this->issueRecoveryCapability($suppressed);
        $this->issueRecoveryCapability($expired);

        $service->suppress($suppressed);
        $service->markExpired($expired);

        foreach ([$suppressed->fresh(), $expired->fresh()] as $record) {
            $this->assertNull($record->recovery_capability_hash);
            $this->assertNull($record->recovery_capability_expires_at);
        }
    }

    public function test_dedicated_recovery_limiter_is_fail_closed_without_secret_keying(): void
    {
        $request = Request::create('/api/v1/cart/recover', 'POST', server: [
            'REMOTE_ADDR' => '192.0.2.10',
        ]);
        $limiter = RateLimiter::limiter('cart-recovery');
        $this->assertNotNull($limiter);

        $limit = $limiter($request);
        $this->assertSame(10, $limit->maxAttempts);
        $this->assertSame('192.0.2.10', $limit->key);
        $this->assertIsCallable($limit->responseCallback);

        $response = ($limit->responseCallback)($request, []);
        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('max-age=0, no-store, private', $response->headers->get('Cache-Control'));
        $this->assertSame('no-cache', $response->headers->get('Pragma'));

        $route = Route::getRoutes()->match($request);
        $this->assertContains('throttle:cart-recovery', $route->gatherMiddleware());

        $provider = (string) file_get_contents(app_path('Providers/AppServiceProvider.php'));
        $this->assertStringContainsString("RateLimiter::for('cart-recovery'", $provider);
        $this->assertStringContainsString('->by((string) $request->ip())', $provider);
        $this->assertStringNotContainsString("RateLimiter::for('cart-recovery', function (Request \$request): Limit {\n            return Limit::perMinute(10)->by(\$request->input", $provider);
    }

    private function record(?User $user = null): AbandonedCartRecord
    {
        return AbandonedCartRecord::query()->create([
            'user_id' => $user?->id,
            'session_id' => $this->cartSession('capability-'.Str::random(12)),
            'email' => $user?->email ?? 'capability@example.test',
            'cart_snapshot' => ['items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'is_gift' => false,
                'unit_price' => 125,
                'total_price' => 125,
            ]], 'subtotal' => 125],
            'cart_total' => 125,
            'items_count' => 1,
            'last_cart_activity_at' => now()->subHours(2),
            'status' => 'pending',
        ]);
    }
}

final class CapturingCartRecoveryEmailProvider implements EmailProviderInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $messages = [];

    public function send(string $email, string $subject, string $template, array $data = [], array $metadata = []): array
    {
        $this->messages[] = compact('email', 'subject', 'template', 'data', 'metadata');

        return [
            'provider' => $this->name(),
            'status' => 'sent',
            'message_id' => 'controlled-message',
        ];
    }

    public function name(): string
    {
        return 'controlled';
    }
}

final class FailingCartRecoveryEmailProvider implements EmailProviderInterface
{
    public ?string $recoveryUrl = null;

    public function send(string $email, string $subject, string $template, array $data = [], array $metadata = []): array
    {
        $this->recoveryUrl = $data['recoveryUrl'];

        throw new RuntimeException('Controlled provider failure: '.$this->recoveryUrl);
    }

    public function name(): string
    {
        return 'controlled-failure';
    }
}

final class ReflectingFailedCartRecoveryEmailProvider implements EmailProviderInterface
{
    public ?string $recoveryUrl = null;

    public function send(string $email, string $subject, string $template, array $data = [], array $metadata = []): array
    {
        $this->recoveryUrl = $data['recoveryUrl'];

        return [
            'provider' => $this->name(),
            'status' => 'failed',
            'message_id' => $this->recoveryUrl,
            'reason' => $this->recoveryUrl,
        ];
    }

    public function name(): string
    {
        return 'controlled-failure';
    }
}
