<?php

namespace App\Data\Suppliers\SourceProfiles;

use App\Data\Suppliers\Snapshots\CanonicalSupplierContract;
use InvalidArgumentException;
use JsonException;

final readonly class CanonicalSupplierSourceLocator
{
    public const CONTRACT = 'supplier_import_source_locator_v1';

    private const FIELDS = [
        'schema',
        'source_locator_contract_key',
        'source_locator_contract_version',
        'scheme',
        'ascii_host',
        'port',
        'path_components',
        'query_components',
    ];

    private const CLASSIFICATIONS = ['source', 'credential', 'fixed'];

    /** @param array<string, mixed> $values */
    private function __construct(private array $values) {}

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $values = CanonicalSupplierContract::ordered($values, self::FIELDS);

        if ($values['schema'] !== self::CONTRACT) {
            throw new InvalidArgumentException('invalid_source_locator_schema');
        }

        CanonicalSupplierContract::asciiString(
            $values['source_locator_contract_key'],
            'source_locator_contract_key',
            96,
        );
        CanonicalSupplierContract::asciiString(
            $values['source_locator_contract_version'],
            'source_locator_contract_version',
            32,
        );
        self::assertLowerAscii($values['scheme'], 'scheme', 16);
        self::assertLowerAscii($values['ascii_host'], 'ascii_host', 253);

        if ($values['port'] !== null
            && (! is_int($values['port']) || $values['port'] < 1 || $values['port'] > 65_535)) {
            throw new InvalidArgumentException('invalid_port');
        }

        if (($values['scheme'] === 'http' && $values['port'] === 80)
            || ($values['scheme'] === 'https' && $values['port'] === 443)) {
            throw new InvalidArgumentException('invalid_default_port');
        }

        self::assertPathComponents($values['path_components']);
        self::assertQueryComponents($values['query_components']);

        return new self($values);
    }

    public static function fromCanonicalBytes(string $bytes, string $expectedKey): self
    {
        $values = self::decodeCanonicalBytes($bytes);
        $locator = self::fromArray($values);

        if ($locator->canonicalBytes() !== $bytes || $locator->key() !== $expectedKey) {
            throw new InvalidArgumentException('noncanonical_source_locator_bytes');
        }

        return $locator;
    }

    /** @return array<string, mixed> */
    public function toCanonicalArray(): array
    {
        return $this->values;
    }

    public function canonicalBytes(): string
    {
        return self::CONTRACT."\0".CanonicalSupplierContract::encodeSorted($this->values);
    }

    public function key(): string
    {
        return 'source-locator-v1:sha256:'.CanonicalSupplierContract::rawDigest($this->canonicalBytes());
    }

    public function contractKey(): string
    {
        return $this->values['source_locator_contract_key'];
    }

    public function contractVersion(): string
    {
        return $this->values['source_locator_contract_version'];
    }

    private static function assertLowerAscii(mixed $value, string $field, int $maxBytes): void
    {
        $value = CanonicalSupplierContract::asciiString($value, $field, $maxBytes);

        $valid = $field === 'scheme'
            ? preg_match('/^[a-z][a-z0-9+.-]*$/D', $value) === 1
            : self::validAsciiHost($value);

        if ($value !== strtolower($value) || ! $valid) {
            throw new InvalidArgumentException('invalid_'.$field);
        }
    }

    private static function assertPathComponents(mixed $components): void
    {
        if (! is_array($components) || ! array_is_list($components)) {
            throw new InvalidArgumentException('invalid_path_components');
        }

        $previousPosition = null;
        foreach ($components as $component) {
            if (! is_array($component)) {
                throw new InvalidArgumentException('invalid_path_components');
            }

            $component = CanonicalSupplierContract::ordered(
                $component,
                ['position', 'classification', 'value'],
            );
            if (! is_int($component['position'])
                || $component['position'] < 0
                || ($previousPosition !== null && $component['position'] <= $previousPosition)) {
                throw new InvalidArgumentException('invalid_path_components');
            }

            self::assertClassifiedValue($component['classification'], $component['value'], 'path_components');
            self::assertNormalizedUriComponent($component['value'], 'path_components');
            $previousPosition = $component['position'];
        }
    }

    private static function assertQueryComponents(mixed $components): void
    {
        if (! is_array($components) || ! array_is_list($components)) {
            throw new InvalidArgumentException('invalid_query_components');
        }

        $previous = null;
        foreach ($components as $component) {
            if (! is_array($component)) {
                throw new InvalidArgumentException('invalid_query_components');
            }

            $component = CanonicalSupplierContract::ordered(
                $component,
                ['key', 'ordinal', 'classification', 'value'],
            );
            if (! is_int($component['ordinal'])
                || $component['ordinal'] < 0) {
                throw new InvalidArgumentException('invalid_query_components');
            }

            $key = CanonicalSupplierContract::nonEmptyString($component['key'], 'query_component_key');
            self::assertNormalizedUriComponent($key, 'query_component_key');
            $order = [$key, $component['ordinal']];
            if ($previous !== null && ($order[0] < $previous[0]
                || ($order[0] === $previous[0] && $order[1] <= $previous[1]))) {
                throw new InvalidArgumentException('invalid_query_component_order');
            }

            self::assertClassifiedValue($component['classification'], $component['value'], 'query_components');
            self::assertNormalizedUriComponent($component['value'], 'query_components');
            $previous = $order;
        }
    }

    private static function assertClassifiedValue(mixed $classification, mixed $value, string $field): void
    {
        CanonicalSupplierContract::enum($classification, $field.'_classification', self::CLASSIFICATIONS);
        CanonicalSupplierContract::string($value, $field.'_value');

        if ($classification === 'credential' && $value !== '{credential}') {
            throw new InvalidArgumentException('invalid_'.$field.'_credential_value');
        }
    }

    private static function assertNormalizedUriComponent(string $value, string $field): void
    {
        if (str_contains($value, '#')) {
            throw new InvalidArgumentException('invalid_'.$field.'_value');
        }

        for ($offset = 0, $length = strlen($value); $offset < $length; $offset++) {
            if ($value[$offset] !== '%') {
                continue;
            }

            $escape = substr($value, $offset + 1, 2);
            if (strlen($escape) !== 2
                || preg_match('/^[0-9A-F]{2}$/D', $escape) !== 1
                || preg_match('/^[A-Za-z0-9._~-]$/D', chr((int) hexdec($escape))) === 1) {
                throw new InvalidArgumentException('invalid_'.$field.'_percent_encoding');
            }

            $offset += 2;
        }
    }

    private static function validAsciiHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return true;
        }

        if (strlen($host) > 253 || str_ends_with($host, '.')) {
            return false;
        }

        foreach (explode('.', $host) as $label) {
            if ($label === ''
                || strlen($label) > 63
                || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private static function decodeCanonicalBytes(string $bytes): array
    {
        $prefix = self::CONTRACT."\0";

        if (! str_starts_with($bytes, $prefix)) {
            throw new InvalidArgumentException('invalid_source_locator_bytes');
        }

        try {
            $values = json_decode(substr($bytes, strlen($prefix)), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('invalid_source_locator_bytes', previous: $exception);
        }

        if (! is_array($values) || array_is_list($values)) {
            throw new InvalidArgumentException('invalid_source_locator_bytes');
        }

        return $values;
    }
}
