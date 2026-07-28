<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Payments\PaymentActionPresentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPaymentAttemptFixtures;
use Tests\TestCase;

class CommerceCheckoutPaymentPresentationTest extends TestCase
{
    use BuildsPaymentAttemptFixtures;
    use RefreshDatabase;

    public function test_offline_payment_methods_have_safe_bulgarian_states_without_actions(): void
    {
        $this->seed();
        $service = app(PaymentActionPresentationService::class);

        $expectations = [
            'cash_on_delivery' => ['Плащане при доставка', null],
            'bank_transfer' => ['Очаква се банков превод', 'Очаквайте банкови данни'],
            'leasing' => ['Заявката е получена', null],
        ];

        foreach ($expectations as $method => [$label, $instructionFragment]) {
            $order = $this->paymentOrder(method: $method, paymentStatus: 'pending');
            $this->paymentTransaction($order, 'pending');
            $presentation = $service->forCheckoutConfirmation($order);

            $this->assertSame($label, $presentation['status_label']);
            $this->assertSame('none', $presentation['action']['type']);
            $this->assertFalse($presentation['action']['available']);
            $this->assertNull($presentation['redirect_url']);

            if ($instructionFragment !== null) {
                $this->assertStringContainsString(
                    $instructionFragment,
                    $presentation['instructions'],
                );
            }
        }
    }

    public function test_online_states_use_existing_retry_and_redirect_authorities(): void
    {
        $this->enableTestCard();
        $service = app(PaymentActionPresentationService::class);
        $owner = User::factory()->create();

        foreach (['pending', 'authorized'] as $status) {
            $order = $this->paymentOrder($owner, paymentStatus: 'pending');
            $transaction = $this->paymentTransaction($order, $status);
            $transaction->update([
                'raw_response' => [
                    'redirect_url' => 'https://payments.example.test/continue',
                ],
            ]);
            $presentation = $service->forAccountOrder($order->fresh(), $owner);

            $this->assertSame('continue_payment', $presentation['action']['type']);
            $this->assertSame(
                'https://payments.example.test/continue',
                $presentation['redirect_url'],
            );
        }

        foreach (['failed', 'cancelled'] as $status) {
            $order = $this->paymentOrder($owner, paymentStatus: 'failed');
            $this->paymentTransaction($order, $status);
            $presentation = $service->forAccountOrder($order, $owner);

            $this->assertSame($status, $presentation['state']);
            $this->assertSame('retry_payment', $presentation['action']['type']);
            $this->assertNull($presentation['redirect_url']);
        }

        foreach (['paid', 'refunded'] as $status) {
            $order = $this->paymentOrder($owner, paymentStatus: $status);
            $this->paymentTransaction($order, $status);
            $presentation = $service->forAccountOrder($order, $owner);

            $this->assertSame($status, $presentation['state']);
            $this->assertSame('none', $presentation['action']['type']);
        }
    }

    public function test_unsafe_redirect_disabled_method_and_indeterminate_attempt_fail_closed(): void
    {
        $this->enableTestCard();
        $service = app(PaymentActionPresentationService::class);
        $owner = User::factory()->create();
        $order = $this->paymentOrder($owner, paymentStatus: 'pending');
        $transaction = $this->paymentTransaction($order, 'pending');
        $transaction->update([
            'raw_response' => ['redirect_url' => 'http://127.0.0.1/payment'],
        ]);

        $presentation = $service->forAccountOrder($order, $owner);
        $this->assertSame('none', $presentation['action']['type']);
        $this->assertNull($presentation['redirect_url']);

        $transaction->update(['status' => 'failed', 'raw_response' => []]);
        $transaction->method()->update(['status' => 'inactive']);
        $presentation = $service->forAccountOrder($order->fresh(), $owner);
        $this->assertSame('none', $presentation['action']['type']);

        $transaction->method()->update(['status' => 'active']);
        PaymentAttempt::query()->create([
            'reference' => 'PA-indeterminate-presentation',
            'order_id' => $order->getKey(),
            'payment_method_id' => $transaction->payment_method_id,
            'payment_provider_id' => $transaction->payment_provider_id,
            'idempotency_key_hash' => hash('sha256', 'indeterminate-key'),
            'request_hash' => hash('sha256', 'indeterminate-request'),
            'attempt_number' => 1,
            'status' => PaymentAttempt::STATUS_INDETERMINATE,
            'authorization_type' => PaymentAttempt::AUTH_ACCOUNT_OWNER,
            'initiated_by_user_id' => $owner->getKey(),
        ]);

        $presentation = $service->forAccountOrder($order->fresh(), $owner);
        $this->assertSame('indeterminate', $presentation['state']);
        $this->assertSame('none', $presentation['action']['type']);
        $this->assertStringContainsString(
            'Не създавайте нова поръчка',
            $presentation['message'],
        );
    }

    public function test_account_detail_exposes_retry_only_to_direct_order_owner(): void
    {
        $this->enableTestCard();
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $other = User::factory()->create(['email' => 'other@example.test']);
        $direct = $this->paymentOrder($owner, paymentStatus: 'failed');
        $this->paymentTransaction($direct, 'failed');
        $fallback = $this->paymentOrder(paymentStatus: 'failed');
        $fallback->update(['customer_email' => $owner->email]);
        $this->paymentTransaction($fallback, 'failed');

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/account/orders/{$direct->getKey()}")
            ->assertOk()
            ->assertJsonPath(
                'data.payment.presentation.action.type',
                'retry_payment',
            );

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/account/orders/{$fallback->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.payment.presentation.action.type', 'none');

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/v1/account/orders/{$direct->getKey()}")
            ->assertNotFound();
    }

    public function test_presentation_evaluation_is_read_only_and_exposes_no_payment_internals(): void
    {
        $this->enableTestCard();
        $owner = User::factory()->create();
        $order = $this->paymentOrder($owner, paymentStatus: 'failed');
        $this->paymentTransaction($order, 'failed');
        $counts = [
            'orders' => Order::query()->count(),
            'transactions' => PaymentTransaction::query()->count(),
            'attempts' => PaymentAttempt::query()->count(),
        ];
        $updatedAt = $order->updated_at?->toJSON();

        $presentation = app(PaymentActionPresentationService::class)
            ->forAccountOrder($order, $owner);
        $serialized = json_encode($presentation, JSON_THROW_ON_ERROR);

        $this->assertSame($counts['orders'], Order::query()->count());
        $this->assertSame(
            $counts['transactions'],
            PaymentTransaction::query()->count(),
        );
        $this->assertSame($counts['attempts'], PaymentAttempt::query()->count());
        $this->assertSame($updatedAt, $order->fresh()->updated_at?->toJSON());

        foreach ([
            'order_id',
            'payment_attempt_id',
            'payment_transaction_id',
            'provider_reference',
            'transaction_id',
            'raw_response',
            'capability',
            'idempotency',
            'customer_email',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }
}
