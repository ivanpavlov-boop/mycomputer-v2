<?php

namespace App\Http\Resources;

use App\Services\Payments\PaymentAttemptResult;
use App\Services\Payments\PaymentRedirectPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class PaymentAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PaymentAttemptResult $result */
        $result = $this->resource;
        $attempt = $result->attempt;
        $transaction = $result->transaction;
        $method = $transaction->method ?? $attempt->method;
        $rawResponse = is_array($transaction->raw_response)
            ? $transaction->raw_response
            : [];
        $instructions = $rawResponse['instructions'] ?? $method?->instructions;

        return [
            'reference' => $attempt->reference,
            'status' => $attempt->status,
            'replayed' => $result->replayed,
            'payment' => [
                'status' => $transaction->status,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'method' => [
                    'code' => $method?->code,
                    'name' => $method?->name,
                ],
                'redirect_url' => app(PaymentRedirectPolicy::class)
                    ->approved($rawResponse['redirect_url'] ?? null),
                'instructions' => is_string($instructions) && trim($instructions) !== ''
                    ? Str::limit(trim(strip_tags($instructions)), 2000, '')
                    : null,
            ],
        ];
    }
}
