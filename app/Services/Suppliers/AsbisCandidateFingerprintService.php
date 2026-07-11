<?php

namespace App\Services\Suppliers;

use JsonException;

class AsbisCandidateFingerprintService
{
    public const SCHEMA_VERSION = 'asbis-dual-feed-staging-candidate-v2';

    /**
     * @param  array<int, array<string, mixed>>  $payloads
     */
    public function fingerprint(array $payloads): string
    {
        $canonicalPayloads = array_map(fn (array $payload): array => $this->canonicalize($this->fingerprintPayload($payload)), $payloads);

        usort($canonicalPayloads, function (array $left, array $right): int {
            $skuComparison = strcmp((string) ($left['supplier_sku'] ?? ''), (string) ($right['supplier_sku'] ?? ''));

            return $skuComparison !== 0
                ? $skuComparison
                : strcmp((string) ($left['payload_hash'] ?? ''), (string) ($right['payload_hash'] ?? ''));
        });

        try {
            return hash('sha256', json_encode([
                'schema_version' => self::SCHEMA_VERSION,
                'candidates' => $canonicalPayloads,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (JsonException $exception) {
            throw new \RuntimeException('Unable to fingerprint ASBIS staging candidates.', previous: $exception);
        }
    }

    /**
     * Payload metadata is written for traceability, but source file hashes and
     * the derived payload hash must not make an otherwise identical candidate
     * set depend on XML row order or file metadata.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function fingerprintPayload(array $payload): array
    {
        unset($payload['payload_hash']);

        if (is_array($payload['raw_data'] ?? null)) {
            unset($payload['raw_data']['product_list_sha256'], $payload['raw_data']['price_avail_sha256']);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        $canonical = [];

        foreach ($value as $key => $item) {
            $canonical[(string) $key] = $this->canonicalize($item);
        }

        ksort($canonical);

        return $canonical;
    }
}
