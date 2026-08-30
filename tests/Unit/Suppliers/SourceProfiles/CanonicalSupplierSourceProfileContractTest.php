<?php

namespace Tests\Unit\Suppliers\SourceProfiles;

use App\Data\Suppliers\SourceProfiles\CanonicalSupplierImportMapping;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierSourceLocator;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierSourceProfileDescriptor;
use App\Data\Suppliers\SourceProfiles\SupplierSourceProfileIdentity;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CanonicalSupplierSourceProfileContractTest extends TestCase
{
    public function test_canonical_contracts_are_deterministic_secret_free_and_byte_bound(): void
    {
        $mapping = $this->mapping();
        $locator = $this->locator();
        $descriptor = $this->descriptor($locator, $mapping);

        $this->assertSame(
            CanonicalSupplierImportMapping::VERSION."\0".'{"effective_mapping":{"defaults":{"currency":"BGN"},"field_map":{"price":"PRICE","sku":"WIC"},"root_path":"CONTENT.PRICE","validation_rules":{"sku":"required"}},"feed_type":"xml","schema":"supplier_import_mapping_contract_v1"}',
            $mapping->canonicalBytes(),
        );
        $this->assertSame(
            CanonicalSupplierSourceLocator::CONTRACT."\0".'{"ascii_host":"feeds.example.test","path_components":[{"classification":"fixed","position":0,"value":"exports"},{"classification":"credential","position":1,"value":"{credential}"}],"port":null,"query_components":[{"classification":"source","key":"catalog","ordinal":0,"value":"bg"},{"classification":"credential","key":"token","ordinal":0,"value":"{credential}"}],"schema":"supplier_import_source_locator_v1","scheme":"https","source_locator_contract_key":"supplier-feed-url-v1","source_locator_contract_version":"1"}',
            $locator->canonicalBytes(),
        );
        $this->assertSame(
            'source-locator-v1:sha256:'.hash('sha256', $locator->canonicalBytes()),
            $locator->key(),
        );
        $this->assertSame(
            CanonicalSupplierSourceProfileDescriptor::VERSION."\0".'{"feed_type":"xml","importer_key":"xml-importer","importer_version":"1","mapping_contract_fingerprint":"'.$mapping->fingerprint().'","mapping_contract_version":"supplier_import_mapping_contract_v1","schema":"supplier_import_source_profile_v1","source_access_scope_key":"source-access-v1:catalog.bg","source_locator_contract_key":"supplier-feed-url-v1","source_locator_contract_version":"1","source_locator_key":"'.$locator->key().'","supplier_feed_id":22,"supplier_id":11}',
            $descriptor->canonicalBytes(),
        );
        $this->assertSame(hash('sha256', $descriptor->canonicalBytes()), $descriptor->fingerprint());

        foreach (['operator', 'super-secret', 'https://feeds.example.test/exports'] as $secret) {
            $this->assertStringNotContainsString($secret, $mapping->canonicalBytes());
            $this->assertStringNotContainsString($secret, $locator->canonicalBytes());
            $this->assertStringNotContainsString($secret, $descriptor->canonicalBytes());
        }

        $attributes = $descriptor->persistenceAttributes();
        $this->assertSame($locator->canonicalBytes(), $attributes['source_locator_canonical_bytes']);
        $this->assertSame($mapping->canonicalBytes(), $attributes['mapping_canonical_bytes']);
        $this->assertSame($mapping->fingerprint(), $attributes['mapping_contract_fingerprint']);
        $this->assertSame($descriptor->fingerprint(), $attributes['source_descriptor_fingerprint']);

        $restoredMapping = CanonicalSupplierImportMapping::fromCanonicalBytes(
            $mapping->canonicalBytes(),
            $mapping->fingerprint(),
        );
        $restoredLocator = CanonicalSupplierSourceLocator::fromCanonicalBytes(
            $locator->canonicalBytes(),
            $locator->key(),
        );
        $restoredDescriptor = CanonicalSupplierSourceProfileDescriptor::fromCanonicalBytes(
            $descriptor->canonicalBytes(),
            $descriptor->fingerprint(),
            $restoredLocator,
            $restoredMapping,
        );
        $this->assertSame($mapping->canonicalBytes(), $restoredMapping->canonicalBytes());
        $this->assertSame($locator->canonicalBytes(), $restoredLocator->canonicalBytes());
        $this->assertSame($descriptor->canonicalBytes(), $restoredDescriptor->canonicalBytes());
    }

    public function test_persisted_canonical_bytes_fail_closed_when_reordered_or_tampered(): void
    {
        $mapping = $this->mapping();
        $locator = $this->locator();
        $descriptor = $this->descriptor($locator, $mapping);

        try {
            CanonicalSupplierImportMapping::fromCanonicalBytes(
                str_replace('"feed_type":"xml"', '"feed_type":"csv"', $mapping->canonicalBytes()),
                $mapping->fingerprint(),
            );
            $this->fail('Tampered mapping bytes were accepted.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        try {
            CanonicalSupplierSourceLocator::fromCanonicalBytes(
                str_replace('feeds.example.test', 'FEEDS.EXAMPLE.TEST', $locator->canonicalBytes()),
                $locator->key(),
            );
            $this->fail('Noncanonical locator bytes were accepted.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        CanonicalSupplierSourceProfileDescriptor::fromCanonicalBytes(
            str_replace('"importer_version":"1"', '"importer_version":"2"', $descriptor->canonicalBytes()),
            $descriptor->fingerprint(),
            $locator,
            $mapping,
        );
    }

    public function test_locator_rejects_default_ports_unsorted_query_and_unredacted_credentials(): void
    {
        $defaultPort = $this->locatorFixture();
        $defaultPort['port'] = 443;

        try {
            CanonicalSupplierSourceLocator::fromArray($defaultPort);
            $this->fail('Default HTTPS port was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('invalid_default_port', $exception->getMessage());
        }

        $unsorted = $this->locatorFixture();
        $unsorted['query_components'] = array_reverse($unsorted['query_components']);

        try {
            CanonicalSupplierSourceLocator::fromArray($unsorted);
            $this->fail('Unsorted query components were accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('invalid_query_component_order', $exception->getMessage());
        }

        $credential = $this->locatorFixture();
        $credential['path_components'][1]['value'] = 'secret-value';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_path_components_credential_value');
        CanonicalSupplierSourceLocator::fromArray($credential);
    }

    public function test_profile_identity_is_exactly_16_random_bytes_encoded_as_lowercase_hex(): void
    {
        $identity = SupplierSourceProfileIdentity::fromRandomBytes(hex2bin('00112233445566778899aabbccddeeff'));

        $this->assertSame(
            'snapshot-source-v1:profile:00112233445566778899aabbccddeeff',
            $identity->value(),
        );
        $this->assertSame(59, strlen($identity->value()));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('source_profile_identity_requires_16_bytes');
        SupplierSourceProfileIdentity::fromRandomBytes(str_repeat('x', 15));
    }

    private function mapping(): CanonicalSupplierImportMapping
    {
        return CanonicalSupplierImportMapping::fromArray([
            'schema' => CanonicalSupplierImportMapping::VERSION,
            'feed_type' => 'xml',
            'effective_mapping' => [
                'root_path' => 'CONTENT.PRICE',
                'field_map' => ['sku' => 'WIC', 'price' => 'PRICE'],
                'validation_rules' => ['sku' => 'required'],
                'defaults' => ['currency' => 'BGN'],
            ],
        ]);
    }

    private function locator(): CanonicalSupplierSourceLocator
    {
        return CanonicalSupplierSourceLocator::fromArray($this->locatorFixture());
    }

    /** @return array<string, mixed> */
    private function locatorFixture(): array
    {
        return [
            'schema' => CanonicalSupplierSourceLocator::CONTRACT,
            'source_locator_contract_key' => 'supplier-feed-url-v1',
            'source_locator_contract_version' => '1',
            'scheme' => 'https',
            'ascii_host' => 'feeds.example.test',
            'port' => null,
            'path_components' => [
                ['position' => 0, 'classification' => 'fixed', 'value' => 'exports'],
                ['position' => 1, 'classification' => 'credential', 'value' => '{credential}'],
            ],
            'query_components' => [
                ['key' => 'catalog', 'ordinal' => 0, 'classification' => 'source', 'value' => 'bg'],
                ['key' => 'token', 'ordinal' => 0, 'classification' => 'credential', 'value' => '{credential}'],
            ],
        ];
    }

    private function descriptor(
        CanonicalSupplierSourceLocator $locator,
        CanonicalSupplierImportMapping $mapping,
    ): CanonicalSupplierSourceProfileDescriptor {
        return CanonicalSupplierSourceProfileDescriptor::fromContracts(
            supplierId: 11,
            supplierFeedId: 22,
            locator: $locator,
            sourceAccessScopeKey: 'source-access-v1:catalog.bg',
            feedType: 'xml',
            importerKey: 'xml-importer',
            importerVersion: '1',
            mapping: $mapping,
        );
    }
}
