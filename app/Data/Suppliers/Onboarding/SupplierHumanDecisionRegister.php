<?php

namespace App\Data\Suppliers\Onboarding;

use JsonSerializable;

final readonly class SupplierHumanDecisionRegister implements JsonSerializable
{
    /** @param array<int, SupplierHumanDecision> $decisions */
    public function __construct(
        public string $key,
        public string $supplierKey,
        public array $decisions,
    ) {
        OnboardingValueGuard::assertSafe($this->toArray(), 'supplier human decision register');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $decisions = $this->decisions;
        usort($decisions, static fn (SupplierHumanDecision $left, SupplierHumanDecision $right): int => $left->decisionId <=> $right->decisionId);

        return CanonicalOnboardingData::normalize([
            'key' => $this->key,
            'supplier_key' => $this->supplierKey,
            'decision_count' => count($decisions),
            'decisions' => array_map(static fn (SupplierHumanDecision $decision): array => $decision->toArray(), $decisions),
            'read_only' => true,
            'persisted' => false,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
