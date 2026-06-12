<?php

namespace App\Services\Attributes;

class UnitConversionService
{
    public function normalize(string $value, ?string $targetUnit = null): array
    {
        $raw = mb_strtolower(trim($value));
        $raw = str_replace(',', '.', $raw);
        $raw = str_replace(['"', 'вЂќ', 'вЂњ', 'РёРЅС‡Р°', 'РёРЅС‡', 'инча', 'инч'], ' inch', $raw);
        $raw = preg_replace('/\b(ddr)\s+(\d)\b/u', '$1$2', $raw) ?? $raw;
        $raw = preg_replace('/(\d)\s+(gb|mb|tb|w|kw|mhz|ghz|mm|cm|inch)\b/u', '$1 $2', $raw) ?? $raw;

        if (preg_match('/(?<number>\d+(?:\.\d+)?)\s*(?<unit>mb|gb|tb|w|kw|mhz|ghz|mm|cm|inch)\b/u', $raw, $matches)) {
            $number = (float) $matches['number'];
            $unit = $matches['unit'];

            [$number, $unit] = $this->convertNumeric($number, $unit, $targetUnit);

            return [
                'normalized_value' => $this->slugNumber($number).'_'.$unit,
                'display_value' => $this->displayNumber($number).' '.$this->displayUnit($unit),
                'numeric_value' => $number,
                'unit' => $unit,
                'confidence' => 70,
            ];
        }

        $normalized = trim((string) preg_replace('/\s+/u', ' ', $raw));
        $normalized = preg_replace('/\bddr\s*(\d)\b/u', 'ddr$1', $normalized) ?? $normalized;

        return [
            'normalized_value' => str_replace(' ', '_', $normalized),
            'display_value' => strtoupper($normalized) === $normalized ? $normalized : trim($value),
            'numeric_value' => null,
            'unit' => $targetUnit,
            'confidence' => 80,
        ];
    }

    private function convertNumeric(float $number, string $unit, ?string $targetUnit): array
    {
        if ($unit === 'mb') {
            return [$number / 1024, 'gb'];
        }

        if ($unit === 'gb' && ($targetUnit === 'tb' || $number >= 1024)) {
            return [$number / 1024, 'tb'];
        }

        if ($unit === 'tb') {
            return [$number, 'tb'];
        }

        if ($unit === 'kw') {
            return [$number * 1000, 'w'];
        }

        if ($unit === 'mhz' && ($targetUnit === 'ghz' || $number >= 1000)) {
            return [$number / 1000, 'ghz'];
        }

        if ($unit === 'mm' && $targetUnit === 'cm') {
            return [$number / 10, 'cm'];
        }

        return [$number, $unit];
    }

    private function displayNumber(float $number): string
    {
        return rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');
    }

    private function slugNumber(float $number): string
    {
        return str_replace('.', '_', $this->displayNumber($number));
    }

    private function displayUnit(string $unit): string
    {
        return match ($unit) {
            'gb' => 'GB',
            'tb' => 'TB',
            'mb' => 'MB',
            'w' => 'W',
            'kw' => 'kW',
            'mhz' => 'MHz',
            'ghz' => 'GHz',
            'cm' => 'cm',
            'mm' => 'mm',
            'inch' => '"',
            default => $unit,
        };
    }
}
