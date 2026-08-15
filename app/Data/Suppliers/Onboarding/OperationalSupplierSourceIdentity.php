<?php

namespace App\Data\Suppliers\Onboarding;

use InvalidArgumentException;

final class OperationalSupplierSourceIdentity
{
    private const MAX_CODE_POINTS = 128;

    public static function validate(mixed $value): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || $value === '') {
            throw new InvalidArgumentException('invalid_source_identity');
        }

        $whitespaceOnly = preg_match('/^\p{White_Space}+$/u', $value);
        if ($whitespaceOnly === false || $whitespaceOnly === 1) {
            throw new InvalidArgumentException('invalid_source_identity');
        }

        $codePoints = mb_strlen($value, 'UTF-8');
        if ($codePoints < 1 || $codePoints > self::MAX_CODE_POINTS) {
            throw new InvalidArgumentException('invalid_source_identity');
        }

        return $value;
    }
}
