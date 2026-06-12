<?php

namespace App\Services\Payments\Webhooks;

use App\Services\Payments\Contracts\WebhookSignatureValidatorInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class MockSignatureValidator implements WebhookSignatureValidatorInterface
{
    public function validate(string $provider, Request $request): bool
    {
        $timestamp = (int) $request->header('X-Webhook-Timestamp', 0);
        $signature = (string) $request->header('X-Webhook-Signature', '');
        $eventId = (string) $request->header('X-Webhook-Id', sha1($request->getContent()));

        if ($timestamp < now()->subMinutes(10)->timestamp || $timestamp > now()->addMinutes(2)->timestamp) {
            return false;
        }

        $cacheKey = "webhook-replay:{$provider}:{$eventId}";
        if (Cache::has($cacheKey)) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), config('services.webhooks.mock_secret', 'local-webhook-secret'));
        $valid = hash_equals($expected, $signature) || ($signature === 'test-signature' && app()->environment('testing'));

        if ($valid) {
            Cache::put($cacheKey, true, now()->addMinutes(15));
        }

        return $valid;
    }
}
