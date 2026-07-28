<?php

namespace Tests\Feature;

use App\Events\PaymentAttemptCompleted;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentTransaction;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Concerns\BuildsPaymentAttemptFixtures;
use Tests\TestCase;

class PaymentAttemptAtomicityTest extends TestCase
{
    use BuildsPaymentAttemptFixtures;
    use RefreshDatabase;

    public function test_indeterminate_attempt_requires_same_key_and_reuses_provider_identity(): void
    {
        $fake = $this->enableTestCard();
        $fake->failureMode = 'indeterminate';
        Event::fake([PaymentAttemptCompleted::class]);
        $owner = User::factory()->create();
        $order = $this->paymentOrder($owner);
        $this->paymentTransaction($order);
        $key = $this->paymentAttemptKey('indeterminate');
        $url = "/api/v1/account/orders/{$order->id}/payment-attempts";

        $this->actingAs($owner, 'sanctum')
            ->postJson($url, [], ['Idempotency-Key' => $key])
            ->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'payment_result_indeterminate');
        $this->assertSame(
            PaymentAttempt::STATUS_INDETERMINATE,
            PaymentAttempt::query()->sole()->status,
        );
        $this->assertDatabaseCount('payment_transactions', 1);

        $this->actingAs($owner, 'sanctum')
            ->postJson(
                $url,
                [],
                ['Idempotency-Key' => $this->paymentAttemptKey('blocked-by-indeterminate')],
            )
            ->assertConflict()
            ->assertJsonPath('error.code', 'payment_attempt_in_progress');

        $fake->failureMode = null;
        $this->actingAs($owner, 'sanctum')
            ->postJson($url, [], ['Idempotency-Key' => $key])
            ->assertCreated();

        $this->assertSame(2, $fake->initiationCount);
        $this->assertSame(
            $fake->providerIdempotencyKeys[0],
            $fake->providerIdempotencyKeys[1],
        );
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertDatabaseCount('payment_transactions', 2);
        Event::assertDispatchedTimes(PaymentAttemptCompleted::class, 1);
    }

    public function test_definitive_failure_is_persisted_without_partial_transaction_or_event(): void
    {
        $fake = $this->enableTestCard();
        $fake->failureMode = 'definitive';
        Event::fake([PaymentAttemptCompleted::class]);
        $owner = User::factory()->create();
        $order = $this->paymentOrder($owner);
        $this->paymentTransaction($order);

        $this->actingAs($owner, 'sanctum')
            ->postJson(
                "/api/v1/account/orders/{$order->id}/payment-attempts",
                [],
                ['Idempotency-Key' => $this->paymentAttemptKey('definitive')],
            )
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'payment_provider_failed');

        $attempt = PaymentAttempt::query()->sole();
        $this->assertSame(PaymentAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame('provider_rejected', $attempt->failure_code);
        $this->assertNull($attempt->payment_transaction_id);
        $this->assertDatabaseCount('payment_transactions', 1);
        Event::assertNotDispatched(PaymentAttemptCompleted::class);
    }

    public function test_failure_after_attempt_creation_rolls_back_everything(): void
    {
        $this->assertRollbackAt(
            function (): void {
                PaymentAttempt::created(function (): never {
                    throw new RuntimeException('Forced failure after attempt creation.');
                });
            },
            'after-attempt',
            expectedProviderCalls: 0,
        );
    }

    public function test_failure_after_transaction_creation_rolls_back_everything(): void
    {
        $this->assertRollbackAt(
            function (): void {
                PaymentTransaction::created(function (PaymentTransaction $transaction): void {
                    if (str_starts_with($transaction->transaction_id, 'TEST-CARD-')) {
                        throw new RuntimeException('Forced failure after transaction creation.');
                    }
                });
            },
            'after-transaction',
            expectedProviderCalls: 1,
        );
    }

    public function test_failure_before_order_payment_status_update_rolls_back_everything(): void
    {
        $this->assertRollbackAt(
            function (): void {
                Order::updating(function (Order $order): void {
                    if ($order->isDirty('payment_status')) {
                        throw new RuntimeException('Forced failure before payment status update.');
                    }
                });
            },
            'before-order-update',
            expectedProviderCalls: 1,
        );
    }

    public function test_failure_before_commit_rolls_back_everything(): void
    {
        $this->assertRollbackAt(
            function (): void {
                PaymentAttempt::updated(function (PaymentAttempt $attempt): void {
                    if ($attempt->status === PaymentAttempt::STATUS_COMPLETED) {
                        throw new RuntimeException('Forced failure before commit.');
                    }
                });
            },
            'before-commit',
            expectedProviderCalls: 1,
        );
    }

    private function assertRollbackAt(
        Closure $registerFailure,
        string $keySuffix,
        int $expectedProviderCalls,
    ): void {
        $fake = $this->enableTestCard();
        Event::fake([PaymentAttemptCompleted::class]);
        $owner = User::factory()->create();
        $order = $this->paymentOrder($owner);
        $this->paymentTransaction($order);
        $registerFailure();

        $this->actingAs($owner, 'sanctum')
            ->postJson(
                "/api/v1/account/orders/{$order->id}/payment-attempts",
                [],
                ['Idempotency-Key' => $this->paymentAttemptKey($keySuffix)],
            )
            ->assertServerError();

        $this->assertSame($expectedProviderCalls, $fake->initiationCount);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertSame('failed', $order->fresh()->payment_status);
        Event::assertNotDispatched(PaymentAttemptCompleted::class);
    }
}
