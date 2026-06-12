<?php

namespace App\Services\Erp\Microinvest;

class MicroinvestApiClient
{
    public function __construct(
        private readonly MicroinvestConfig $config,
    ) {}

    public function externalCallsEnabled(): bool
    {
        return false;
    }

    public function testConnection(): array
    {
        return $this->placeholderResponse('connection_test');
    }

    public function pushCustomer(array $payload): array
    {
        return $this->placeholderResponse('push_customer', $payload);
    }

    public function pushOrder(array $payload): array
    {
        return $this->placeholderResponse('push_order', $payload);
    }

    public function createInvoice(array $payload): array
    {
        return $this->placeholderResponse('create_invoice', $payload);
    }

    public function pushPayment(array $payload): array
    {
        return $this->placeholderResponse('push_payment', $payload);
    }

    public function pullStock(array $payload): array
    {
        return [
            ...$this->placeholderResponse('pull_stock', $payload),
            'items' => [],
        ];
    }

    public function pullProducts(): array
    {
        return [
            ...$this->placeholderResponse('pull_products'),
            'items' => [],
        ];
    }

    public function getDocument(string $externalId): array
    {
        return $this->placeholderResponse('get_document', ['external_id' => $externalId]);
    }

    public function cancelDocument(string $externalId): array
    {
        return $this->placeholderResponse('cancel_document', ['external_id' => $externalId]);
    }

    private function placeholderResponse(string $operation, array $payload = []): array
    {
        return [
            'success' => false,
            'status' => $this->config->isConfigured() ? 'unsupported' : 'not_configured',
            'operation' => $operation,
            'message' => $this->config->isConfigured()
                ? 'Microinvest external communication is not implemented yet. No external request was sent.'
                : 'Microinvest provider is not configured. No external request was sent.',
            'external_calls_enabled' => false,
            'config' => $this->config->safeArray(),
            'payload' => $payload,
        ];
    }
}
