<?php

namespace App\Data\Suppliers\Onboarding;

use InvalidArgumentException;
use JsonSerializable;

final readonly class PostApplyVerificationResult implements JsonSerializable
{
    public const SCHEMA_VERSION = 'supplier-post-apply-verification-v1';

    /** @var array<string, int> */
    public readonly array $recordsChanged;

    /** @var array<string, int> */
    public readonly array $issueCounts;

    /** @var array<int, array<string, mixed>> */
    public readonly array $issueSamples;

    public function __construct(
        public bool $sourceFingerprintMatch,
        public bool $candidateFingerprintMatch,
        public bool $candidateCountMatch,
        public bool $exactSkuReconciliation,
        public bool $canonicalRowReconciliation,
        public bool $provenanceVerification,
        public bool $pricingVerification,
        public bool $availabilityVerification,
        public bool $truncationSchemaVerification,
        public int $linkedProductCount = 0,
        array $recordsChanged = [],
        array $issueCounts = [],
        array $issueSamples = [],
        public VerificationVerdict $verdict = VerificationVerdict::VERIFICATION_FAILED,
    ) {
        if ($linkedProductCount < 0) {
            throw new InvalidArgumentException('Linked product count cannot be negative.');
        }

        $this->recordsChanged = self::recordsChanged($recordsChanged);
        $this->issueCounts = self::integerMap($issueCounts);
        $this->issueSamples = array_slice(array_values($issueSamples), 0, 20);
        OnboardingValueGuard::assertSafe($this->issueSamples, 'verification issue samples');
    }

    public function isVerified(): bool
    {
        return $this->verdict === VerificationVerdict::VERIFIED
            && $this->sourceFingerprintMatch
            && $this->candidateFingerprintMatch
            && $this->candidateCountMatch
            && $this->exactSkuReconciliation
            && $this->canonicalRowReconciliation
            && $this->provenanceVerification
            && $this->pricingVerification
            && $this->availabilityVerification
            && $this->truncationSchemaVerification
            && $this->linkedProductCount === 0
            && array_filter($this->recordsChanged) === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'schema_version' => self::SCHEMA_VERSION,
            'source_fingerprint_match' => $this->sourceFingerprintMatch,
            'candidate_fingerprint_match' => $this->candidateFingerprintMatch,
            'candidate_count_match' => $this->candidateCountMatch,
            'exact_sku_reconciliation' => $this->exactSkuReconciliation,
            'canonical_row_reconciliation' => $this->canonicalRowReconciliation,
            'provenance_verification' => $this->provenanceVerification,
            'pricing_verification' => $this->pricingVerification,
            'availability_verification' => $this->availabilityVerification,
            'truncation_schema_verification' => $this->truncationSchemaVerification,
            'linked_product_count' => $this->linkedProductCount,
            'records_changed' => $this->recordsChanged,
            'issue_counts' => $this->issueCounts,
            'issue_samples' => $this->issueSamples,
            'verdict' => $this->verdict->value,
            'read_only' => true,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @return array<string, int> */
    private static function recordsChanged(array $values): array
    {
        $keys = [
            'products',
            'supplier_products',
            'suppliers',
            'categories',
            'supplier_category_mappings',
            'canonical_product_families',
            'category_product_attributes',
            'product_attributes',
            'attribute_values',
            'product_attribute_values',
            'catalog_sync_batches',
            'catalog_sync_logs',
            'catalog_sync',
        ];
        $normalized = [];

        foreach ($keys as $key) {
            $value = $values[$key] ?? 0;

            if (! is_int($value) || $value < 0) {
                throw new InvalidArgumentException('Verification change counters must be non-negative integers.');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /** @return array<string, int> */
    private static function integerMap(array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            if (! is_int($value) || $value < 0) {
                throw new InvalidArgumentException('Verification issue counts must be non-negative integers.');
            }

            $normalized[(string) $key] = $value;
        }

        ksort($normalized);

        return $normalized;
    }
}
