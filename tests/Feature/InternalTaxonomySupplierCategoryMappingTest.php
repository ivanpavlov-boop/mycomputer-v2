<?php

namespace Tests\Feature;

use App\Filament\Resources\CanonicalProductFamilies\CanonicalProductFamilyResource;
use App\Filament\Resources\CanonicalProductFamilies\Pages\ListCanonicalProductFamilies;
use App\Filament\Resources\SupplierCategoryMappings\Pages\ListSupplierCategoryMappings;
use App\Filament\Resources\SupplierCategoryMappings\SupplierCategoryMappingResource;
use App\Models\AttributeValue;
use App\Models\CanonicalProductFamily;
use App\Models\Category;
use App\Models\CategoryProductAttribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\Supplier;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\Taxonomy\SupplierCategoryFamilyInferenceService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use JsonException;
use Livewire\Livewire;
use Tests\TestCase;

class InternalTaxonomySupplierCategoryMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_taxonomy_tables_exist_with_expected_fields_and_defaults(): void
    {
        $this->assertTrue(Schema::hasTable('canonical_product_families'));
        $this->assertTrue(Schema::hasTable('supplier_category_mappings'));

        foreach ([
            'code',
            'name_bg',
            'name_en',
            'description_bg',
            'description_en',
            'sort_order',
            'active',
            'metadata',
            'deleted_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('canonical_product_families', $column), $column);
        }

        foreach ([
            'supplier_id',
            'supplier_key',
            'supplier_name',
            'supplier_category_name',
            'supplier_category_slug',
            'supplier_category_path',
            'supplier_category_external_id',
            'supplier_category_hash',
            'canonical_product_family_id',
            'target_category_id',
            'status',
            'confidence',
            'match_reason',
            'notes',
            'reviewed_at',
            'reviewed_by',
            'metadata',
            'deleted_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('supplier_category_mappings', $column), $column);
        }

        $family = CanonicalProductFamily::query()->create([
            'code' => 'peripherals',
            'name_bg' => 'Периферия',
            'name_en' => 'Peripherals',
        ]);
        $category = Category::factory()->create(['name' => 'Internal Mice', 'slug' => 'internal-mice']);
        $supplier = Supplier::factory()->create(['company_name' => 'APCOM', 'slug' => 'apcom']);

        $mapping = SupplierCategoryMapping::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->company_name,
            'supplier_category_name' => 'Mice & Keyboards',
            'canonical_product_family_id' => $family->id,
            'target_category_id' => $category->id,
        ])->refresh();

        $this->assertSame(SupplierCategoryMapping::STATUS_PENDING_REVIEW, $mapping->status);
        $this->assertNotEmpty($mapping->supplier_category_hash);
        $this->assertSame($family->id, $mapping->canonicalProductFamily->id);
        $this->assertSame($category->id, $mapping->targetCategory->id);
    }

    public function test_supplier_category_mapping_hash_has_uniqueness_protection(): void
    {
        $supplier = Supplier::factory()->create(['company_name' => 'APCOM', 'slug' => 'apcom']);

        SupplierCategoryMapping::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_name' => 'APCOM',
            'supplier_category_name' => 'Power & Cable',
        ]);

        $this->expectException(QueryException::class);

        SupplierCategoryMapping::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_name' => 'APCOM',
            'supplier_category_name' => 'Power & Cable',
        ]);
    }

    public function test_commands_are_registered_with_only_allowed_apply_options(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('taxonomy:seed-canonical-families', $commands);
        $this->assertTrue($commands['taxonomy:seed-canonical-families']->getDefinition()->hasOption('apply'));

        $this->assertArrayHasKey('supplier-categories:audit', $commands);
        $this->assertFalse($commands['supplier-categories:audit']->getDefinition()->hasOption('apply'));

        $this->assertArrayHasKey('supplier-categories:discover-mappings', $commands);
        $this->assertTrue($commands['supplier-categories:discover-mappings']->getDefinition()->hasOption('apply'));
    }

    public function test_seed_canonical_families_dry_run_apply_and_idempotency(): void
    {
        $this->assertSame(0, Artisan::call('taxonomy:seed-canonical-families'));
        $this->assertStringContainsString('Dry-run only. No records were changed.', Artisan::output());
        $this->assertSame(0, CanonicalProductFamily::query()->count());

        $this->assertSame(0, Artisan::call('taxonomy:seed-canonical-families', ['--apply' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('Canonical product families applied.', $output);
        $this->assertStringContainsString('Families created: 17', $output);
        $this->assertStringContainsString('products changed: 0', $output);
        $this->assertStringContainsString('supplier_products changed: 0', $output);
        $this->assertSame(17, CanonicalProductFamily::query()->count());
        $this->assertDatabaseHas('canonical_product_families', [
            'code' => 'cables_adapters',
            'name_bg' => 'Кабели и адаптери',
            'active' => true,
        ]);
        $this->assertDatabaseHas('canonical_product_families', [
            'code' => 'unknown',
            'name_bg' => 'Некласифицирано',
        ]);

        $this->assertSame(0, Artisan::call('taxonomy:seed-canonical-families', ['--apply' => true]));
        $this->assertStringContainsString('Families already present: 17', Artisan::output());
        $this->assertSame(17, CanonicalProductFamily::query()->count());
    }

    /**
     * @throws JsonException
     */
    public function test_supplier_categories_audit_reports_candidates_and_is_read_only(): void
    {
        $supplier = Supplier::factory()->create(['company_name' => 'APCOM', 'slug' => 'apcom']);
        $this->supplierProduct($supplier, 'Power & Cable');
        $this->supplierProduct($supplier, 'Power & Cable');
        $this->supplierProduct($supplier, 'Cases & Protection');

        $counts = $this->protectedCounts();

        $payload = $this->commandJson('supplier-categories:audit', [
            '--format' => 'json',
            '--limit' => 50,
        ]);

        $this->assertSame(2, $payload['summary']['supplier_category_candidates']);
        $this->assertSame(2, $payload['summary']['unmapped_supplier_categories']);
        $this->assertSame($counts, $this->protectedCounts());

        $rows = collect($payload['rows'])->keyBy('supplier_category_name');

        $this->assertSame(2, $rows['Power & Cable']['product_count']);
        $this->assertSame('cables_adapters', $rows['Power & Cable']['suggested_canonical_family']);
        $this->assertSame('cases_protection', $rows['Cases & Protection']['suggested_canonical_family']);
        $this->assertSame('create pending mapping', $rows['Power & Cable']['next_action']);
    }

    /**
     * @throws JsonException
     */
    public function test_supplier_categories_audit_supports_supplier_filter_only_unmapped_and_missing_data_message(): void
    {
        $apcom = Supplier::factory()->create(['company_name' => 'APCOM', 'slug' => 'apcom']);
        $other = Supplier::factory()->create(['company_name' => 'Other Supplier', 'slug' => 'other']);
        $this->supplierProduct($apcom, 'Power & Cable');
        $this->supplierProduct($apcom, 'Cases & Protection');
        $this->supplierProduct($other, 'Mice & Keyboards');

        SupplierCategoryMapping::query()->create([
            'supplier_id' => $apcom->id,
            'supplier_name' => 'APCOM',
            'supplier_category_name' => 'Power & Cable',
            'status' => SupplierCategoryMapping::STATUS_PENDING_REVIEW,
        ]);

        $payload = $this->commandJson('supplier-categories:audit', [
            '--format' => 'json',
            '--supplier' => 'apcom',
            '--only-unmapped' => true,
        ]);

        $this->assertCount(1, $payload['rows']);
        $this->assertSame('Cases & Protection', $payload['rows'][0]['supplier_category_name']);
        $this->assertSame(1, $payload['summary']['unmapped_supplier_categories']);

        $this->assertSame(0, Artisan::call('supplier-categories:audit', [
            '--supplier' => 'missing-supplier',
        ]));
        $this->assertStringContainsString('No supplier category data found', Artisan::output());
    }

    /**
     * @throws JsonException
     */
    public function test_discover_mappings_dry_run_apply_pending_status_and_idempotency(): void
    {
        $this->assertSame(0, Artisan::call('taxonomy:seed-canonical-families', ['--apply' => true]));

        $supplier = Supplier::factory()->create(['company_name' => 'APCOM', 'slug' => 'apcom']);
        $this->supplierProduct($supplier, 'Power & Cable');
        $this->supplierProduct($supplier, 'Mice & Keyboards');

        $this->assertSame(0, Artisan::call('supplier-categories:discover-mappings', ['--limit' => 50]));
        $this->assertStringContainsString('Dry-run only. No records were changed.', Artisan::output());
        $this->assertSame(0, SupplierCategoryMapping::query()->count());

        $payload = $this->commandJson('supplier-categories:discover-mappings', [
            '--format' => 'json',
            '--apply' => true,
            '--limit' => 50,
        ]);

        $this->assertSame(2, $payload['summary']['mappings_created']);
        $this->assertSame(2, $payload['summary']['records_changed']['supplier_category_mappings']);
        $this->assertSame(2, SupplierCategoryMapping::query()->count());
        $this->assertTrue(SupplierCategoryMapping::query()->where('status', SupplierCategoryMapping::STATUS_PENDING_REVIEW)->whereNull('reviewed_at')->exists());
        $this->assertFalse(SupplierCategoryMapping::query()->where('status', SupplierCategoryMapping::STATUS_APPROVED)->exists());

        $powerMapping = SupplierCategoryMapping::query()
            ->where('supplier_category_name', 'Power & Cable')
            ->firstOrFail();

        $this->assertSame('cables_adapters', $powerMapping->canonicalProductFamily?->code);
        $this->assertSame(SupplierCategoryMapping::CONFIDENCE_HIGH, $powerMapping->confidence);

        $secondPayload = $this->commandJson('supplier-categories:discover-mappings', [
            '--format' => 'json',
            '--apply' => true,
            '--limit' => 50,
        ]);

        $this->assertSame(0, $secondPayload['summary']['mappings_created']);
        $this->assertSame(2, $secondPayload['summary']['mappings_already_present']);
        $this->assertSame(2, SupplierCategoryMapping::query()->count());
    }

    public function test_conservative_family_inference_maps_known_categories_and_keeps_unknown_pending(): void
    {
        $service = app(SupplierCategoryFamilyInferenceService::class);

        $this->assertSame('cables_adapters', $service->infer(['Power & Cable'])['family_code']);
        $this->assertSame('cases_protection', $service->infer(['Cases & Protection'])['family_code']);
        $this->assertSame('peripherals', $service->infer(['Mice & Keyboards'])['family_code']);
        $this->assertSame('apple_devices', $service->infer(['MacBook Air'])['family_code']);

        $unknown = $service->infer(['Completely Bespoke Supplier Bucket']);

        $this->assertSame('unknown', $unknown['family_code']);
        $this->assertSame(SupplierCategoryMapping::CONFIDENCE_LOW, $unknown['confidence']);
        $this->assertStringContainsString('manual classification', $unknown['match_reason']);
    }

    public function test_apply_commands_mutate_only_allowed_taxonomy_tables(): void
    {
        $supplier = Supplier::factory()->create(['company_name' => 'APCOM', 'slug' => 'apcom']);
        $category = Category::factory()->create([
            'name' => 'Cases & Protection',
            'slug' => 'cases-protection',
            'description' => 'Curated description',
            'image_path' => 'categories/cases.jpg',
        ]);
        $product = Product::factory()->supplierPublished()->create([
            'category_id' => $category->id,
            'sku' => 'TAXONOMY-SAFE-001',
        ]);
        $attribute = ProductAttribute::factory()->create([
            'code' => 'material',
            'slug' => 'material',
            'name_bg' => 'Материал',
        ]);
        $attributeValue = AttributeValue::factory()->create([
            'product_attribute_id' => $attribute->id,
            'value' => 'Leather',
            'slug' => 'leather',
        ]);
        $assignment = CategoryProductAttribute::factory()->create([
            'category_id' => $category->id,
            'product_attribute_id' => $attribute->id,
            'is_required' => true,
        ]);
        $productValue = ProductAttributeValue::factory()->create([
            'product_id' => $product->id,
            'product_attribute_id' => $attribute->id,
            'attribute_value_id' => $attributeValue->id,
            'value_text' => 'Leather',
            'custom_value' => 'Leather',
        ]);
        $supplierProduct = $this->supplierProduct($supplier, 'Cases & Protection');

        $counts = $this->protectedCounts();
        $snapshots = [
            'product' => $product->fresh()->only(['category_id', 'name', 'sku', 'workflow_status', 'product_status', 'active', 'updated_at']),
            'supplierProduct' => $supplierProduct->fresh()->only(['category_name', 'name', 'supplier_sku', 'raw_data', 'status', 'updated_at']),
            'category' => $category->fresh()->only(['name', 'slug', 'description', 'image_path', 'updated_at']),
            'assignment' => $assignment->fresh()->only(['category_id', 'product_attribute_id', 'is_required', 'is_filterable', 'is_visible_on_product', 'is_comparable', 'sort_order', 'updated_at']),
            'attribute' => $attribute->fresh()->only(['code', 'name_bg', 'slug', 'type', 'updated_at']),
            'attributeValue' => $attributeValue->fresh()->only(['product_attribute_id', 'value', 'slug', 'updated_at']),
            'productValue' => $productValue->fresh()->only(['product_id', 'product_attribute_id', 'attribute_value_id', 'value_text', 'custom_value', 'updated_at']),
        ];

        $this->assertSame(0, Artisan::call('taxonomy:seed-canonical-families', ['--apply' => true]));
        $this->assertSame(0, Artisan::call('supplier-categories:discover-mappings', ['--apply' => true, '--limit' => 50]));

        $this->assertSame($counts, $this->protectedCounts());
        $this->assertEquals($snapshots['product'], $product->fresh()->only(array_keys($snapshots['product'])));
        $this->assertEquals($snapshots['supplierProduct'], $supplierProduct->fresh()->only(array_keys($snapshots['supplierProduct'])));
        $this->assertEquals($snapshots['category'], $category->fresh()->only(array_keys($snapshots['category'])));
        $this->assertEquals($snapshots['assignment'], $assignment->fresh()->only(array_keys($snapshots['assignment'])));
        $this->assertEquals($snapshots['attribute'], $attribute->fresh()->only(array_keys($snapshots['attribute'])));
        $this->assertEquals($snapshots['attributeValue'], $attributeValue->fresh()->only(array_keys($snapshots['attributeValue'])));
        $this->assertEquals($snapshots['productValue'], $productValue->fresh()->only(array_keys($snapshots['productValue'])));

        $this->assertSame(17, CanonicalProductFamily::query()->count());
        $this->assertSame(1, SupplierCategoryMapping::query()->count());
    }

    public function test_admin_resources_are_super_admin_mutable_and_viewer_auditor_read_only(): void
    {
        $family = CanonicalProductFamily::query()->create([
            'code' => 'cables_adapters',
            'name_bg' => 'Кабели и адаптери',
        ]);
        $mapping = SupplierCategoryMapping::query()->create([
            'supplier_category_name' => 'Power & Cable',
            'canonical_product_family_id' => $family->id,
        ]);

        $this->actingAsRole(User::ROLE_SUPER_ADMIN);

        $this->assertTrue(CanonicalProductFamilyResource::canViewAny());
        $this->assertTrue(CanonicalProductFamilyResource::canCreate());
        $this->assertTrue(CanonicalProductFamilyResource::canEdit($family));
        $this->assertTrue(SupplierCategoryMappingResource::canViewAny());
        $this->assertTrue(SupplierCategoryMappingResource::canCreate());
        $this->assertTrue(SupplierCategoryMappingResource::canEdit($mapping));
        $this->assertFalse(SupplierCategoryMappingResource::canDeleteAny());

        $familyColumns = array_keys(Livewire::test(ListCanonicalProductFamilies::class)->instance()->getTable()->getColumns());
        $mappingColumns = array_keys(Livewire::test(ListSupplierCategoryMappings::class)->instance()->getTable()->getColumns());

        $this->assertContains('code', $familyColumns);
        $this->assertContains('name_bg', $familyColumns);
        $this->assertContains('supplier_category_mappings_count', $familyColumns);
        $this->assertContains('supplier_category_name', $mappingColumns);
        $this->assertContains('canonicalProductFamily.code', $mappingColumns);
        $this->assertContains('status', $mappingColumns);
        $this->assertContains('confidence', $mappingColumns);

        $this->actingAsRole(User::ROLE_VIEWER_AUDITOR);

        $this->assertTrue(CanonicalProductFamilyResource::canViewAny());
        $this->assertFalse(CanonicalProductFamilyResource::canCreate());
        $this->assertFalse(CanonicalProductFamilyResource::canEdit($family));
        $this->assertTrue(SupplierCategoryMappingResource::canViewAny());
        $this->assertFalse(SupplierCategoryMappingResource::canCreate());
        $this->assertFalse(SupplierCategoryMappingResource::canEdit($mapping));
    }

    public function test_taxonomy_phase_does_not_expand_sync_or_storefront_mutation_surfaces(): void
    {
        $supplier = Supplier::factory()->create(['company_name' => 'APCOM', 'slug' => 'apcom']);
        $this->supplierProduct($supplier, 'Power & Cable');

        $this->assertSame(0, Artisan::call('taxonomy:seed-canonical-families', ['--apply' => true]));
        $this->assertSame(0, Artisan::call('supplier-categories:audit', ['--limit' => 50]));
        $this->assertSame(0, Artisan::call('supplier-categories:discover-mappings', ['--apply' => true, '--limit' => 50]));

        $this->assertTrue((bool) config('catalog_sync.create_enabled'));
        $this->assertFalse((bool) config('catalog_sync.update_enabled'));
        $this->assertFalse((bool) config('catalog_sync.sync_all_enabled'));
        $this->assertFalse((bool) config('catalog_sync.auto_enabled'));
        $this->get('/cart')->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function commandJson(string $command, array $arguments): array
    {
        $this->assertSame(0, Artisan::call($command, $arguments));

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function supplierProduct(Supplier $supplier, ?string $categoryName): SupplierProduct
    {
        return SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'SUP-'.str()->random(8),
            'name' => 'Supplier staged product',
            'category_name' => $categoryName,
            'currency' => 'EUR',
            'raw_data' => ['category' => $categoryName],
            'payload_hash' => sha1((string) str()->uuid()),
            'received_at' => now(),
            'status' => 'new',
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function protectedCounts(): array
    {
        return [
            'products' => Product::query()->count(),
            'supplier_products' => SupplierProduct::query()->count(),
            'categories' => Category::query()->count(),
            'category_product_attributes' => CategoryProductAttribute::query()->count(),
            'product_attributes' => ProductAttribute::query()->count(),
            'attribute_values' => AttributeValue::query()->count(),
            'product_attribute_values' => ProductAttributeValue::query()->count(),
        ];
    }

    private function actingAsRole(string $role): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create([
            'role' => $role,
            'is_active' => true,
        ]);
        $user->assignRole($role);

        $this->actingAs($user);

        return $user;
    }
}
