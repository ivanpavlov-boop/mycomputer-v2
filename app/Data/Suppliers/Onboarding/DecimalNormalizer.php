<?php

namespace App\Data\Suppliers\Onboarding;

use InvalidArgumentException;

final class DecimalNormalizer
{
    public static function canonical(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException('Decimal value must be finite.');
        }

        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            throw new InvalidArgumentException('Decimal value must be numeric.');
        }

        $value = trim((string) $value);

        if (preg_match('/^-?\d+(?:\.\d+)?$/', $value) !== 1) {
            throw new InvalidArgumentException('Decimal value has an invalid format.');
        }

        [$integer, $fraction] = array_pad(explode('.', ltrim($value, '+'), 2), 2, '');
        $negative = str_starts_with($integer, '-');
        $integer = ltrim($integer, '-');
        $integer = ltrim($integer, '0') ?: '0';
        $fraction = rtrim($fraction, '0');

        if ($integer === '0' && $fraction === '') {
            return '0';
        }

        return ($negative ? '-' : '').$integer.($fraction === '' ? '' : '.'.$fraction);
    }

    public static function fixed(mixed $value, int $scale = 2): ?string
    {
        if ($scale < 0) {
            throw new InvalidArgumentException('Decimal scale cannot be negative.');
        }

        $canonical = self::canonical($value);

        if ($canonical === null) {
            return null;
        }

        $negative = str_starts_with($canonical, '-');
        $unsigned = ltrim($canonical, '-');
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $fraction = substr(str_pad($fraction, $scale, '0'), 0, $scale);

        return ($negative ? '-' : '').$integer.($scale > 0 ? '.'.$fraction : '');
    }

    public static function compare(mixed $left, mixed $right): int
    {
        $left = self::canonical($left);
        $right = self::canonical($right);

        if ($left === null || $right === null) {
            throw new InvalidArgumentException('Decimal comparison values cannot be null.');
        }

        $leftNegative = str_starts_with($left, '-');
        $rightNegative = str_starts_with($right, '-');

        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }

        $leftParts = array_pad(explode('.', ltrim($left, '-'), 2), 2, '');
        $rightParts = array_pad(explode('.', ltrim($right, '-'), 2), 2, '');
        $integerComparison = strlen($leftParts[0]) <=> strlen($rightParts[0]);

        if ($integerComparison === 0) {
            $integerComparison = strcmp($leftParts[0], $rightParts[0]) <=> 0;
        }

        $scale = max(strlen($leftParts[1]), strlen($rightParts[1]));
        $fractionComparison = strcmp(
            str_pad($leftParts[1], $scale, '0'),
            str_pad($rightParts[1], $scale, '0'),
        ) <=> 0;
        $comparison = $integerComparison !== 0 ? $integerComparison : $fractionComparison;

        return $leftNegative ? -$comparison : $comparison;
    }
}
