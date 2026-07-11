<?php

namespace App\Services\Suppliers;

use App\Models\AvailabilityStatus;
use App\Models\Supplier;
use Illuminate\Support\Facades\Schema;
use JsonException;
use RuntimeException;

class AsbisStagingCandidatePayloadBuilder
{
    /**
     * @param  array<int, array<string, mixed>>  $classifiedRows
     * @param  array<string, mixed>  $sourceFingerprints
     * @return array<int, array<string, mixed>>
     */
    public function build(array $classifiedRows, Supplier $supplier, array $sourceFingerprints): array
    {
        $availabilityStatuses = $this->availabilityStatuses();
        $payloads = [];

        foreach ($classifiedRows as $row) {
            if (($row['readiness_state'] ?? null) !== 'ready_to_create') {
                continue;
            }

            $payloads[] = $this->payload($row, $supplier, $sourceFingerprints, $availabilityStatuses);
        }

        usort($payloads, fn (array $left, array $right): int => strcmp(
            (string) ($left['supplier_sku'] ?? ''),
            (string) ($right['supplier_sku'] ?? '')
        ));

        return $payloads;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function writeAttributes(array $payload): array
    {
        return $payload;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $sourceFingerprints
     * @param  array<string, int>  $availabilityStatuses
     * @return array<string, mixed>
     */
    private function payload(array $row, Supplier $supplier, array $sourceFingerprints, array $availabilityStatuses): array
    {
        $supplierSku = $this->stringValue($row['supplier_sku'] ?? null);
        $ean = $this->stringValue($row['ean_gtin'] ?? null);
        $availability = $this->stringValue($row['availability'] ?? null);
        $rawAvailability = $this->stringValue($row['raw_availability'] ?? null);
        $currency = strtoupper($this->stringValue($row['currency'] ?? null) ?? 'EUR');
        $rawData = [
            'source' => 'asbis_dual_feed',
            'supplier_key' => 'asbis',
            'source_product_code' => $supplierSku,
            'source_wic' => $supplierSku,
            'product_list_sha256' => $sourceFingerprints['product_list_sha256'] ?? null,
            'price_avail_sha256' => $sourceFingerprints['price_avail_sha256'] ?? null,
            'candidate_payload_schema_version' => AsbisCandidateFingerprintService::SCHEMA_VERSION,
            'price_source' => $row['price_source'] ?? null,
            'availability_source' => 'AVAIL',
        ];

        $logicalPayload = [
            'supplier_id' => (int) $supplier->getKey(),
            'supplier_feed_id' => null,
            'product_id' => null,
            'supplier_sku' => $supplierSku,
            'ean' => $ean,
            'mpn' => $this->stringValue($row['mpn'] ?? null),
            'name' => $this->stringValue($row['name'] ?? null),
            'brand_name' => $this->stringValue($row['brand'] ?? null),
            'category_name' => $this->stringValue($row['category'] ?? null),
            'price' => $this->decimalValue($row['price'] ?? null),
            'supplier_price_raw' => $this->decimalValue($row['price'] ?? null),
            'recommended_price' => null,
            'quantity' => is_int($row['stock'] ?? null) ? $row['stock'] : null,
            'external_availability_status' => $availability,
            'external_availability_label' => $rawAvailability,
            'availability_status_id' => $availability !== null ? ($availabilityStatuses[$availability] ?? null) : null,
            'currency' => $currency,
            'raw_data' => $rawData,
            'synced_at' => null,
            'status' => 'pending_review',
            'mapping_notes' => 'ASBIS dual-feed controlled staging; create-only; Catalog Sync not run.',
        ];

        $logicalPayload['payload_hash'] = $this->hashPayload($logicalPayload);

        return $logicalPayload;
    }

    /**
     * @return array<string, int>
     */
    private function availabilityStatuses(): array
    {
        if (! Schema::hasTable('availability_statuses')) {
            return [];
        }

        return AvailabilityStatus::query()
            ->whereIn('code', ['in_stock', 'limited_stock', 'on_request', 'out_of_stock'])
            ->pluck('id', 'code')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hashPayload(array $payload): string
    {
        try {
            ksort($payload);

            return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to hash an ASBIS staging candidate payload.', previous: $exception);
        }
    }

    private function decimalValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
