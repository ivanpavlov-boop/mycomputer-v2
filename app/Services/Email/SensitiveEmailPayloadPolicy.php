<?php

namespace App\Services\Email;

use App\Models\AbandonedCartRecord;

final class SensitiveEmailPayloadPolicy
{
    public function isSensitive(string $type): bool
    {
        return str_starts_with($type, 'abandoned_cart_');
    }

    public function providerMetadata(string $type, array $data): array
    {
        if (! $this->isSensitive($type)) {
            return ['type' => $type];
        }

        $record = $data['record'] ?? null;

        return [
            'type' => $type,
            'sensitive' => true,
            'reminder_stage' => $this->reminderStage($type),
            'abandoned_cart_record_id' => $record instanceof AbandonedCartRecord
                ? (int) $record->getKey()
                : null,
        ];
    }

    public function persistedPayload(
        string $type,
        string $view,
        array $data,
        array $providerResult,
    ): array {
        if (! $this->isSensitive($type)) {
            return [
                'view' => $view,
                'html' => view()->exists($view) ? view($view, $data)->render() : null,
                'data' => collect($data)->except(['password', 'token'])->all(),
                'provider_result' => $providerResult,
            ];
        }

        return [
            'view' => $view,
            'html_persisted' => false,
            'data' => [
                'reminder_stage' => $this->reminderStage($type),
            ],
            'provider_result' => [
                'provider' => (string) ($providerResult['provider'] ?? 'unknown'),
                'status' => in_array($providerResult['status'] ?? null, ['sent', 'failed'], true)
                    ? $providerResult['status']
                    : 'failed',
            ],
        ];
    }

    private function reminderStage(string $type): ?int
    {
        return preg_match('/\Aabandoned_cart_([1-3])\z/', $type, $matches) === 1
            ? (int) $matches[1]
            : null;
    }
}
