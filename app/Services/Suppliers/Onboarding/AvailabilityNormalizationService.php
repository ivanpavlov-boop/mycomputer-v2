<?php

namespace App\Services\Suppliers\Onboarding;

use App\Contracts\Suppliers\Onboarding\AvailabilityNormalizationInterface;
use App\Data\Suppliers\Onboarding\AvailabilityNormalizationResult;

final class AvailabilityNormalizationService implements AvailabilityNormalizationInterface
{
    private const QUANTITY_THRESHOLD = 3;

    public function normalize(
        ?string $externalCode,
        ?string $externalLabel,
        ?int $quantity,
        array $mapping = [],
        string $profileVersion = 'unknown',
    ): AvailabilityNormalizationResult {
        if ($quantity !== null && $quantity < 0) {
            return new AvailabilityNormalizationResult(
                externalCode: $externalCode,
                externalLabel: $externalLabel,
                normalizedKey: null,
                quantity: $quantity,
                mappingConfidence: 'invalid',
                profileVersion: $profileVersion,
                errors: ['quantity_negative'],
                valid: false,
            );
        }

        $normalizedMapping = [];

        foreach ($mapping as $key => $value) {
            if (trim((string) $key) !== '' && trim($value) !== '') {
                $normalizedMapping[strtolower(trim((string) $key))] = trim($value);
            }
        }

        foreach ([$externalCode, $externalLabel] as $candidate) {
            $candidateKey = $candidate === null ? null : strtolower(trim($candidate));

            if ($candidateKey !== null && isset($normalizedMapping[$candidateKey])) {
                return $this->result($externalCode, $externalLabel, $quantity, $normalizedMapping[$candidateKey], 'explicit', $profileVersion);
            }
        }

        foreach ([$externalCode, $externalLabel] as $candidate) {
            $normalized = $this->standardize($candidate);

            if ($normalized !== null) {
                return $this->result($externalCode, $externalLabel, $quantity, $normalized, 'standard', $profileVersion);
            }
        }

        if ($quantity !== null) {
            $normalized = $quantity <= 0
                ? 'out_of_stock'
                : ($quantity <= self::QUANTITY_THRESHOLD ? 'limited_stock' : 'in_stock');

            return $this->result($externalCode, $externalLabel, $quantity, $normalized, 'quantity', $profileVersion, ['availability_inferred_from_quantity']);
        }

        return new AvailabilityNormalizationResult(
            externalCode: $externalCode,
            externalLabel: $externalLabel,
            normalizedKey: null,
            quantity: null,
            mappingConfidence: 'unknown',
            profileVersion: $profileVersion,
            warnings: ['availability_unmapped'],
            valid: false,
        );
    }

    private function standardize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match (strtolower(trim($value))) {
            'in_stock', 'in stock', 'available', 'availability_in_stock' => 'in_stock',
            'limited_stock', 'limited stock', 'low stock' => 'limited_stock',
            'out_of_stock', 'out of stock', 'unavailable', 'not available' => 'out_of_stock',
            'on_request', 'on request', 'preorder', 'pre-order' => 'on_request',
            default => null,
        };
    }

    /** @param array<int, string> $warnings */
    private function result(?string $code, ?string $label, ?int $quantity, string $normalized, string $confidence, string $profileVersion, array $warnings = []): AvailabilityNormalizationResult
    {
        return new AvailabilityNormalizationResult(
            externalCode: $code,
            externalLabel: $label,
            normalizedKey: $normalized,
            quantity: $quantity,
            mappingConfidence: $confidence,
            profileVersion: $profileVersion,
            warnings: $warnings,
        );
    }
}
