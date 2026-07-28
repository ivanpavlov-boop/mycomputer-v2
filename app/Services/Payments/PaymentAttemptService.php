<?php

namespace App\Services\Payments;

use App\Events\PaymentAttemptCompleted;
use App\Exceptions\PaymentAttemptInProgressException;
use App\Exceptions\PaymentIdempotencyConflictException;
use App\Exceptions\PaymentProviderDefinitiveFailureException;
use App\Exceptions\PaymentProviderIndeterminateException;
use App\Exceptions\PaymentRetryNotAllowedException;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

class PaymentAttemptService
{
    public function __construct(
        private readonly PaymentAttemptIdempotencyService $idempotency,
        private readonly PaymentRetryAuthorizationService $authorization,
        private readonly PaymentRetryPolicy $policy,
        private readonly PaymentProviderResolver $providers,
        private readonly PaymentRedirectPolicy $redirects,
    ) {}

    public function attempt(
        PaymentRetryAuthorization $authorization,
        #[SensitiveParameter]
        mixed $clientKey,
    ): PaymentAttemptResult {
        try {
            $outcome = DB::transaction(function () use ($authorization, $clientKey): PaymentAttemptResult|\Throwable {
                $order = Order::query()
                    ->lockForUpdate()
                    ->find($authorization->order->getKey());

                if (! $order) {
                    return new PaymentRetryNotAllowedException;
                }

                $this->authorization->recheck($authorization, $order);
                $context = $this->idempotency->context($clientKey, $order, $authorization);
                $existingAttempt = PaymentAttempt::query()
                    ->with(['transaction.method'])
                    ->lockForUpdate()
                    ->where('idempotency_key_hash', $context->keyHash)
                    ->first();

                if ($existingAttempt) {
                    return $this->replayOrResume(
                        $existingAttempt,
                        $context,
                        $order,
                    );
                }

                $blockingAttempt = PaymentAttempt::query()
                    ->lockForUpdate()
                    ->where('order_id', $order->getKey())
                    ->whereIn('status', [
                        PaymentAttempt::STATUS_PROCESSING,
                        PaymentAttempt::STATUS_INDETERMINATE,
                    ])
                    ->first();

                if ($blockingAttempt) {
                    return new PaymentAttemptInProgressException;
                }

                $method = $this->lockedMethod($order);
                $latestTransaction = $this->latestTransaction($order);
                $decision = $this->policy->decide($order, $method, $latestTransaction);

                if (! $decision->initiateProvider) {
                    return $this->existingPaymentResult(
                        $order,
                        $method,
                        $decision->existingTransaction,
                        $context,
                        $authorization,
                    );
                }

                $attempt = $this->createAttempt(
                    $order,
                    $method,
                    $context,
                    $authorization,
                );

                return $this->invokeProvider(
                    $attempt,
                    $order,
                    $method,
                    $context,
                );
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $keyHash = is_string($clientKey) ? hash('sha256', $clientKey) : null;

            if (
                $keyHash !== null
                && PaymentAttempt::query()
                    ->where('idempotency_key_hash', $keyHash)
                    ->exists()
            ) {
                throw new PaymentIdempotencyConflictException;
            }

            throw $exception;
        }

        if ($outcome instanceof \Throwable) {
            throw $outcome;
        }

        return $outcome;
    }

    private function replayOrResume(
        PaymentAttempt $attempt,
        PaymentAttemptIdempotencyContext $context,
        Order $order,
    ): PaymentAttemptResult|\Throwable {
        if (! hash_equals($attempt->request_hash, $context->requestHash)) {
            return new PaymentIdempotencyConflictException;
        }

        if ($attempt->status === PaymentAttempt::STATUS_COMPLETED) {
            $transaction = $attempt->transaction
                ?? $this->latestTransaction($order);

            if (! $transaction) {
                return new PaymentRetryNotAllowedException;
            }

            return new PaymentAttemptResult(
                $attempt->loadMissing(['method', 'provider']),
                $transaction->loadMissing(['method', 'provider']),
                replayed: true,
            );
        }

        if ($attempt->status === PaymentAttempt::STATUS_PROCESSING) {
            return new PaymentAttemptInProgressException;
        }

        if ($attempt->status === PaymentAttempt::STATUS_FAILED) {
            return new PaymentProviderDefinitiveFailureException(
                $attempt->failure_code ?: 'provider_rejected',
            );
        }

        $method = $this->lockedMethod($order);
        $latestTransaction = $this->latestTransaction($order);
        $decision = $this->policy->decide($order, $method, $latestTransaction);

        if (! $decision->initiateProvider) {
            return $this->existingPaymentResult(
                $order,
                $method,
                $decision->existingTransaction,
                $context,
                new PaymentRetryAuthorization(
                    $order,
                    $attempt->authorization_type,
                    $attempt->initiated_by_user_id,
                ),
            );
        }

        $attempt->update([
            'status' => PaymentAttempt::STATUS_PROCESSING,
            'failed_at' => null,
            'failure_code' => null,
        ]);

        return $this->invokeProvider($attempt, $order, $method, $context);
    }

    private function existingPaymentResult(
        Order $order,
        PaymentMethod $method,
        ?PaymentTransaction $transaction,
        PaymentAttemptIdempotencyContext $context,
        PaymentRetryAuthorization $authorization,
    ): PaymentAttemptResult|\Throwable {
        if (! $transaction) {
            return new PaymentRetryNotAllowedException;
        }

        $linkedAttempt = PaymentAttempt::query()
            ->with(['method', 'provider'])
            ->lockForUpdate()
            ->where('payment_transaction_id', $transaction->getKey())
            ->first();

        if ($linkedAttempt) {
            return new PaymentAttemptResult(
                $linkedAttempt,
                $transaction->loadMissing(['method', 'provider']),
                replayed: true,
            );
        }

        $attempt = $this->createAttempt(
            $order,
            $method,
            $context,
            $authorization,
            [
                'payment_transaction_id' => $transaction->getKey(),
                'provider_reference' => $transaction->transaction_id,
                'status' => PaymentAttempt::STATUS_COMPLETED,
                'completed_at' => now(),
            ],
        );

        return new PaymentAttemptResult(
            $attempt->loadMissing(['method', 'provider']),
            $transaction->loadMissing(['method', 'provider']),
            replayed: true,
        );
    }

    private function invokeProvider(
        PaymentAttempt $attempt,
        Order $order,
        PaymentMethod $method,
        PaymentAttemptIdempotencyContext $context,
    ): PaymentAttemptResult|\Throwable {
        $provider = $this->providers->resolve($method);

        if (! $provider || ! $provider->isOperational()) {
            return $this->failAttempt($attempt, 'provider_unavailable');
        }

        try {
            $response = $provider->initiatePayment(
                $order,
                new PaymentInitiationContext(
                    instructions: $method->instructions,
                    providerIdempotencyKey: $context->providerIdempotencyKey,
                    attemptReference: $attempt->reference,
                    isRetry: true,
                ),
            );
        } catch (PaymentProviderDefinitiveFailureException $exception) {
            return $this->failAttempt($attempt, $exception->failureCode);
        } catch (PaymentProviderIndeterminateException) {
            return $this->markIndeterminate($attempt);
        }

        $status = $response['status'] ?? null;
        $providerReference = $response['transaction_id'] ?? null;

        if (
            ! is_string($status)
            || ! in_array($status, PaymentTransaction::STATUSES, true)
            || ! is_string($providerReference)
            || trim($providerReference) === ''
        ) {
            return $this->markIndeterminate($attempt);
        }

        $duplicateReference = PaymentAttempt::query()
            ->where('payment_provider_id', $method->payment_provider_id)
            ->where('provider_reference', $providerReference)
            ->whereKeyNot($attempt->getKey())
            ->exists();

        if ($duplicateReference) {
            return $this->markIndeterminate($attempt);
        }

        $safeResponse = [
            'redirect_url' => $this->redirects->approved($response['redirect_url'] ?? null),
            'instructions' => $this->safeInstructions($response['instructions'] ?? null),
        ];
        $transaction = $order->paymentTransactions()->create([
            'payment_provider_id' => $method->payment_provider_id,
            'payment_method_id' => $method->getKey(),
            'transaction_id' => $providerReference,
            'amount' => $order->grand_total,
            'currency' => 'EUR',
            'status' => $status,
            'raw_request' => ['payment_method_code' => $method->code],
            'raw_response' => $safeResponse,
            'paid_at' => $status === 'paid' ? now() : null,
            'failed_at' => $status === 'failed' ? now() : null,
        ]);

        if (in_array($status, ['failed', 'cancelled'], true)) {
            $attempt->update([
                'payment_transaction_id' => $transaction->getKey(),
                'provider_reference' => $providerReference,
                'status' => PaymentAttempt::STATUS_FAILED,
                'failed_at' => now(),
                'failure_code' => 'provider_rejected',
            ]);

            return new PaymentProviderDefinitiveFailureException;
        }

        if (! in_array($order->payment_status, ['paid', 'refunded'], true)) {
            $order->update([
                'payment_status' => match ($status) {
                    'paid' => 'paid',
                    'refunded' => 'refunded',
                    default => 'pending',
                },
            ]);
        }

        $attempt->update([
            'payment_transaction_id' => $transaction->getKey(),
            'provider_reference' => $providerReference,
            'status' => PaymentAttempt::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
        PaymentAttemptCompleted::dispatch($attempt->getKey());

        return new PaymentAttemptResult(
            $attempt->loadMissing(['method', 'provider']),
            $transaction->loadMissing(['method', 'provider']),
            replayed: false,
        );
    }

    private function createAttempt(
        Order $order,
        PaymentMethod $method,
        PaymentAttemptIdempotencyContext $context,
        PaymentRetryAuthorization $authorization,
        array $overrides = [],
    ): PaymentAttempt {
        $nextAttempt = (int) PaymentAttempt::query()
            ->where('order_id', $order->getKey())
            ->max('attempt_number') + 1;

        return PaymentAttempt::query()->create($overrides + [
            'reference' => 'PA-'.Str::ulid(),
            'order_id' => $order->getKey(),
            'payment_method_id' => $method->getKey(),
            'payment_provider_id' => $method->payment_provider_id,
            'idempotency_key_hash' => $context->keyHash,
            'request_hash' => $context->requestHash,
            'attempt_number' => $nextAttempt,
            'status' => PaymentAttempt::STATUS_PROCESSING,
            'authorization_type' => $authorization->type,
            'initiated_by_user_id' => $authorization->userId,
        ]);
    }

    private function lockedMethod(Order $order): PaymentMethod
    {
        return PaymentMethod::query()
            ->with('provider')
            ->lockForUpdate()
            ->where('code', $order->payment_method)
            ->first()
            ?? throw new PaymentRetryNotAllowedException;
    }

    private function latestTransaction(Order $order): ?PaymentTransaction
    {
        return PaymentTransaction::query()
            ->with(['method', 'provider'])
            ->lockForUpdate()
            ->where('order_id', $order->getKey())
            ->latest('id')
            ->first();
    }

    private function failAttempt(
        PaymentAttempt $attempt,
        string $failureCode,
    ): PaymentProviderDefinitiveFailureException {
        $safeCode = preg_match('/\A[a-z0-9_]{1,64}\z/', $failureCode) === 1
            ? $failureCode
            : 'provider_rejected';

        $attempt->update([
            'status' => PaymentAttempt::STATUS_FAILED,
            'failed_at' => now(),
            'failure_code' => $safeCode,
        ]);

        return new PaymentProviderDefinitiveFailureException($safeCode);
    }

    private function markIndeterminate(
        PaymentAttempt $attempt,
    ): PaymentProviderIndeterminateException {
        $attempt->update([
            'status' => PaymentAttempt::STATUS_INDETERMINATE,
            'failure_code' => null,
        ]);

        return new PaymentProviderIndeterminateException;
    }

    private function safeInstructions(mixed $instructions): ?string
    {
        if (! is_string($instructions) || trim($instructions) === '') {
            return null;
        }

        return Str::limit(trim(strip_tags($instructions)), 2000, '');
    }
}
