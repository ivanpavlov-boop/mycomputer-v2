<?php

namespace App\Services\Suppliers\Onboarding;

use App\Contracts\Suppliers\Onboarding\PriceNormalizationInterface;
use App\Data\Suppliers\Onboarding\DecimalNormalizer;
use App\Data\Suppliers\Onboarding\PriceNormalizationResult;
use Throwable;

final class PriceNormalizationService implements PriceNormalizationInterface
{
    public function normalize(
        mixed $rawSupplierPrice,
        string $sourceCurrency,
        string $normalizedCurrency = 'EUR',
        string $taxInterpretation = 'profile-defined',
    ): PriceNormalizationResult {
        $sourceCurrency = strtoupper(trim($sourceCurrency));
        $normalizedCurrency = strtoupper(trim($normalizedCurrency));

        if (preg_match('/^[A-Z]{3}$/', $sourceCurrency) !== 1 || preg_match('/^[A-Z]{3}$/', $normalizedCurrency) !== 1) {
            return $this->invalid($rawSupplierPrice, $sourceCurrency, $normalizedCurrency, $taxInterpretation, ['currency_invalid']);
        }

        if (trim($taxInterpretation) === '') {
            return $this->invalid($rawSupplierPrice, $sourceCurrency, $normalizedCurrency, 'profile-defined', ['tax_interpretation_missing']);
        }

        try {
            $canonical = DecimalNormalizer::canonical($rawSupplierPrice);
        } catch (Throwable) {
            return $this->invalid($rawSupplierPrice, $sourceCurrency, $normalizedCurrency, $taxInterpretation, ['price_invalid']);
        }

        if ($canonical === null) {
            return $this->invalid($rawSupplierPrice, $sourceCurrency, $normalizedCurrency, $taxInterpretation, ['price_missing']);
        }

        $negativeDetected = str_starts_with($canonical, '-');
        $unsigned = ltrim($canonical, '-');
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $overflowDetected = strlen($integer) > 10;

        if ($negativeDetected || $overflowDetected || strlen($fraction) > 2) {
            $errors = [];

            if ($negativeDetected) {
                $errors[] = 'price_negative';
            }

            if ($overflowDetected) {
                $errors[] = 'price_overflow';
            }

            if (strlen($fraction) > 2) {
                $errors[] = 'price_precision_exceeded';
            }

            return new PriceNormalizationResult(
                valid: false,
                rawSupplierPrice: $rawSupplierPrice,
                normalizedPrice: null,
                sourceCurrency: $sourceCurrency,
                normalizedCurrency: $normalizedCurrency,
                roundingPolicy: 'no-implicit-rounding',
                taxInterpretation: $taxInterpretation,
                errors: $errors,
                overflowDetected: $overflowDetected,
                negativeDetected: $negativeDetected,
            );
        }

        return new PriceNormalizationResult(
            valid: true,
            rawSupplierPrice: $rawSupplierPrice,
            normalizedPrice: DecimalNormalizer::fixed($canonical, 2),
            sourceCurrency: $sourceCurrency,
            normalizedCurrency: $normalizedCurrency,
            roundingPolicy: 'fixed-scale-2-no-implicit-rounding',
            taxInterpretation: $taxInterpretation,
        );
    }

    /** @param array<int, string> $errors */
    private function invalid(mixed $rawPrice, string $sourceCurrency, string $normalizedCurrency, string $taxInterpretation, array $errors): PriceNormalizationResult
    {
        return new PriceNormalizationResult(
            valid: false,
            rawSupplierPrice: $rawPrice,
            normalizedPrice: null,
            sourceCurrency: $sourceCurrency,
            normalizedCurrency: $normalizedCurrency,
            roundingPolicy: 'no-implicit-rounding',
            taxInterpretation: $taxInterpretation,
            errors: $errors,
        );
    }
}
