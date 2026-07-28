<?php

namespace Tests\Feature;

use App\Exceptions\PaymentRetryUnavailableException;
use App\Models\PaymentRetryCapability;
use App\Models\User;
use App\Services\Payments\PaymentRetryCapabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsPaymentAttemptFixtures;
use Tests\TestCase;

class PaymentRetryCapabilityTest extends TestCase
{
    use BuildsPaymentAttemptFixtures;
    use RefreshDatabase;

    public function test_guest_online_capability_is_hash_only_and_cookie_is_host_only(): void
    {
        $this->enableTestCard();
        $order = $this->paymentOrder();
        $service = app(PaymentRetryCapabilityService::class);
        $token = $service->issue($order);

        $this->assertIsString($token);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $token);
        $record = PaymentRetryCapability::query()->sole();
        $this->assertSame(hash('sha256', $token), $record->token_hash);
        $this->assertDatabaseMissing('payment_retry_capabilities', ['token_hash' => $token]);

        $request = Request::create(
            'https://shop.example.test/api/v1/checkout/payment-attempts',
            'POST',
        );
        $cookie = $service->cookie($token, $request);
        $this->assertSame('mc_payment_retry', $cookie->getName());
        $this->assertSame('/api/v1/checkout/payment-attempts', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $this->assertStringNotContainsString('domain=', strtolower((string) $cookie));
        $this->assertEqualsWithDelta(
            3600,
            $cookie->getExpiresTime() - time(),
            2,
        );

        $second = $service->issue($order);
        $this->assertNotSame($token, $second);
        $this->assertDatabaseCount('payment_retry_capabilities', 2);
    }

    public function test_capability_is_not_issued_for_authenticated_offline_leasing_or_disabled_card(): void
    {
        $this->enableTestCard();
        $service = app(PaymentRetryCapabilityService::class);
        $this->assertNull($service->issue($this->paymentOrder(User::factory()->create())));
        $this->assertNull($service->issue($this->paymentOrder(method: 'cash_on_delivery')));
        $this->assertNull($service->issue($this->paymentOrder(method: 'bank_transfer')));
        $this->assertNull($service->issue($this->paymentOrder(method: 'leasing')));

        config()->set('payments.methods.card.enabled', false);
        $this->assertNull($service->issue($this->paymentOrder()));
    }

    public function test_expired_and_revoked_capabilities_fail_closed(): void
    {
        $this->enableTestCard();
        $service = app(PaymentRetryCapabilityService::class);

        foreach (['expired', 'revoked'] as $state) {
            $token = $service->issue($this->paymentOrder());
            $record = PaymentRetryCapability::query()->latest('id')->firstOrFail();
            $record->update($state === 'expired'
                ? ['expires_at' => now()->subMinute()]
                : ['revoked_at' => now()]);

            try {
                $service->resolve($token);
                $this->fail("{$state} capability resolved.");
            } catch (PaymentRetryUnavailableException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_valid_guest_capability_authorizes_one_safe_attempt(): void
    {
        $this->enableTestCard();
        $order = $this->paymentOrder();
        $this->paymentTransaction($order);
        $token = app(PaymentRetryCapabilityService::class)->issue($order);

        $response = $this->withCredentials()
            ->withUnencryptedCookie('mc_payment_retry', $token)
            ->postJson(
                '/api/v1/checkout/payment-attempts',
                [],
                ['Idempotency-Key' => $this->paymentAttemptKey('guest-valid')],
            );

        $response->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonMissingPath('data.order_id')
            ->assertJsonMissingPath('data.transaction_id')
            ->assertJsonMissingPath('data.capability');
        $this->assertDatabaseCount('payment_attempts', 1);
    }

    public function test_retry_persistence_has_the_required_hash_only_constraints(): void
    {
        $this->assertSame([
            'id',
            'order_id',
            'token_hash',
            'expires_at',
            'last_used_at',
            'revoked_at',
            'created_at',
            'updated_at',
        ], Schema::getColumnListing('payment_retry_capabilities'));
        $this->assertSame([
            'id',
            'reference',
            'order_id',
            'payment_method_id',
            'payment_provider_id',
            'payment_transaction_id',
            'idempotency_key_hash',
            'request_hash',
            'attempt_number',
            'status',
            'authorization_type',
            'initiated_by_user_id',
            'provider_reference',
            'completed_at',
            'failed_at',
            'failure_code',
            'created_at',
            'updated_at',
        ], Schema::getColumnListing('payment_attempts'));

        $capabilityIndexes = collect(Schema::getIndexes('payment_retry_capabilities'));
        $attemptIndexes = collect(Schema::getIndexes('payment_attempts'));
        $this->assertTrue($capabilityIndexes->contains(
            fn (array $index): bool => $index['unique']
                && $index['columns'] === ['token_hash'],
        ));
        $this->assertTrue($attemptIndexes->contains(
            fn (array $index): bool => $index['unique']
                && $index['columns'] === ['idempotency_key_hash'],
        ));
        $this->assertTrue($attemptIndexes->contains(
            fn (array $index): bool => $index['unique']
                && $index['columns'] === ['order_id', 'attempt_number'],
        ));
        $this->assertTrue($attemptIndexes->contains(
            fn (array $index): bool => $index['unique']
                && $index['columns'] === ['payment_transaction_id'],
        ));
        $this->assertTrue($attemptIndexes->contains(
            fn (array $index): bool => $index['unique']
                && $index['columns'] === ['payment_provider_id', 'provider_reference'],
        ));
    }
}
