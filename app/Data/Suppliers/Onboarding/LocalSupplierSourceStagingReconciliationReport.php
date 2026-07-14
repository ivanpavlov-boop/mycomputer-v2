<?php

namespace App\Data\Suppliers\Onboarding;

use JsonSerializable;

final readonly class LocalSupplierSourceStagingReconciliationReport implements JsonSerializable
{
    public const SCHEMA_VERSION = 'local-supplier-source-staging-reconciliation-v1';

    /** @param array<string, mixed> $payload */
    public function __construct(
        public bool $success,
        public string $verdict,
        private array $payload,
    ) {
        OnboardingValueGuard::assertSafe($this->toArray(), 'local supplier source staging reconciliation report');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => 'local_source_staging_reconciliation',
            'read_only' => true,
            'success' => $this->success,
            'verdict' => $this->verdict,
            ...$this->payload,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
