<?php

namespace App\Data\Suppliers\Onboarding;

use JsonSerializable;

final readonly class OperationalSupplierOfferEvidenceBundle implements JsonSerializable
{
    public const SCHEMA_VERSION = 'supplier-offer-lifecycle-operational-evidence-v1';

    public string $sourceIdentity;

    /**
     * @param  array<int, string>  $supplierScope
     * @param  array<string, string>  $policyVersions
     * @param  array<string, array{policy_key: string, max_age_hours: int, approved: bool}>  $freshnessPolicies
     * @param  array<int, array<string, mixed>>  $snapshots
     * @param  array<string, array{continuous_qualified_absence_proven: bool, zero_active_offers_since: ?string}>  $productLifecycleEvidence
     */
    public function __construct(
        public string $evidenceFingerprint,
        public string $supplierKey,
        public array $supplierScope,
        public array $policyVersions,
        mixed $sourceIdentity,
        public array $freshnessPolicies,
        public array $snapshots,
        public array $productLifecycleEvidence,
    ) {
        $this->sourceIdentity = OperationalSupplierSourceIdentityMap::assertStable(
            $this->snapshots,
            $this->supplierKey,
            $sourceIdentity,
        );
        OnboardingValueGuard::assertSafe($this->toArray(), 'operational supplier offer evidence bundle');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'evidence_fingerprint' => $this->evidenceFingerprint,
            'freshness_policies' => $this->freshnessPolicies,
            'policy_versions' => $this->policyVersions,
            'product_lifecycle_evidence' => $this->productLifecycleEvidence,
            'schema_version' => self::SCHEMA_VERSION,
            'snapshots' => $this->snapshots,
            'source_identity' => $this->sourceIdentity,
            'supplier' => $this->supplierKey,
            'supplier_scope' => $this->supplierScope,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
