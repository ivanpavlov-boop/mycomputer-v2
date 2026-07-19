<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\RelationManagers\ProductAttributeValuesRelationManager;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductWorkflowUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_filament_creation_strips_workflow_actor_and_supplier_source_spoofing(): void
    {
        $actor = $this->actingAsRole(User::ROLE_SUPER_ADMIN);

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Защитен ръчен продукт',
                'slug' => 'zashtiten-rachen-produkt',
                'sku' => 'WORKFLOW-SPOOF-001',
                'price' => 199,
                'price_source' => Product::PRICE_SOURCE_MANUAL,
                'quantity' => 1,
                'reserved_quantity' => 0,
                'stock_status' => Product::STOCK_STATUS_IN_STOCK,
            ])
            ->set('data.source', Product::SOURCE_SUPPLIER_IMPORT)
            ->set('data.workflow_status', Product::WORKFLOW_PUBLISHED)
            ->set('data.product_status', 'active')
            ->set('data.active', true)
            ->set('data.published_at', now()->toDateTimeString())
            ->set('data.published_by', $actor->id)
            ->set('data.approved_by', $actor->id)
            ->set('data.submitted_by', $actor->id)
            ->set('data.returned_by', $actor->id)
            ->set('data.submitted_at', now()->toDateTimeString())
            ->set('data.approved_at', now()->toDateTimeString())
            ->set('data.returned_at', now()->toDateTimeString())
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->where('sku', 'WORKFLOW-SPOOF-001')->firstOrFail();

        $this->assertSame(Product::SOURCE_MANUAL, $product->source);
        $this->assertSame(Product::WORKFLOW_DRAFT, $product->workflow_status);
        $this->assertSame('draft', $product->product_status);
        $this->assertFalse((bool) $product->active);
        $this->assertNull($product->published_at);
        $this->assertNull($product->published_by);
        $this->assertNull($product->approved_by);
        $this->assertNull($product->submitted_by);
        $this->assertNull($product->returned_by);
        $this->assertNull($product->submitted_at);
        $this->assertNull($product->approved_at);
        $this->assertNull($product->returned_at);
        $this->assertSame($actor->id, $product->created_by);
    }

    public function test_normal_edit_payload_cannot_mutate_workflow_owned_fields_or_source(): void
    {
        $actor = $this->actingAsRole(User::ROLE_SUPER_ADMIN);
        $product = Product::factory()->manualDraft()->create([
            'name' => 'Редактиран защитен продукт',
            'source' => Product::SOURCE_MANUAL,
        ]);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->set('data.source', Product::SOURCE_SUPPLIER_IMPORT)
            ->set('data.workflow_status', Product::WORKFLOW_PUBLISHED)
            ->set('data.product_status', 'active')
            ->set('data.active', true)
            ->set('data.published_at', now()->toDateTimeString())
            ->set('data.published_by', $actor->id)
            ->set('data.approved_by', $actor->id)
            ->set('data.submitted_by', $actor->id)
            ->set('data.returned_by', $actor->id)
            ->set('data.submitted_at', now()->toDateTimeString())
            ->set('data.approved_at', now()->toDateTimeString())
            ->set('data.returned_at', now()->toDateTimeString())
            ->set('data.review_notes', 'Подправена бележка')
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();

        $this->assertSame(Product::SOURCE_MANUAL, $product->source);
        $this->assertSame(Product::WORKFLOW_DRAFT, $product->workflow_status);
        $this->assertSame('draft', $product->product_status);
        $this->assertFalse((bool) $product->active);
        $this->assertNull($product->published_at);
        $this->assertNull($product->published_by);
        $this->assertNull($product->approved_by);
        $this->assertNull($product->submitted_by);
        $this->assertNull($product->returned_by);
        $this->assertNull($product->submitted_at);
        $this->assertNull($product->approved_at);
        $this->assertNull($product->returned_at);
        $this->assertNull($product->review_notes);
    }

    public function test_product_form_uses_read_only_workflow_components_and_shows_metadata(): void
    {
        $manager = $this->actingAsRole(User::ROLE_CATALOG_MANAGER);
        $product = Product::factory()->create([
            'workflow_status' => Product::WORKFLOW_PENDING_REVIEW,
            'product_status' => 'hidden',
            'active' => false,
            'submitted_by' => $manager->id,
            'submitted_at' => now()->subHour(),
            'review_notes' => 'Последна бележка за проверка.',
        ]);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertSchemaComponentDoesNotExist('source')
            ->assertSchemaComponentDoesNotExist('workflow_status')
            ->assertSchemaComponentDoesNotExist('product_status')
            ->assertSchemaComponentDoesNotExist('active')
            ->assertSchemaComponentDoesNotExist('published_at')
            ->assertSchemaComponentExists('source_display')
            ->assertSchemaComponentExists('workflow_status_display')
            ->assertSchemaComponentExists('submitted_by_display')
            ->assertSchemaComponentExists('review_notes_display');

        $this->get(ProductResource::getUrl('edit', ['record' => $product]))
            ->assertOk()
            ->assertSee('За преглед')
            ->assertSee('Изпратен от')
            ->assertSee($manager->name)
            ->assertSee('Последна бележка за преглед')
            ->assertSee('Последна бележка за проверка.');
    }

    public function test_filament_actions_are_state_and_role_aware_with_bulgarian_confirmations(): void
    {
        $this->actingAsRole(User::ROLE_PRODUCT_EDITOR);
        $draft = Product::factory()->manualDraft()->create();

        Livewire::test(EditProduct::class, ['record' => $draft->getRouteKey()])
            ->assertActionVisible('submitForReview')
            ->assertActionHidden('requestChanges')
            ->assertActionHidden('approve')
            ->assertActionHidden('publish')
            ->assertActionHidden('hide')
            ->mountAction('submitForReview')
            ->assertMountedActionModalSee('Продуктът ще остане скрит')
            ->callMountedAction();

        $this->assertSame(Product::WORKFLOW_PENDING_REVIEW, $draft->fresh()->workflow_status);
        $this->assertFalse((bool) $draft->fresh()->active);

        $this->actingAsRole(User::ROLE_CATALOG_MANAGER);
        $pending = $draft->fresh();

        Livewire::test(EditProduct::class, ['record' => $pending->getRouteKey()])
            ->assertActionVisible('requestChanges')
            ->assertActionVisible('approve')
            ->assertActionHidden('publish')
            ->assertActionHidden('hide')
            ->mountAction('approve')
            ->assertMountedActionModalSee('Одобрението не публикува продукта')
            ->callMountedAction();

        $approved = $draft->fresh();
        $this->assertSame(Product::WORKFLOW_APPROVED, $approved->workflow_status);
        $this->assertFalse((bool) $approved->active);

        Livewire::test(EditProduct::class, ['record' => $approved->getRouteKey()])
            ->assertActionVisible('publish')
            ->mountAction('publish')
            ->assertMountedActionModalSee('продуктът ще стане публично видим')
            ->callMountedAction();

        $published = $draft->fresh();
        $this->assertSame(Product::WORKFLOW_PUBLISHED, $published->workflow_status);
        $this->assertTrue((bool) $published->active);

        Livewire::test(EditProduct::class, ['record' => $published->getRouteKey()])
            ->assertActionVisible('hide')
            ->mountAction('hide')
            ->assertMountedActionModalSee('Историята на публикуването ще се запази')
            ->callMountedAction();

        $this->assertSame(Product::WORKFLOW_APPROVED, $draft->fresh()->workflow_status);
        $this->assertFalse((bool) $draft->fresh()->active);
        $this->assertNotNull($draft->fresh()->published_at);
    }

    public function test_crafted_livewire_action_call_cannot_bypass_hidden_action_authorization(): void
    {
        $this->actingAsRole(User::ROLE_PRICING_MANAGER);
        $product = Product::factory()->manualDraft()->create();

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertActionHidden('submitForReview')
            ->call('mountAction', 'submitForReview')
            ->call('callMountedAction');

        $product->refresh();

        $this->assertSame(Product::WORKFLOW_DRAFT, $product->workflow_status);
        $this->assertFalse((bool) $product->active);
        $this->assertNull($product->submitted_by);
        $this->assertNull($product->submitted_at);
    }

    public function test_product_edit_payload_is_limited_to_each_roles_authorized_field_domain(): void
    {
        $product = Product::factory()->manualDraft()->create([
            'name' => 'Original product name',
            'price' => 100,
            'quantity' => 5,
            'meta_title' => 'Original SEO title',
        ]);

        $this->actingAsRole(User::ROLE_PRICING_MANAGER);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormFieldDisabled('name')
            ->assertFormFieldEnabled('price')
            ->assertFormFieldDisabled('quantity')
            ->set('data.name', 'Pricing manager content change')
            ->set('data.price', 125)
            ->set('data.quantity', 99)
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();
        $this->assertSame('Original product name', $product->name);
        $this->assertSame('125.00', $product->price);
        $this->assertSame(5, $product->quantity);

        $this->actingAsRole(User::ROLE_INVENTORY_MANAGER);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormFieldDisabled('name')
            ->assertFormFieldDisabled('price')
            ->assertFormFieldEnabled('quantity')
            ->set('data.name', 'Inventory manager content change')
            ->set('data.price', 150)
            ->set('data.quantity', 8)
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();
        $this->assertSame('Original product name', $product->name);
        $this->assertSame('125.00', $product->price);
        $this->assertSame(8, $product->quantity);

        $this->actingAsRole(User::ROLE_PRODUCT_DATA_ENTRY);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormFieldEnabled('name')
            ->assertFormFieldDisabled('price')
            ->assertFormFieldDisabled('quantity')
            ->assertFormFieldDisabled('meta_title')
            ->set('data.name', 'Authorized content change')
            ->set('data.price', 175)
            ->set('data.quantity', 11)
            ->set('data.meta_title', 'Unauthorized SEO change')
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();
        $this->assertSame('Authorized content change', $product->name);
        $this->assertSame('125.00', $product->price);
        $this->assertSame(8, $product->quantity);
        $this->assertSame('Original SEO title', $product->meta_title);
    }

    public function test_only_content_roles_can_create_manual_products(): void
    {
        $this->actingAsRole(User::ROLE_PRICING_MANAGER);
        $this->assertFalse(ProductResource::canCreate());

        $this->actingAsRole(User::ROLE_INVENTORY_MANAGER);
        $this->assertFalse(ProductResource::canCreate());

        $this->actingAsRole(User::ROLE_PRODUCT_DATA_ENTRY);
        $this->assertTrue(ProductResource::canCreate());

        $this->actingAsRole(User::ROLE_SUPER_ADMIN);
        $this->assertTrue(ProductResource::canCreate());
    }

    public function test_create_and_secondary_product_actions_honor_field_domain_permissions(): void
    {
        $product = Product::factory()->manualDraft()->create();

        $this->actingAsRole(User::ROLE_PRICING_MANAGER);
        Livewire::test(ListProducts::class)->assertTableBulkActionHidden('assignAvailability');
        $this->assertFalse(ProductAttributeValuesRelationManager::canViewForRecord($product, EditProduct::class));

        $this->actingAsRole(User::ROLE_INVENTORY_MANAGER);
        Livewire::test(ListProducts::class)->assertTableBulkActionVisible('assignAvailability');
        $this->assertFalse(ProductAttributeValuesRelationManager::canViewForRecord($product, EditProduct::class));

        $this->actingAsRole(User::ROLE_PRODUCT_DATA_ENTRY);
        Livewire::test(ListProducts::class)->assertTableBulkActionHidden('assignAvailability');
        $this->assertTrue(ProductAttributeValuesRelationManager::canViewForRecord($product, EditProduct::class));

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Content-only product',
                'slug' => 'content-only-product',
                'sku' => 'CONTENT-ONLY-001',
            ])
            ->set('data.price', 999)
            ->set('data.quantity', 10)
            ->set('data.stock_status', Product::STOCK_STATUS_IN_STOCK)
            ->call('create')
            ->assertHasNoFormErrors();

        $created = Product::query()->where('sku', 'CONTENT-ONLY-001')->firstOrFail();

        $this->assertSame('0.00', $created->price);
        $this->assertSame(0, $created->quantity);
        $this->assertSame(Product::STOCK_STATUS_OUT_OF_STOCK, $created->stock_status);
        $this->assertSame(Product::WORKFLOW_DRAFT, $created->workflow_status);
        $this->assertFalse((bool) $created->active);
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
