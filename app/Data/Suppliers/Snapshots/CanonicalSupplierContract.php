<?php

namespace App\Data\Suppliers\Snapshots;

use App\Data\Suppliers\Onboarding\CanonicalOnboardingData;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;

final class CanonicalSupplierContract
{
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    public const SIGNED_BIGINT_MAX = PHP_INT_MAX;

    public const UNSIGNED_INT_MAX = 4_294_967_295;

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public static function ordered(array $values, array $fields): array
    {
        $keys = array_keys($values);

        if (array_is_list($values)
            || count($keys) !== count($fields)
            || array_diff($keys, $fields) !== []
            || array_diff($fields, $keys) !== []) {
            throw new InvalidArgumentException('invalid_canonical_contract_shape');
        }

        $ordered = [];
        foreach ($fields as $field) {
            $ordered[$field] = $values[$field];
        }

        return $ordered;
    }

    /** @param array<string, mixed> $values */
    public static function encodeFixed(array $values): string
    {
        self::assertCanonicalValue($values);

        try {
            return json_encode($values, self::JSON_FLAGS);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('invalid_canonical_json', previous: $exception);
        }
    }

    public static function encodeSorted(mixed $value): string
    {
        self::assertCanonicalValue($value);

        return CanonicalOnboardingData::encode($value);
    }

    public static function digest(string $domain, string $canonicalBytes): string
    {
        return hash('sha256', $domain."\0".$canonicalBytes);
    }

    public static function rawDigest(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    public static function string(mixed $value, string $field): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('invalid_'.$field);
        }

        return $value;
    }

    public static function nonEmptyString(mixed $value, string $field, ?int $maxBytes = null): string
    {
        $value = self::string($value, $field);

        if ($value === '' || ($maxBytes !== null && strlen($value) > $maxBytes)) {
            throw new InvalidArgumentException('invalid_'.$field);
        }

        return $value;
    }

    public static function asciiString(mixed $value, string $field, int $maxBytes): string
    {
        $value = self::nonEmptyString($value, $field, $maxBytes);

        if (preg_match('/^[\x20-\x7E]+$/D', $value) !== 1) {
            throw new InvalidArgumentException('invalid_'.$field);
        }

        return $value;
    }

    /** @param list<string> $allowed */
    public static function enum(mixed $value, string $field, array $allowed): string
    {
        $value = self::string($value, $field);

        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException('invalid_'.$field);
        }

        return $value;
    }

    public static function boolean(mixed $value, string $field): bool
    {
        if (! is_bool($value)) {
            throw new InvalidArgumentException('invalid_'.$field);
        }

        return $value;
    }

    public static function positiveInteger(mixed $value, string $field): int
    {
        if (! is_int($value) || $value < 1 || $value > self::SIGNED_BIGINT_MAX) {
            throw new InvalidArgumentException('invalid_'.$field);
        }

        return $value;
    }

    public static function nullablePositiveInteger(mixed $value, string $field): ?int
    {
        return $value === null ? null : self::positiveInteger($value, $field);
    }

    public static function nullableUnsignedInteger(
        mixed $value,
        string $field,
        int $maximum = self::UNSIGNED_INT_MAX,
    ): ?int {
        return $value === null ? null : self::unsignedInteger($value, $field, $maximum);
    }

    public static function unsignedInteger(mixed $value, string $field, int $maximum = self::UNSIGNED_INT_MAX): int
    {
        if (! is_int($value) || $value < 0 || $value > $maximum) {
            throw new InvalidArgumentException('invalid_'.$field);
        }

        return $value;
    }

    public static function hex64(mixed $value, string $field): string
    {
        if (! is_string($value) || preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new InvalidArgumentException('invalid_'.$field);
        }

        return $value;
    }

    public static function nullableHex64(mixed $value, string $field): ?string
    {
        return $value === null ? null : self::hex64($value, $field);
    }

    public static function mysqlUtcMicroseconds(mixed $value, string $field): string
    {
        $value = self::string($value, $field);

        return self::validatedDate(
            $value,
            $field,
            '/^[0-9]{4}-(0[1-9]|1[0-2])-([0-2][0-9]|3[01])T([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]\.[0-9]{6}Z$/D',
            '!Y-m-d\TH:i:s.u\Z',
            'Y-m-d\TH:i:s.u\Z',
        );
    }

    public static function nullableMysqlUtcMicroseconds(mixed $value, string $field): ?string
    {
        return $value === null ? null : self::mysqlUtcMicroseconds($value, $field);
    }

    public static function snapshotUtcSeconds(mixed $value, string $field): string
    {
        $value = self::string($value, $field);

        return self::validatedDate(
            $value,
            $field,
            '/^[0-9]{4}-(0[1-9]|1[0-2])-([0-2][0-9]|3[01])T([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]\+00:00$/D',
            '!Y-m-d\TH:i:sP',
            'Y-m-d\TH:i:sP',
        );
    }

    public static function nullableSnapshotUtcSeconds(mixed $value, string $field): ?string
    {
        return $value === null ? null : self::snapshotUtcSeconds($value, $field);
    }

    public static function exactPrice(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || preg_match('/^(0|[1-9][0-9]{0,9})\.[0-9]{2}$/D', $value) !== 1) {
            throw new InvalidArgumentException('invalid_'.$field);
        }

        return $value;
    }

    public static function exactPercent(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)
            || preg_match('/^(0|[1-9][0-9]{0,2})\.[0-9]{6}$/D', $value) !== 1
            || (int) str_replace('.', '', $value) > 100_000_000) {
            throw new InvalidArgumentException('invalid_'.$field);
        }

        return $value;
    }

    /** @param list<string> $hashes @return list<string> */
    public static function sortedUniqueHashes(array $hashes, string $field): array
    {
        if (! array_is_list($hashes) || $hashes === []) {
            throw new InvalidArgumentException('invalid_'.$field);
        }

        foreach ($hashes as $hash) {
            self::hex64($hash, $field);
        }

        sort($hashes, SORT_STRING);

        if (count($hashes) !== count(array_unique($hashes, SORT_STRING))) {
            throw new InvalidArgumentException('invalid_'.$field);
        }

        return $hashes;
    }

    /** @return array<string, mixed> */
    public static function canonicalObject(mixed $value, string $field): array
    {
        if (! is_array($value) || array_is_list($value) || $value === []) {
            throw new InvalidArgumentException('invalid_'.$field);
        }

        self::assertCanonicalValue($value);

        return $value;
    }

    /** @return list<string> */
    public static function sortedUniqueAsciiStrings(mixed $values, string $field, int $maxBytes): array
    {
        if (! is_array($values) || ! array_is_list($values)) {
            throw new InvalidArgumentException('invalid_'.$field);
        }

        foreach ($values as $value) {
            self::asciiString($value, $field, $maxBytes);
        }

        sort($values, SORT_STRING);

        if (count($values) !== count(array_unique($values, SORT_STRING))) {
            throw new InvalidArgumentException('invalid_'.$field);
        }

        return $values;
    }

    public static function assertCanonicalValue(mixed $value): void
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return;
        }

        if (is_string($value)) {
            if (! mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidArgumentException('invalid_canonical_utf8');
            }

            return;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('invalid_canonical_value_type');
        }

        foreach ($value as $key => $item) {
            if (is_string($key) && ! mb_check_encoding($key, 'UTF-8')) {
                throw new InvalidArgumentException('invalid_canonical_utf8');
            }

            self::assertCanonicalValue($item);
        }
    }

    private static function validatedDate(
        string $value,
        string $field,
        string $pattern,
        string $parseFormat,
        string $outputFormat,
    ): string {
        if (preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException('invalid_'.$field);
        }

        $date = DateTimeImmutable::createFromFormat($parseFormat, $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $date->format($outputFormat) !== $value) {
            throw new InvalidArgumentException('invalid_'.$field);
        }

        return $value;
    }
}
