<?php

namespace App\Services\Orders;

use App\Exceptions\CheckoutAlreadyCompletedException;
use App\Exceptions\CheckoutIdempotencyConflictException;
use App\Exceptions\CheckoutIdempotencyKeyInvalidException;
use App\Models\Cart;
use App\Models\CheckoutIdempotencyRecord;
use App\Models\Order;
use Illuminate\Database\QueryException;
use JsonException;
use LogicException;
use SensitiveParameter;

class CheckoutIdempotencyService
{
    public const KEY_BYTES = 32;

    public const KEY_LENGTH = 43;

    private const KEY_PATTERN = '/\A[A-Za-z0-9_-]{43}\z/';

    private const FINGERPRINT_PURPOSE = 'checkout-idempotency-request-v1';

    public function context(
        #[SensitiveParameter]
        mixed $rawKey,
        array $validatedPayload,
        ?string $submittedCartSession,
        ?int $authenticatedUserId,
    ): CheckoutIdempotencyContext {
        if (! is_string($rawKey) || preg_match(self::KEY_PATTERN, $rawKey) !== 1) {
            throw new CheckoutIdempotencyKeyInvalidException;
        }

        return new CheckoutIdempotencyContext(
            keyHash: hash('sha256', $rawKey),
            requestHash: $this->fingerprintPayload($validatedPayload),
            submittedCartSession: $submittedCartSession,
            authenticatedUserId: $authenticatedUserId,
        );
    }

    public function findCompletedReplay(
        CheckoutIdempotencyContext $context,
    ): ?CheckoutIdempotencyRecord {
        $byKey = CheckoutIdempotencyRecord::query()
            ->with(['cart', 'order.paymentTransactions.method'])
            ->where('key_hash', $context->keyHash)
            ->first();

        if ($byKey && $byKey->cart && $this->isReplayAuthorized($byKey->cart, $context)) {
            if (
                $context->submittedCartSession !== null
                && ! hash_equals($byKey->cart->session_id, $context->submittedCartSession)
            ) {
                throw new CheckoutIdempotencyConflictException;
            }

            return $this->validateCompletedRecord($byKey, $context, sameKey: true);
        }

        if ($context->submittedCartSession === null) {
            return null;
        }

        $byCartSession = CheckoutIdempotencyRecord::query()
            ->with(['cart', 'order.paymentTransactions.method'])
            ->whereHas(
                'cart',
                fn ($query) => $query->where('session_id', $context->submittedCartSession),
            )
            ->first();

        if (
            ! $byCartSession
            || ! $byCartSession->cart
            || ! $this->isReplayAuthorized($byCartSession->cart, $context)
        ) {
            return null;
        }

        return $this->validateCompletedRecord($byCartSession, $context, sameKey: false);
    }

    public function lockCompletedReplay(
        Cart $cart,
        CheckoutIdempotencyContext $context,
    ): ?CheckoutIdempotencyRecord {
        $records = CheckoutIdempotencyRecord::query()
            ->with(['cart', 'order.paymentTransactions.method'])
            ->where(function ($query) use ($cart, $context): void {
                $query
                    ->where('cart_id', $cart->getKey())
                    ->orWhere('key_hash', $context->keyHash);
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $byKey = $records->first(
            fn (CheckoutIdempotencyRecord $record): bool => hash_equals(
                $record->key_hash,
                $context->keyHash,
            ),
        );
        $byCart = $records->firstWhere('cart_id', $cart->getKey());

        if ($byKey && (int) $byKey->cart_id !== (int) $cart->getKey()) {
            throw new CheckoutIdempotencyConflictException;
        }

        if (! $byCart) {
            return null;
        }

        abort_unless($this->isReplayAuthorized($cart, $context), 403, 'Cart access is not allowed.');

        return $this->validateCompletedRecord(
            $byCart,
            $context,
            sameKey: $byKey !== null,
        );
    }

    public function startRecord(
        Cart $cart,
        CheckoutIdempotencyContext $context,
    ): CheckoutIdempotencyRecord {
        try {
            return CheckoutIdempotencyRecord::query()->create([
                'cart_id' => $cart->getKey(),
                'key_hash' => $context->keyHash,
                'request_hash' => $context->requestHash,
                'status' => CheckoutIdempotencyRecord::STATUS_PROCESSING,
            ]);
        } catch (QueryException $exception) {
            if ($this->isIdempotencyIdentityConflict($exception)) {
                throw new CheckoutIdempotencyConflictException;
            }

            throw $exception;
        }
    }

    public function completeRecord(
        CheckoutIdempotencyRecord $record,
        Order $order,
    ): CheckoutIdempotencyRecord {
        if (
            $record->status !== CheckoutIdempotencyRecord::STATUS_PROCESSING
            || $record->order_id !== null
        ) {
            throw new LogicException('Checkout idempotency record cannot be completed from its current state.');
        }

        $record->update([
            'order_id' => $order->getKey(),
            'status' => CheckoutIdempotencyRecord::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        return $record->fresh(['cart', 'order.paymentTransactions.method']);
    }

    public function fingerprintPayload(array $validatedPayload): string
    {
        try {
            $canonicalJson = json_encode(
                $this->canonicalize($validatedPayload),
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PRESERVE_ZERO_FRACTION
                    | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new LogicException('Unable to canonicalize the validated checkout payload.', 0, $exception);
        }

        return hash_hmac('sha256', $canonicalJson, $this->fingerprintKey());
    }

    private function validateCompletedRecord(
        CheckoutIdempotencyRecord $record,
        CheckoutIdempotencyContext $context,
        bool $sameKey,
    ): CheckoutIdempotencyRecord {
        if (! hash_equals($record->request_hash, $context->requestHash)) {
            throw $sameKey
                ? new CheckoutIdempotencyConflictException
                : new CheckoutAlreadyCompletedException;
        }

        if (
            $record->status !== CheckoutIdempotencyRecord::STATUS_COMPLETED
            || $record->order_id === null
            || $record->order === null
            || $record->completed_at === null
        ) {
            throw new CheckoutIdempotencyConflictException;
        }

        return $record;
    }

    private function isReplayAuthorized(
        Cart $cart,
        CheckoutIdempotencyContext $context,
    ): bool {
        if ($context->authenticatedUserId !== null) {
            return $cart->user_id !== null
                && (int) $cart->user_id === $context->authenticatedUserId;
        }

        return $cart->user_id === null
            && $context->submittedCartSession !== null
            && hash_equals($cart->session_id, $context->submittedCartSession);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }

    private function fingerprintKey(): string
    {
        $applicationKey = (string) config('app.key');

        if (str_starts_with($applicationKey, 'base64:')) {
            $decoded = base64_decode(substr($applicationKey, 7), true);

            if ($decoded === false) {
                throw new LogicException('The application key cannot derive the checkout fingerprint key.');
            }

            $applicationKey = $decoded;
        }

        if ($applicationKey === '') {
            throw new LogicException('The application key is required for checkout request fingerprints.');
        }

        return hash_hmac('sha256', self::FINGERPRINT_PURPOSE, $applicationKey, true);
    }

    private function isIdempotencyIdentityConflict(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        if (! in_array($sqlState, ['23000', '23505'], true) && $driverCode !== 19) {
            return false;
        }

        return str_contains($exception->getMessage(), 'checkout_idempotency_records');
    }
}
