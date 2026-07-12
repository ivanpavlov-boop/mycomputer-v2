<?php

namespace App\Data\Suppliers\Onboarding;

use JsonException;
use JsonSerializable;
use RuntimeException;

final class CanonicalOnboardingData
{
    /** @return array<string|int, mixed> */
    public static function normalize(mixed $value): mixed
    {
        if ($value instanceof JsonSerializable) {
            return self::normalize($value->jsonSerialize());
        }

        if (is_float($value)) {
            return DecimalNormalizer::canonical($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[(string) $key] = self::normalize($item);
        }

        ksort($normalized);

        return $normalized;
    }

    public static function encode(mixed $value): string
    {
        try {
            return json_encode(
                self::normalize($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to serialize onboarding data.', previous: $exception);
        }
    }
}
