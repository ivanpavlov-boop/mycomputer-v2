<?php

namespace Tests\Feature;

use App\Models\AttributeAlias;
use App\Models\AttributeGroup;
use App\Models\CanonicalAttribute;
use App\Models\CanonicalAttributeValue;
use App\Models\CsvImportJob;
use App\Models\PcBuild;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\SupplierProductAttribute;
use App\Services\Attributes\AttributeMappingReviewService;
use App\Services\Attributes\AttributeNameMapper;
use App\Services\Attributes\AttributeNormalizationService;
use App\Services\Attributes\AttributeValueNormalizer;
use App\Services\Attributes\DuplicateAttributeDetectionService;
use App\Services\Attributes\SupplierAttributeExtractionService;
use App\Services\Attributes\UnitConversionService;
use App\Services\Csv\CsvImportService;
use App\Services\PcBuilder\CompatibilityService;
use App\Services\Products\CompareService;
use App\Services\Products\ProductSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use SimpleXMLElement;
use Tests\TestCase;

class AttributeNormalizationEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_common_attribute_names_map_to_same_canonical_attribute(): void
    {
        $this->seed();

        $mapper = app(AttributeNameMapper::class);
        $ids = collect(['RAM', 'Memory', 'Оперативна памет'])
            ->map(fn (string $name): ?int => $mapper->map($name)['attribute']?->id)
            ->unique()
            ->values();

        $this->assertCount(1, $ids);
        $this->assertSame(CanonicalAttribute::query()->where('code', 'ram')->value('id'), $ids->first());
    }

    public function test_bulgarian_attribute_aliases_map_to_canonical_attributes(): void
    {
        $this->seed();

        $mapper = app(AttributeNameMapper::class);

        $this->assertSame('cpu', $mapper->map('Процесор')['attribute']?->code);
        $this->assertSame('gpu', $mapper->map('Видео карта')['attribute']?->code);
        $this->assertSame('ram', $mapper->map('Памет')['attribute']?->code);
        $this->assertSame('ram', $mapper->map('Оперативна памет')['attribute']?->code);
    }

    public function test_common_attribute_values_map_to_same_canonical_value(): void
    {
        $this->seed();

        $ram = CanonicalAttribute::query()->where('code', 'ram')->firstOrFail();
        $normalizer = app(AttributeValueNormalizer::class);
        $ids = collect(['16GB', '16 GB', '16384 MB', '16 гб'])
            ->map(fn (string $value): ?int => $normalizer->normalize($ram, $value)['value']?->id)
            ->unique()
            ->values();

        $this->assertCount(1, $ids);
        $this->assertSame('16 GB', CanonicalAttributeValue::query()->findOrFail($ids->first())->display_value);
    }

    public function test_storage_values_and_unit_conversions_keep_correct_canonical_slugs(): void
    {
        $this->seed();

        $storage = CanonicalAttribute::query()->where('code', 'storage')->firstOrFail();
        $valueNormalizer = app(AttributeValueNormalizer::class);
        $unitConversion = app(UnitConversionService::class);

        foreach (['1024 GB', '1 TB', '1TB'] as $raw) {
            $result = $valueNormalizer->normalize($storage, $raw);

            $this->assertSame('1 TB', $result['value']?->display_value);
            $this->assertSame('1_tb', $result['value']?->normalized_value);
        }

        $this->assertSame('16_gb', $unitConversion->normalize('16384 MB')['normalized_value']);
        $this->assertSame('1_tb', $unitConversion->normalize('1024 GB')['normalized_value']);
        $this->assertSame('2_tb', $unitConversion->normalize('2048 GB')['normalized_value']);
        $this->assertSame('512_gb', $unitConversion->normalize('512 GB')['normalized_value']);
        $this->assertSame('1_tb', $unitConversion->normalize('1 TB', 'gb')['normalized_value']);
        $this->assertSame('15_6_inch', $unitConversion->normalize('15.6 инча')['normalized_value']);
        $this->assertSame('2_4_ghz', $unitConversion->normalize('2400 MHz')['normalized_value']);
    }

    public function test_supplier_specific_alias_can_override_generic_alias(): void
    {
        $this->seed();

        $supplier = Supplier::factory()->create();
        $special = CanonicalAttribute::query()->create([
            'code' => 'vendor_memory_code',
            'name' => 'Vendor Memory Code',
            'type' => 'text',
            'is_filterable' => false,
            'is_comparable' => false,
            'is_active' => true,
        ]);

        AttributeAlias::query()->create([
            'canonical_attribute_id' => $special->id,
            'alias' => 'Memory',
            'normalized_alias' => 'memory',
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'confidence' => 95,
            'is_active' => true,
        ]);

        $mapped = app(AttributeNameMapper::class)->map('Memory', $supplier, 'xml');

        $this->assertSame($special->id, $mapped['attribute']->id);
    }

    public function test_low_confidence_attribute_is_staged_for_review_and_does_not_pollute_catalog(): void
    {
        $this->seed();

        $product = Product::factory()->create();
        $raw = app(AttributeNormalizationService::class)->stageAndNormalize([
            'product_id' => $product->id,
            'source_type' => 'csv',
            'raw_name' => 'Mystery Thing',
            'raw_value' => 'Unknown Value',
        ]);

        $this->assertSame('needs_review', $raw->status);
        $this->assertNull($raw->canonical_attribute_id);
        $this->assertSame(0, ProductAttributeValue::query()->where('product_id', $product->id)->count());
    }

    public function test_csv_attribute_import_uses_canonical_mapping(): void
    {
        $this->seed();

        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();
        $job = $this->csvImportJob('attributes', "sku,attribute_group,attribute_name,attribute_value,unit\n{$product->sku},Memory,RAM,16GB,\n");

        app(CsvImportService::class)->process($job);

        $this->assertSame('completed', $job->fresh()->status);
        $this->assertDatabaseHas('supplier_product_attributes', [
            'product_id' => $product->id,
            'raw_name' => 'RAM',
            'status' => 'mapped',
        ]);
        $this->assertDatabaseHas('product_attribute_values', [
            'product_id' => $product->id,
            'canonical_attribute_id' => CanonicalAttribute::query()->where('code', 'ram')->value('id'),
        ]);
    }

    public function test_product_sync_stages_and_writes_mapped_supplier_attributes(): void
    {
        $this->seed();

        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'SYNC-RAM-001',
            'sku' => 'SYNC-RAM-001',
            'source' => Product::SOURCE_SUPPLIER_IMPORT,
        ]);
        $supplierProduct = SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'SYNC-RAM-001',
            'ean' => '1234567890123',
            'mpn' => 'SYNC-RAM-MPN',
            'name' => 'Sync RAM Product',
            'price' => 100,
            'quantity' => 4,
            'currency' => 'EUR',
            'raw_data' => ['attributes' => ['Оперативна памет' => '16384 MB']],
            'payload_hash' => sha1('SYNC-RAM-001'),
            'received_at' => now(),
            'status' => 'new',
        ]);

        app(ProductSyncService::class)->sync($supplierProduct);

        $product->refresh();

        $this->assertDatabaseHas('supplier_product_attributes', [
            'supplier_product_id' => $supplierProduct->id,
            'product_id' => $product->id,
            'status' => 'mapped',
        ]);
        $this->assertDatabaseHas('product_attribute_values', [
            'product_id' => $product->id,
            'canonical_attribute_id' => CanonicalAttribute::query()->where('code', 'ram')->value('id'),
            'canonical_attribute_value_id' => CanonicalAttributeValue::query()->where('display_value', '16 GB')->value('id'),
        ]);
    }

    public function test_repeated_xml_attributes_are_staged_and_canonicalized(): void
    {
        $this->seed();

        $supplier = Supplier::factory()->create();
        $supplierProduct = SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'XML-ATTR-001',
            'name' => 'XML Attribute Laptop',
            'price' => 1500,
            'quantity' => 3,
            'currency' => 'EUR',
            'raw_data' => [],
            'payload_hash' => sha1('XML-ATTR-001'),
            'received_at' => now(),
            'status' => 'new',
        ]);

        $xml = new SimpleXMLElement(<<<'XML'
<product>
    <attributes>
        <attribute name="RAM" value="16GB" />
        <attribute name="Storage" value="1TB" />
        <attribute name="Display" value="15.6 инча" />
        <attribute name="GPU" value="RTX 5070" />
        <attribute name="Unmapped Mystery" value="ABC" />
    </attributes>
</product>
XML);

        $service = app(SupplierAttributeExtractionService::class);
        $attributes = $service->extractFromXml($xml);
        $count = $service->stage($supplierProduct, $attributes, 'xml', 'fixture');

        $this->assertSame(5, $count);
        $this->assertSame(5, SupplierProductAttribute::query()->where('supplier_product_id', $supplierProduct->id)->count());

        foreach (['ram', 'storage', 'display_size', 'gpu'] as $code) {
            $this->assertDatabaseHas('supplier_product_attributes', [
                'supplier_product_id' => $supplierProduct->id,
                'canonical_attribute_id' => CanonicalAttribute::query()->where('code', $code)->value('id'),
                'status' => 'mapped',
            ]);
        }

        $this->assertDatabaseHas('supplier_product_attributes', [
            'supplier_product_id' => $supplierProduct->id,
            'raw_name' => 'Unmapped Mystery',
            'status' => 'needs_review',
        ]);

        $this->assertDatabaseMissing('product_attribute_values', [
            'custom_value' => 'Unmapped Mystery',
        ]);
    }

    public function test_public_filters_and_search_payload_use_canonical_attributes(): void
    {
        $this->seed();

        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();
        $this->assignCanonical($product, 'ram', '16 GB');

        $this->getJson('/api/v1/products?attributes[]=16_gb')
            ->assertOk()
            ->assertJsonFragment(['sku' => $product->sku]);

        $payload = $product->fresh()->toSearchableArray();

        $this->assertContains('ram', collect($payload['attributes'])->pluck('slug')->all());
        $this->assertContains('16_gb', collect($payload['attributes'])->pluck('value_slug')->all());
        $this->assertSame(16.0, $payload['attribute_numeric']['ram_gb']);
    }

    public function test_search_and_filter_facets_remain_canonical_after_imports(): void
    {
        $this->seed();

        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();
        $this->assignCanonical($product, 'ram', '16 GB');
        $this->assignCanonical($product, 'storage', '1 TB');
        $this->assignCanonical($product, 'cpu', 'Intel Core i7');

        $this->getJson('/api/v1/products?attributes[]=ram')
            ->assertOk()
            ->assertJsonFragment(['sku' => $product->sku]);

        $this->getJson('/api/v1/products?attributes[]=1_tb')
            ->assertOk()
            ->assertJsonFragment(['sku' => $product->sku]);

        $filters = $this->getJson('/api/v1/filters/categories/'.$product->category->slug)
            ->assertOk()
            ->json('data.attributes');

        $slugs = collect($filters)->pluck('slug');
        $this->assertTrue($slugs->contains('ram'));
        $this->assertTrue($slugs->contains('storage'));
        $this->assertTrue($slugs->contains('cpu'));
        $this->assertFalse($slugs->contains('memory'));
        $this->assertFalse($slugs->contains('оперативна-памет'));

        $payload = $product->fresh()->toSearchableArray();
        $attributeSlugs = collect($payload['attributes'])->pluck('slug');
        $valueSlugs = collect($payload['attributes'])->pluck('value_slug');

        $this->assertTrue($attributeSlugs->contains('ram'));
        $this->assertTrue($attributeSlugs->contains('storage'));
        $this->assertTrue($attributeSlugs->contains('cpu'));
        $this->assertTrue($valueSlugs->contains('1_tb'));
    }

    public function test_compare_and_pc_builder_read_canonical_attribute_codes(): void
    {
        $this->seed();

        $first = Product::factory()->create(['active' => true, 'published_at' => now()]);
        $second = Product::factory()->create(['active' => true, 'published_at' => now()]);
        $this->assignCanonical($first, 'ram', '16 GB');
        $this->assignCanonical($second, 'ram', '16 GB');

        $comparison = app(CompareService::class)->buildComparison(collect([$first->fresh(['attributes.canonicalAttribute', 'attributes.canonicalAttributeValue']), $second->fresh(['attributes.canonicalAttribute', 'attributes.canonicalAttributeValue'])]));
        $this->assertSame('16 GB', $comparison['shared_attributes']['ram']);

        $cpu = Product::factory()->create();
        $motherboard = Product::factory()->create();
        $this->assignCanonical($cpu, 'cpu_socket', 'AM5');
        $this->assignCanonical($motherboard, 'motherboard_socket', 'AM5');

        $build = PcBuild::query()->create(['name' => 'Canonical Build', 'total_price' => 0, 'status' => 'draft']);
        $build->items()->create(['product_id' => $cpu->id, 'component_type' => 'cpu', 'quantity' => 1]);
        $build->items()->create(['product_id' => $motherboard->id, 'component_type' => 'motherboard', 'quantity' => 1]);

        $result = app(CompatibilityService::class)->validate($build->fresh(['items.product.attributeValues.canonicalAttribute', 'items.product.attributeValues.canonicalAttributeValue']));
        $this->assertTrue($result['compatible']);
    }

    public function test_admin_review_can_approve_mapping_and_create_aliases(): void
    {
        $this->seed();

        $raw = SupplierProductAttribute::query()->create([
            'source_type' => 'manual',
            'raw_name' => 'Опер. памет',
            'raw_value' => '16 гб',
            'status' => 'needs_review',
        ]);
        $attribute = CanonicalAttribute::query()->where('code', 'ram')->firstOrFail();
        $value = CanonicalAttributeValue::query()->where('display_value', '16 GB')->firstOrFail();

        app(AttributeMappingReviewService::class)->approve($raw, $attribute, $value);

        $this->assertDatabaseHas('attribute_aliases', [
            'canonical_attribute_id' => $attribute->id,
            'alias' => 'Опер. памет',
        ]);
        $this->assertDatabaseHas('attribute_value_aliases', [
            'canonical_attribute_value_id' => $value->id,
            'alias' => '16 гб',
        ]);
        $this->assertSame('mapped', $raw->fresh()->status);
    }

    public function test_duplicate_report_detects_duplicate_canonical_definitions(): void
    {
        $this->seed();

        CanonicalAttribute::query()->create([
            'code' => 'ram_duplicate',
            'name' => 'RAM',
            'type' => 'text',
            'is_active' => true,
        ]);

        $duplicates = app(DuplicateAttributeDetectionService::class)->duplicateAttributes();

        $this->assertTrue($duplicates->has('ram'));
    }

    private function assignCanonical(Product $product, string $attributeCode, string $displayValue): ProductAttributeValue
    {
        $attribute = CanonicalAttribute::query()->where('code', $attributeCode)->firstOrFail();
        $normalized = app(UnitConversionService::class)->normalize($displayValue, $attribute->unit);
        $value = CanonicalAttributeValue::query()->firstOrCreate([
            'canonical_attribute_id' => $attribute->id,
            'normalized_value' => $normalized['normalized_value'],
        ], [
            'display_value' => $displayValue,
            'numeric_value' => $normalized['numeric_value'],
            'unit' => $normalized['unit'],
            'is_active' => true,
        ]);

        return ProductAttributeValue::query()->updateOrCreate([
            'product_id' => $product->id,
            'canonical_attribute_id' => $attribute->id,
            'canonical_attribute_value_id' => $value->id,
        ], [
            'product_attribute_id' => ProductAttribute::query()->firstOrCreate([
                'slug' => $attribute->code,
            ], [
                'attribute_group_id' => AttributeGroup::query()->firstOrCreate(['slug' => 'test'], ['name' => 'Test'])->id,
                'name' => $attribute->name,
                'type' => 'select',
                'is_filterable' => true,
                'is_active' => true,
            ])->id,
            'attribute_value_id' => null,
            'custom_value' => null,
            'is_filterable' => true,
        ]);
    }

    private function csvImportJob(string $type, string $contents): CsvImportJob
    {
        File::ensureDirectoryExists(storage_path('app/imports'));
        $path = 'imports/attribute-normalization-'.uniqid().'.csv';
        file_put_contents(storage_path('app/'.$path), $contents);

        return CsvImportJob::query()->create([
            'type' => $type,
            'status' => 'pending',
            'file_path' => $path,
            'original_filename' => basename($path),
            'mode' => 'create-or-update',
        ]);
    }
}
