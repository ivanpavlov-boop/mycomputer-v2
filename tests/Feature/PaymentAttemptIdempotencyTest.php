<?php

namespace Tests\Feature;

use App\Events\PaymentAttemptCompleted;
use App\Models\PaymentAttempt;
use App\Models\PaymentRetryCapability;
use App\Models\User;
use App\Services\Payments\PaymentAttemptIdempotencyService;
use App\Services\Payments\PaymentProviderIdempotencyService;
use App\Services\Payments\PaymentRetryAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsPaymentAttemptFixtures;
use Tests\TestCase;

class PaymentAttemptIdempotencyTest extends TestCase
{
    use BuildsPaymentAttemptFixtures;
    use RefreshDatabase;

    public function test_valid_key_creates_one_attempt_and_transaction_and_replays_safely(): void
    {
        $fake = $this->enableTestCard();
        Event::fake([PaymentAttemptCompleted::class]);
        $owner = User::factory()->create();
        $order = $this->paymentOrder($owner);
        $this->paymentTransaction($order, 'failed');
        $key = $this->paymentAttemptKey('single');
        $url = "/api/v1/account/orders/{$order->id}/payment-attempts";

        $first = $this->actingAs($owner, 'sanctum')
            ->postJson($url, [], ['Idempotency-Key' => $key]);
        $replay = $this->actingAs($owner, 'sanctum')
            ->postJson($url, [], ['Idempotency-Key' => $key]);

        $first->assertCreated()
            ->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.payment.status', 'pending')
            ->assertJsonPath('data.payment.redirect_url', 'https://payments.example.test/continue')
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.payment.transaction_id');
        $replay->assertOk()
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.reference', $first->json('data.reference'));

        $this->assertSame(1, $fake->initiationCount);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertDatabaseCount('payment_transactions', 2);
        $attempt = PaymentAttempt::query()->sole();
        $providerIdempotencyKey = $fake->providerIdempotencyKeys[0];
        $this->assertSame(hash('sha256', $key), $attempt->idempotency_key_hash);
        $this->assertNotSame($key, $attempt->idempotency_key_hash);
        $this->assertNotNull($attempt->payment_transaction_id);
        $this->assertNotNull($providerIdempotencyKey);
        $this->assertNotSame($key, $providerIdempotencyKey);
        $this->assertNotSame($attempt->idempotency_key_hash, $providerIdempotencyKey);
        $this->assertStringNotContainsString(
            $providerIdempotencyKey,
            json_encode([
                $attempt->getAttributes(),
                $attempt->transaction?->getAttributes(),
            ], JSON_THROW_ON_ERROR),
        );
        $this->assertStringNotContainsString(
            substr($providerIdempotencyKey, 0, 16),
            (string) $attempt->transaction?->transaction_id,
        );
        Event::assertDispatchedTimes(PaymentAttemptCompleted::class, 1);
    }

    public function test_same_key_for_another_order_conflicts_without_leaking_identity(): void
    {
        $fake = $this->enableTestCard();
        $owner = User::factory()->create();
        $firstOrder = $this->paymentOrder($owner);
        $secondOrder = $this->paymentOrder($owner);
        $this->paymentTransaction($firstOrder);
        $this->paymentTransaction($secondOrder);
        $key = $this->paymentAttemptKey('global');

        $this->actingAs($owner, 'sanctum')
            ->postJson(
                "/api/v1/account/orders/{$firstOrder->id}/payment-attempts",
                [],
                ['Idempotency-Key' => $key],
            )
            ->assertCreated();
        $this->actingAs($owner, 'sanctum')
            ->postJson(
                "/api/v1/account/orders/{$secondOrder->id}/payment-attempts",
                [],
                ['Idempotency-Key' => $key],
            )
            ->assertConflict()
            ->assertJsonPath('error.code', 'payment_idempotency_conflict')
            ->assertJsonMissing(['order_id' => $firstOrder->id]);

        $this->assertSame(1, $fake->initiationCount);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertDatabaseCount('payment_transactions', 3);
    }

    public function test_same_key_with_a_different_authorization_fingerprint_conflicts(): void
    {
        $this->enableTestCard();
        $order = $this->paymentOrder();
        $transaction = $this->paymentTransaction($order);
        $key = $this->paymentAttemptKey('authorization-type');
        $accountAuthorization = PaymentRetryAuthorization::accountOwner(
            $order,
            123,
        );
        $context = app(PaymentAttemptIdempotencyService::class)
            ->context($key, $order, $accountAuthorization);
        PaymentAttempt::query()->create([
            'reference' => 'PA-auth-fingerprint',
            'order_id' => $order->id,
            'payment_method_id' => $transaction->payment_method_id,
            'payment_provider_id' => $transaction->payment_provider_id,
            'payment_transaction_id' => $transaction->id,
            'idempotency_key_hash' => $context->keyHash,
            'request_hash' => $context->requestHash,
            'attempt_number' => 1,
            'status' => PaymentAttempt::STATUS_COMPLETED,
            'authorization_type' => PaymentAttempt::AUTH_ACCOUNT_OWNER,
            'completed_at' => now(),
        ]);

        $token = $this->paymentAttemptKey('manual-capability');
        PaymentRetryCapability::query()->create([
            'order_id' => $order->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(),
        ]);

        $this->withCredentials()
            ->withUnencryptedCookie('mc_payment_retry', $token)
            ->postJson(
                '/api/v1/checkout/payment-attempts',
                [],
                ['Idempotency-Key' => $key],
            )
            ->assertConflict()
            ->assertJsonPath('error.code', 'payment_idempotency_conflict')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertDatabaseCount('payment_transactions', 1);
    }

    public function test_invalid_keys_and_in_progress_attempts_fail_without_provider_call(): void
    {
        $fake = $this->enableTestCard();
        $owner = User::factory()->create();
        $order = $this->paymentOrder($owner);
        $old = $this->paymentTransaction($order);
        $url = "/api/v1/account/orders/{$order->id}/payment-attempts";

        foreach ([null, '', 'short', str_repeat('a', 44), str_repeat('*', 43)] as $key) {
            $headers = $key === null ? [] : ['Idempotency-Key' => $key];
            $this->actingAs($owner, 'sanctum')
                ->postJson($url, [], $headers)
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'payment_idempotency_key_invalid');
        }

        PaymentAttempt::query()->create([
            'reference' => 'PA-processing',
            'order_id' => $order->id,
            'payment_method_id' => $old->payment_method_id,
            'payment_provider_id' => $old->payment_provider_id,
            'idempotency_key_hash' => hash('sha256', $this->paymentAttemptKey('processing')),
            'request_hash' => app(PaymentAttemptIdempotencyService::class)
                ->context(
                    $this->paymentAttemptKey('processing'),
                    $order,
                    PaymentRetryAuthorization::accountOwner(
                        $order,
                        $owner->id,
                    ),
                )
                ->requestHash,
            'attempt_number' => 1,
            'status' => PaymentAttempt::STATUS_PROCESSING,
            'authorization_type' => PaymentAttempt::AUTH_ACCOUNT_OWNER,
            'initiated_by_user_id' => $owner->id,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson(
                $url,
                [],
                ['Idempotency-Key' => $this->paymentAttemptKey('processing')],
            )
            ->assertConflict()
            ->assertJsonPath('error.code', 'payment_attempt_in_progress');

        $this->actingAs($owner, 'sanctum')
            ->postJson(
                $url,
                [],
                ['Idempotency-Key' => $this->paymentAttemptKey('different')],
            )
            ->assertConflict()
            ->assertJsonPath('error.code', 'payment_attempt_in_progress')
            ->assertHeader('Retry-After', '2');

        $this->assertSame(0, $fake->initiationCount);
        $this->assertDatabaseCount('payment_transactions', 1);
    }

    public function test_provider_identity_is_distinct_for_another_order(): void
    {
        $this->enableTestCard();
        $owner = User::factory()->create();
        $first = $this->paymentOrder($owner);
        $second = $this->paymentOrder($owner);
        $service = app(PaymentProviderIdempotencyService::class);
        $hash = hash('sha256', $this->paymentAttemptKey('provider-distinct'));

        $this->assertNotSame(
            $service->derive($hash, $first, 'card'),
            $service->derive($hash, $second, 'card'),
        );
    }

    public function test_existing_pending_payment_is_returned_without_new_provider_or_transaction(): void
    {
        $fake = $this->enableTestCard();
        Event::fake([PaymentAttemptCompleted::class]);
        $owner = User::factory()->create();
        $order = $this->paymentOrder($owner, paymentStatus: 'pending');
        $pending = $this->paymentTransaction($order, 'pending');

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson(
                "/api/v1/account/orders/{$order->id}/payment-attempts",
                [],
                ['Idempotency-Key' => $this->paymentAttemptKey('pending')],
            );

        $response->assertOk()
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.payment.status', 'pending');
        $this->assertSame(0, $fake->initiationCount);
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertSame(
            $pending->id,
            PaymentAttempt::query()->sole()->payment_transaction_id,
        );
        Event::assertNotDispatched(PaymentAttemptCompleted::class);
    }

    public function test_rate_limiter_is_narrow_and_database_idempotency_remains_authoritative(): void
    {
        $this->enableTestCard();
        $owner = User::factory()->create();
        $order = $this->paymentOrder($owner, paymentStatus: 'pending');
        $this->paymentTransaction($order, 'pending');
        $url = "/api/v1/account/orders/{$order->id}/payment-attempts";
        $headers = ['Idempotency-Key' => $this->paymentAttemptKey('limited')];

        for ($request = 1; $request <= 10; $request++) {
            $this->actingAs($owner, 'sanctum')
                ->postJson($url, [], $headers)
                ->assertOk();
        }

        $this->actingAs($owner, 'sanctum')
            ->postJson($url, [], $headers)
            ->assertTooManyRequests();
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertDatabaseCount('payment_transactions', 1);
    }
}
