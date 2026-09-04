<?php

namespace Tests\Unit\Suppliers\Imports;

use App\Data\Suppliers\Imports\CanonicalSupplierImportSourceExecution;
use App\Data\Suppliers\Imports\ImportJobIdentity;
use App\Data\Suppliers\Imports\ResolvedSupplierImportSourceContext;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierImportMapping;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierSourceLocator;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierSourceProfileDescriptor;
use App\Models\SupplierImportSourceExecution;
use App\Models\SupplierImportSourceProfile;
use Illuminate\Database\Eloquent\MassAssignmentException;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class SupplierImportSourceExecutionContractTest extends TestCase
{
    public function test_import_job_identity_has_exact_bytes_fingerprint_and_order_independence(): void
    {
        $identity = $this->identity();

        $this->assertSame(
            ImportJobIdentity::VERSION."\0".'{"import_job_id":44,"import_type":"xml","schema":"supplier_import_job_identity_v1","supplier_feed_id":22,"supplier_id":11,"xml_mapping_template_id":55}',
            $identity->canonicalBytes(),
        );
        $this->assertSame(
            '39c43b709b1627964104baa71d0069e18ab4678421862ca5a986855a6f6a39f5',
            $identity->fingerprint(),
        );

        $shuffled = ImportJobIdentity::fromArray(array_reverse($identity->toCanonicalArray(), true));
        $restored = ImportJobIdentity::fromCanonicalBytes(
            $identity->canonicalBytes(),
            $identity->fingerprint(),
        );

        $this->assertSame($identity->toCanonicalArray(), $shuffled->toCanonicalArray());
        $this->assertSame($identity->canonicalBytes(), $shuffled->canonicalBytes());
        $this->assertSame($identity->canonicalBytes(), $restored->canonicalBytes());
    }

    public function test_import_job_identity_rejects_unsupported_malformed_and_contradictory_selectors(): void
    {
        $valid = $this->identity()->toCanonicalArray();

        $invalid = [
            'unsupported version' => [...$valid, 'schema' => 'supplier_import_job_identity_v2'],
            'xml without template' => [...$valid, 'xml_mapping_template_id' => null],
            'csv with template' => [...$valid, 'import_type' => 'csv'],
            'empty selector' => [...$valid, 'xml_mapping_template_id' => ''],
            'unknown import type' => [...$valid, 'import_type' => 'json'],
            'extra field' => [...$valid, 'fallback' => true],
        ];

        foreach ($invalid as $case => $values) {
            try {
                ImportJobIdentity::fromArray($values);
                $this->fail("{$case} was accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $csv = ImportJobIdentity::fromArray([
            'schema' => ImportJobIdentity::VERSION,
            'import_job_id' => 44,
            'supplier_id' => 11,
            'supplier_feed_id' => 22,
            'xml_mapping_template_id' => null,
            'import_type' => 'csv',
        ]);
        $this->assertStringContainsString('"xml_mapping_template_id":null', $csv->canonicalBytes());

        $this->expectException(InvalidArgumentException::class);
        ImportJobIdentity::fromCanonicalBytes(
            str_replace('"import_type":"xml"', '"import_type":"csv"', $this->identity()->canonicalBytes()),
            $this->identity()->fingerprint(),
        );
    }

    public function test_resolved_context_is_byte_reconstructable_and_uses_frozen_fingerprint(): void
    {
        $context = $this->context();
        $shuffled = ResolvedSupplierImportSourceContext::fromArray(
            array_reverse($context->toCanonicalArray(), true),
        );
        $restored = ResolvedSupplierImportSourceContext::fromCanonicalBytes(
            $context->canonicalBytes(),
            $context->fingerprint(),
        );

        $this->assertSame(
            '8a09ef8a46d592b28f5b97ef8eb4a2e9fc222935c9adf5f74b9c34f6b524e521',
            $context->fingerprint(),
        );
        $this->assertSame($context->toCanonicalArray(), $shuffled->toCanonicalArray());
        $this->assertSame($context->canonicalBytes(), $shuffled->canonicalBytes());
        $this->assertSame($context->canonicalBytes(), $restored->canonicalBytes());
        $this->assertStringContainsString('"source_profile_id":33', $context->canonicalBytes());
        $this->assertStringContainsString(
            '"port":null',
            $context->toCanonicalArray()['source_locator_canonical_bytes'],
        );
    }

    public function test_resolved_context_rejects_tampered_bytes_unknown_versions_and_contradictions(): void
    {
        $context = $this->context();
        $values = $context->toCanonicalArray();

        foreach ([
            [...$values, 'schema' => 'supplier_import_resolved_source_context_v2'],
            [...$values, 'source_identity' => ''],
            [...$values, 'source_descriptor_fingerprint' => str_repeat('A', 64)],
            [...$values, 'source_locator_contract_key' => 'different-locator-v1'],
            [...$values, 'mapping_contract_version' => 'supplier_import_mapping_contract_v2'],
        ] as $invalid) {
            try {
                ResolvedSupplierImportSourceContext::fromArray($invalid);
                $this->fail('Invalid resolved source context was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        ResolvedSupplierImportSourceContext::fromCanonicalBytes(
            str_replace('feeds.example.test', 'other.example.test', $context->canonicalBytes()),
            $context->fingerprint(),
        );
    }

    public function test_resolved_context_preserves_unicode_bytes_without_platform_normalization(): void
    {
        $precomposed = $this->context("\u{00E9}");
        $decomposed = $this->context("e\u{0301}");

        $this->assertStringContainsString(
            '"currency":"é"',
            $precomposed->toCanonicalArray()['mapping_canonical_bytes'],
        );
        $this->assertStringContainsString(
            '"currency":"é"',
            $decomposed->toCanonicalArray()['mapping_canonical_bytes'],
        );
        $this->assertNotSame($precomposed->canonicalBytes(), $decomposed->canonicalBytes());
        $this->assertNotSame($precomposed->fingerprint(), $decomposed->fingerprint());
        $this->assertSame(
            $precomposed->canonicalBytes(),
            ResolvedSupplierImportSourceContext::fromCanonicalBytes(
                $precomposed->canonicalBytes(),
                $precomposed->fingerprint(),
            )->canonicalBytes(),
        );
    }

    public function test_source_execution_is_deterministic_reconstructable_and_type_bound(): void
    {
        $identity = $this->identity();
        $context = $this->context();
        $execution = CanonicalSupplierImportSourceExecution::fromContracts(
            $identity,
            $context,
            66,
            '2026-08-28T09:10:11.123456Z',
        );
        $restored = CanonicalSupplierImportSourceExecution::fromCanonicalBytes(
            $execution->canonicalBytes(),
            $execution->fingerprint(),
            $identity,
            $context,
        );

        $this->assertSame(
            '119f2269dafc727ac581b0be6e28fc0a13bbeb553547e4b6aa8519da531851e7',
            $execution->fingerprint(),
        );
        $this->assertSame($execution->canonicalBytes(), $restored->canonicalBytes());
        $this->assertSame($identity->canonicalBytes(), $execution->persistenceAttributes()['import_job_identity_canonical_bytes']);
        $this->assertSame('2026-08-28 09:10:11.123456', $execution->persistenceAttributes()['captured_at']);

        $csvIdentity = ImportJobIdentity::fromArray([
            'schema' => ImportJobIdentity::VERSION,
            'import_job_id' => 44,
            'supplier_id' => 11,
            'supplier_feed_id' => 22,
            'xml_mapping_template_id' => null,
            'import_type' => 'csv',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('source_execution_ownership_mismatch');
        CanonicalSupplierImportSourceExecution::fromContracts(
            $csvIdentity,
            $context,
            66,
            '2026-08-28T09:10:11.123456Z',
        );
    }

    public function test_source_execution_rejects_noncanonical_timestamp_and_tampered_bytes(): void
    {
        $identity = $this->identity();
        $context = $this->context();

        try {
            CanonicalSupplierImportSourceExecution::fromContracts(
                $identity,
                $context,
                66,
                '2026-08-28 09:10:11',
            );
            $this->fail('Noncanonical capture instant was accepted.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $execution = CanonicalSupplierImportSourceExecution::fromContracts(
            $identity,
            $context,
            66,
            '2026-08-28T09:10:11.123456Z',
        );

        $this->expectException(InvalidArgumentException::class);
        CanonicalSupplierImportSourceExecution::fromCanonicalBytes(
            str_replace('"import_history_id":66', '"import_history_id":67', $execution->canonicalBytes()),
            $execution->fingerprint(),
            $identity,
            $context,
        );
    }

    public function test_source_execution_model_is_guarded_hidden_and_immutable(): void
    {
        $model = new SupplierImportSourceExecution;

        try {
            $model->fill(['source_identity' => 'forbidden']);
            $this->fail('Mass assignment was accepted.');
        } catch (MassAssignmentException) {
            $this->addToAssertionCount(1);
        }

        $model->setRawAttributes([
            'id' => 1,
            'source_identity' => 'snapshot-source-v1:profile:00112233445566778899aabbccddeeff',
            'source_descriptor_fingerprint' => str_repeat('a', 64),
            'import_job_identity_canonical_bytes' => 'secret-free-identity-bytes',
            'import_job_identity_fingerprint' => str_repeat('b', 64),
            'source_execution_fingerprint' => str_repeat('c', 64),
        ], true);
        $model->exists = true;

        $array = $model->toArray();
        foreach ([
            'source_identity',
            'source_descriptor_fingerprint',
            'import_job_identity_canonical_bytes',
            'import_job_identity_fingerprint',
            'source_execution_fingerprint',
        ] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $array);
        }

        foreach (['update', 'updateQuietly', 'touch'] as $method) {
            try {
                $model->{$method}(['source_identity' => 'different']);
                $this->fail("{$method} mutation was accepted.");
            } catch (LogicException $exception) {
                $this->assertSame('Immutable canonical supplier records cannot be mutated.', $exception->getMessage());
            }
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Immutable canonical supplier records cannot be mutated.');
        $model->delete();
    }

    private function identity(): ImportJobIdentity
    {
        return ImportJobIdentity::fromArray([
            'schema' => ImportJobIdentity::VERSION,
            'import_job_id' => 44,
            'supplier_id' => 11,
            'supplier_feed_id' => 22,
            'xml_mapping_template_id' => 55,
            'import_type' => 'xml',
        ]);
    }

    private function context(string $currency = 'BGN'): ResolvedSupplierImportSourceContext
    {
        $mapping = CanonicalSupplierImportMapping::fromArray([
            'schema' => CanonicalSupplierImportMapping::VERSION,
            'feed_type' => 'xml',
            'effective_mapping' => [
                'root_path' => 'CONTENT.PRICE',
                'field_map' => ['sku' => 'WIC', 'price' => 'PRICE'],
                'validation_rules' => ['sku' => 'required'],
                'defaults' => ['currency' => $currency],
            ],
        ]);
        $locator = CanonicalSupplierSourceLocator::fromArray([
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
        ]);
        $descriptor = CanonicalSupplierSourceProfileDescriptor::fromContracts(
            supplierId: 11,
            supplierFeedId: 22,
            locator: $locator,
            sourceAccessScopeKey: 'source-access-v1:catalog.bg',
            feedType: 'xml',
            importerKey: 'xml-importer',
            importerVersion: '1',
            mapping: $mapping,
        );
        $profile = (new SupplierImportSourceProfile)->setRawAttributes([
            'id' => 33,
            ...$descriptor->persistenceAttributes(),
            'source_identity' => 'snapshot-source-v1:profile:00112233445566778899aabbccddeeff',
        ], true);
        $profile->exists = true;

        return ResolvedSupplierImportSourceContext::fromProfile($profile);
    }
}
