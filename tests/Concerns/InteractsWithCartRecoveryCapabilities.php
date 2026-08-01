<?php

namespace Tests\Concerns;

use App\Models\AbandonedCartRecord;
use App\Services\CartRecovery\CartRecoveryCapabilityService;

trait InteractsWithCartRecoveryCapabilities
{
    /** @var array<int, string> */
    private array $issuedRecoveryCapabilities = [];

    protected function issueRecoveryCapability(AbandonedCartRecord $record): string
    {
        $capability = app(CartRecoveryCapabilityService::class)
            ->issue($record)
            ->value();
        $this->issuedRecoveryCapabilities[(int) $record->getKey()] = $capability;

        return $capability;
    }

    protected function recoveryCapability(AbandonedCartRecord $record): string
    {
        return $this->issuedRecoveryCapabilities[(int) $record->getKey()]
            ?? $this->issueRecoveryCapability($record);
    }

    protected function recoveryRequest(string $capability): array
    {
        return ['capability' => $capability];
    }
}
