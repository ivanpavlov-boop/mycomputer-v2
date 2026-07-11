<?php

namespace App\Services\Suppliers;

use App\Models\AvailabilityStatus;
use Illuminate\Support\Facades\Schema;
use JsonException;

class AsbisStagingPayloadSchemaValidator
{
    private const STRING_LIMITS = [
        'supplier_sku' => 255,
        'ean' => 255,
        'mpn' => 255,
        'name' => 255,
        'brand_name' => 255,
        'category_name' => 255,
        'external_availability_status' => 255,
        'external_availability_label' => 255,
        'currency' => 3,
        'payload_hash' => 255,
        'mapping_notes' => 65535,
    ];

    private const DECIMAL_FIELDS = [
        'price',
        'supplier_price_raw',
        'recommended_price',
    ];

    private const UNSIGNED_INTEGER_FIELDS = [
        'quantity',
        'availability_status_id',
    ];

    private const REQUIRED_FIELDS = [
        'supplier_id',
        'supplier_sku',
        'name',
        'raw_data',
        'payload_hash',
        'currency',
        'status',
    ];

    private const CONTRACT_FIELDS = [
        'supplier_id',
        'supplier_feed_id',
        'product_id',
        'supplier_sku',
        'ean',
        'mpn',
        'name',
        'brand_name',
        'category_name',
        'price',
        'supplier_price_raw',
        'recommended_price',
        'quantity',
        'external_availability_status',
        'external_availability_label',
        'availability_status_id',
        'currency',
        'raw_data',
        'payload_hash',
        'received_at',
        'synced_at',
        'status',
        'mapping_notes',
        'created_at',
        'updated_at',
    ];

    private const SAMPLE_LIMIT = 20;

    /**
     * Validate the canonical payload before generated timestamps are added.
     * Runtime schema checks only verify that the canonical contract exists; the
     * payload fingerprint itself never depends on database introspection order.
     *
     * @param  array<int, array<string, mixed>>  $payloads
     * @return array<string, mixed>
     */
    public function validate(array $payloads): array
    {
        $fieldLengthViolationCounts = [];
        $fieldLengthViolationSamples = [];
        $unknownPayloadFields = [];
        $invalidJsonCount = 0;
        $invalidAvailabilityStatusIdCount = 0;
        $nullabilityViolationCount = 0;
        $invalidDecimalCount = 0;
        $invalidUnsignedIntegerCount = 0;
        $truncatedNameCount = 0;
        $maximumOriginalNameLength = 0;
        $maximumStagedNameLength = 0;

        foreach ($payloads as $index => $payload) {
            $unknownPayloadFields = array_values(array_unique([
                ...$unknownPayloadFields,
                ...array_diff(array_keys($payload), self::CONTRACT_FIELDS),
            ]));

            $rawData = $payload['raw_data'] ?? null;

            if (! is_array($rawData)) {
                $invalidJsonCount++;
            } else {
                try {
                    json_encode($rawData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } catch (JsonException) {
                    $invalidJsonCount++;
                }
            }

            foreach (self::REQUIRED_FIELDS as $field) {
                if (! array_key_exists($field, $payload) || $payload[$field] === null || $payload[$field] === '') {
                    $nullabilityViolationCount++;
                }
            }

            foreach (self::STRING_LIMITS as $field => $limit) {
                $value = $payload[$field] ?? null;

                if ($value === null) {
                    continue;
                }

                if (! is_scalar($value)) {
                    $this->recordLengthViolation($field, 0, $limit, $index, $fieldLengthViolationCounts, $fieldLengthViolationSamples);

                    continue;
                }

                $length = mb_strlen((string) $value, 'UTF-8');

                if ($length > $limit) {
                    $this->recordLengthViolation($field, $length, $limit, $index, $fieldLengthViolationCounts, $fieldLengthViolationSamples);
                }
            }

            foreach (self::DECIMAL_FIELDS as $field) {
                $value = $payload[$field] ?? null;

                if ($value !== null && ! $this->validDecimal($value)) {
                    $invalidDecimalCount++;
                }
            }

            foreach (self::UNSIGNED_INTEGER_FIELDS as $field) {
                $value = $payload[$field] ?? null;

                if ($value !== null && ! $this->validUnsignedInteger($value)) {
                    $invalidUnsignedIntegerCount++;
                }
            }

            $availabilityStatusId = $payload['availability_status_id'] ?? null;

            if ($availabilityStatusId !== null && (! is_int($availabilityStatusId) || $availabilityStatusId < 1)) {
                $invalidAvailabilityStatusIdCount++;
            }

            $stagedNameLength = $this->unicodeLength($payload['name'] ?? null);
            $maximumStagedNameLength = max($maximumStagedNameLength, $stagedNameLength);
            $rawOriginalLength = is_array($rawData) ? ($rawData['original_name_length'] ?? null) : null;
            $originalNameLength = is_int($rawOriginalLength) ? $rawOriginalLength : $stagedNameLength;
            $maximumOriginalNameLength = max($maximumOriginalNameLength, $originalNameLength);

            if (is_array($rawData) && ($rawData['name_was_truncated'] ?? false) === true) {
                $truncatedNameCount++;
            }
        }

        $availabilityStatusIds = collect($payloads)
            ->pluck('availability_status_id')
            ->filter(fn (mixed $id): bool => $id !== null && is_int($id) && $id > 0)
            ->unique()
            ->values();

        if ($availabilityStatusIds->isNotEmpty()) {
            $validIds = Schema::hasTable('availability_statuses')
                ? AvailabilityStatus::query()->whereIn('id', $availabilityStatusIds)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()
                : [];
            $invalidAvailabilityStatusIdCount += $availabilityStatusIds->diff($validIds)->count();
        }

        $missingSchemaColumns = Schema::hasTable('supplier_products')
            ? collect(self::CONTRACT_FIELDS)->filter(fn (string $field): bool => ! Schema::hasColumn('supplier_products', $field))->values()->all()
            : ['supplier_products'];

        $compatible = $missingSchemaColumns === []
            && $unknownPayloadFields === []
            && $invalidJsonCount === 0
            && $invalidAvailabilityStatusIdCount === 0
            && $nullabilityViolationCount === 0
            && $invalidDecimalCount === 0
            && $invalidUnsignedIntegerCount === 0
            && $fieldLengthViolationCounts === []
            && $maximumStagedNameLength <= 255;

        return [
            'payload_schema_compatible' => $compatible,
            'candidate_count' => count($payloads),
            'truncated_name_count' => $truncatedNameCount,
            'maximum_original_name_length' => $maximumOriginalNameLength,
            'maximum_staged_name_length' => $maximumStagedNameLength,
            'staged_name_limit' => 255,
            'field_length_violation_counts' => $fieldLengthViolationCounts,
            'field_length_violation_samples' => $fieldLengthViolationSamples,
            'unknown_payload_fields' => $unknownPayloadFields,
            'missing_schema_columns' => $missingSchemaColumns,
            'invalid_json_count' => $invalidJsonCount,
            'invalid_availability_status_id_count' => $invalidAvailabilityStatusIdCount,
            'nullability_violation_count' => $nullabilityViolationCount,
            'invalid_decimal_count' => $invalidDecimalCount,
            'invalid_unsigned_integer_count' => $invalidUnsignedIntegerCount,
        ];
    }

    private function recordLengthViolation(string $field, int $length, int $limit, int $index, array &$counts, array &$samples): void
    {
        $counts[$field] = ($counts[$field] ?? 0) + 1;

        if (count($samples) < self::SAMPLE_LIMIT) {
            $samples[] = [
                'field' => $field,
                'candidate_index' => $index,
                'observed_length' => $length,
                'limit' => $limit,
            ];
        }
    }

    private function validDecimal(mixed $value): bool
    {
        return is_int($value) || is_float($value) && is_finite($value)
            ? $this->validDecimalString((string) $value)
            : is_string($value) && $this->validDecimalString($value);
    }

    private function validDecimalString(string $value): bool
    {
        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) {
            return false;
        }

        $integerDigits = strlen(ltrim(strtok($value, '.') ?: '', '-'));

        return $integerDigits <= 10;
    }

    private function validUnsignedInteger(mixed $value): bool
    {
        if (is_int($value)) {
            return $value >= 0 && $value <= 4_294_967_295;
        }

        return is_string($value) && preg_match('/^(?:0|[1-9]\d*)$/', $value) === 1
            && strlen($value) <= 10
            && (int) $value <= 4_294_967_295;
    }

    private function unicodeLength(mixed $value): int
    {
        return $value === null ? 0 : mb_strlen((string) $value, 'UTF-8');
    }
}
