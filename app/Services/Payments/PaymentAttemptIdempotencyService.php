<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentIdempotencyKeyInvalidException;
use App\Models\Order;
use LogicException;
use SensitiveParameter;

class PaymentAttemptIdempotencyService
{
    private const FINGERPRINT_PURPOSE = 'payment-attempt-request-v1';

    private const KEY_PATTERN = '/\A[A-Za-z0-9_-]{43}\z/';

    public function __construct(
        private readonly PaymentProviderIdempotencyService $providerIdentity,
    ) {}

    public function context(
        #[SensitiveParameter]
        mixed $clientKey,
        Order $order,
        PaymentRetryAuthorization $authorization,
    ): PaymentAttemptIdempotencyContext {
        if (! is_string($clientKey) || preg_match(self::KEY_PATTERN, $clientKey) !== 1) {
            throw new PaymentIdempotencyKeyInvalidException;
        }

        $keyHash = hash('sha256', $clientKey);
        $fingerprint = json_encode([
            'purpose' => self::FINGERPRINT_PURPOSE,
            'order_id' => $order->getKey(),
            'payment_method' => $order->payment_method,
            'authorization_type' => $authorization->type,
            'operation' => 'retry_existing_payment_method',
        ], JSON_THROW_ON_ERROR);

        return new PaymentAttemptIdempotencyContext(
            keyHash: $keyHash,
            requestHash: hash_hmac('sha256', $fingerprint, $this->fingerprintKey()),
            providerIdempotencyKey: $this->providerIdentity->derive(
                $keyHash,
                $order,
                $order->payment_method,
            ),
        );
    }

    private function fingerprintKey(): string
    {
        $applicationKey = (string) config('app.key');

        if (str_starts_with($applicationKey, 'base64:')) {
            $decoded = base64_decode(substr($applicationKey, 7), true);

            if ($decoded === false) {
                throw new LogicException('The application key cannot derive payment fingerprints.');
            }

            $applicationKey = $decoded;
        }

        if ($applicationKey === '') {
            throw new LogicException('The application key is required for payment fingerprints.');
        }

        return hash_hmac('sha256', self::FINGERPRINT_PURPOSE, $applicationKey, true);
    }
}
