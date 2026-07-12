<?php

namespace App\Data\Suppliers\Onboarding;

use InvalidArgumentException;
use JsonSerializable;

final readonly class StagingPlan implements JsonSerializable
{
    public const SCHEMA_VERSION = 'supplier-staging-plan-v1';

    public const WRITE_SCOPE = 'supplier_products-only';

    public const MODE = 'create-only';

    /** @var array<int, array<string, mixed>> */
    public readonly array $sourceFingerprints;

    /** @var array<int, array<string, mixed>> */
    public readonly array $warnings;

    /** @var array<int, array<string, mixed>> */
    public readonly array $blockers;

    public function __construct(
        public string $supplierKey,
        array $sourceFingerprints,
        public ?string $candidateFingerprint,
        public int $candidateCount,
        public int $expectedCurrentStagedCount,
        public int $plannedInsertCount,
        public int $plannedUpdateCount = 0,
        public int $plannedSkipCount = 0,
        array $warnings = [],
        array $blockers = [],
    ) {
        if (trim($supplierKey) === '') {
            throw new InvalidArgumentException('Supplier key is required.');
        }

        foreach ([
            'candidate count' => $candidateCount,
            'current staged count' => $expectedCurrentStagedCount,
            'planned insert count' => $plannedInsertCount,
            'planned update count' => $plannedUpdateCount,
            'planned skip count' => $plannedSkipCount,
        ] as $label => $count) {
            if ($count < 0) {
                throw new InvalidArgumentException("{$label} cannot be negative.");
            }
        }

        if ($plannedUpdateCount !== 0) {
            throw new InvalidArgumentException('The onboarding staging plan is create-only.');
        }

        $this->sourceFingerprints = array_map(
            static fn (mixed $fingerprint): array => $fingerprint instanceof SourceFingerprint
                ? $fingerprint->toArray()
                : (array) $fingerprint,
            $sourceFingerprints
        );
        $this->warnings = self::issues($warnings);
        $this->blockers = self::issues($blockers);
        OnboardingValueGuard::assertSafe($this->sourceFingerprints, 'staging plan source fingerprints');
        OnboardingValueGuard::assertSafe($this->warnings, 'staging plan warnings');
        OnboardingValueGuard::assertSafe($this->blockers, 'staging plan blockers');
    }

    /** @param array<int, SourceFingerprint|array<string, mixed>> $sourceFingerprints */
    public static function createOnly(
        string $supplierKey,
        array $sourceFingerprints,
        ?string $candidateFingerprint,
        int $candidateCount,
        int $expectedCurrentStagedCount,
        int $plannedInsertCount,
        int $plannedSkipCount = 0,
        array $warnings = [],
        array $blockers = [],
    ): self {
        return new self(
            supplierKey: $supplierKey,
            sourceFingerprints: $sourceFingerprints,
            candidateFingerprint: $candidateFingerprint,
            candidateCount: $candidateCount,
            expectedCurrentStagedCount: $expectedCurrentStagedCount,
            plannedInsertCount: $plannedInsertCount,
            plannedUpdateCount: 0,
            plannedSkipCount: $plannedSkipCount,
            warnings: $warnings,
            blockers: $blockers,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'schema_version' => self::SCHEMA_VERSION,
            'supplier_key' => $this->supplierKey,
            'write_scope' => self::WRITE_SCOPE,
            'mode' => self::MODE,
            'update_enabled' => false,
            'source_fingerprints' => $this->sourceFingerprints,
            'candidate_fingerprint' => $this->candidateFingerprint,
            'candidate_count' => $this->candidateCount,
            'expected_current_staged_count' => $this->expectedCurrentStagedCount,
            'planned_insert_count' => $this->plannedInsertCount,
            'planned_update_count' => 0,
            'planned_skip_count' => $this->plannedSkipCount,
            'warnings' => $this->warnings,
            'blockers' => $this->blockers,
            'read_only_contract' => true,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @return array<int, array<string, mixed>> */
    private static function issues(array $issues): array
    {
        return array_map(
            static fn (mixed $issue): array => $issue instanceof ValidationIssue ? $issue->toArray() : (array) $issue,
            array_slice(array_values($issues), 0, 50)
        );
    }
}
