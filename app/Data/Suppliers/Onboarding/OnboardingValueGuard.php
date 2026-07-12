<?php

namespace App\Data\Suppliers\Onboarding;

use InvalidArgumentException;

final class OnboardingValueGuard
{
    private const FORBIDDEN_KEYS = '/(?:password|secret|token|authorization|credential|api[_-]?key|private[_-]?key|feed[_-]?url)/i';

    private const FORBIDDEN_VALUES = '/(?:https?|ftp):\/\/|(?:password|secret|token|authorization|bearer)\s*[:=]/i';

    public static function assertSafe(array $value, string $context = 'metadata'): void
    {
        self::walk($value, $context);
    }

    private static function walk(mixed $value, string $path): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $key = (string) $key;

                if (preg_match(self::FORBIDDEN_KEYS, $key) === 1) {
                    throw new InvalidArgumentException("{$path}.{$key} contains prohibited sensitive data.");
                }

                self::walk($item, "{$path}.{$key}");
            }

            return;
        }

        if (is_string($value) && preg_match(self::FORBIDDEN_VALUES, $value) === 1) {
            throw new InvalidArgumentException("{$path} contains prohibited sensitive data.");
        }
    }
}
