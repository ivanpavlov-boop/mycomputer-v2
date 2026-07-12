<?php

namespace App\Services\Suppliers\Onboarding;

use App\Contracts\Suppliers\Onboarding\CandidateFingerprintInterface;
use App\Data\Suppliers\Onboarding\CanonicalOnboardingData;
use App\Data\Suppliers\Onboarding\NormalizedSupplierRecord;
use InvalidArgumentException;

final class CandidateFingerprintService implements CandidateFingerprintInterface
{
    public function fingerprint(iterable $records): string
    {
        $canonicalRecords = [];

        foreach ($records as $record) {
            if (! $record instanceof NormalizedSupplierRecord) {
                throw new InvalidArgumentException('Candidate fingerprints require normalized supplier records.');
            }

            $canonicalRecords[] = $record->toCanonicalArray();
        }

        usort(
            $canonicalRecords,
            static fn (array $left, array $right): int => strcmp(
                CanonicalOnboardingData::encode([
                    'supplier_key' => $left['supplier_key'] ?? null,
                    'supplier_sku' => $left['supplier_sku'] ?? null,
                    'record' => $left,
                ]),
                CanonicalOnboardingData::encode([
                    'supplier_key' => $right['supplier_key'] ?? null,
                    'supplier_sku' => $right['supplier_sku'] ?? null,
                    'record' => $right,
                ])
            )
        );

        return hash('sha256', CanonicalOnboardingData::encode([
            'schema_version' => NormalizedSupplierRecord::SCHEMA_VERSION,
            'records' => $canonicalRecords,
        ]));
    }
}
