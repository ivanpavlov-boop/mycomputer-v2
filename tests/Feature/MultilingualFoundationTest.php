<?php

namespace Tests\Feature;

use App\Filament\Pages\CatalogSyncPreview;
use App\Filament\Resources\Products\ProductResource;
use App\Models\AttributeGroup;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Products\ProductSyncService;
use App\Support\Localization\Locales;
use App\Support\Seo\HreflangLinks;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MultilingualFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_supported_locales_are_bg_primary_and_en_secondary(): void
    {
        $this->assertSame('bg', config('app.locale'));
        $this->assertSame('bg', config('app.fallback_locale'));
        $this->assertSame('bg', config('locales.default'));
        $this->assertSame('bg', config('locales.fallback'));
        $this->assertSame(['bg', 'en'], Locales::codes());
        $this->assertSame('Български', config('locales.supported.bg.label'));
        $this->assertSame('English', config('locales.supported.en.label'));
    }

    public function test_default_and_english_storefront_entrypoints_render_with_expected_locales(): void
    {
        $this
            ->get('/')
            ->assertOk()
            ->assertHeader('Content-Language', 'bg')
            ->assertSee('<html lang="bg"', false);

        $this
            ->get('/en')
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertSee('<html lang="en"', false);
    }

    public function test_admin_password_reset_and_catalog_sync_routes_are_not_affected_by_en_entrypoint(): void
    {
        $this->assertTrue(Route::has('storefront.en'));
        $this->assertTrue(Route::has('filament.admin.auth.password-reset.request'));
        $this->assertTrue(Route::has('filament.admin.auth.password-reset.reset'));

        $this
            ->get(route('filament.admin.auth.password-reset.request'))
            ->assertOk();

        $this->actingAsSuperAdmin();

        $this
            ->get(CatalogSyncPreview::getUrl())
            ->assertOk();

        $this->assertFalse((bool) config('catalog_sync.sync_all_enabled'));
        $this->assertFalse((bool) config('catalog_sync.auto_enabled'));
    }

    public function test_existing_product_and_category_content_remains_bg_fallback(): void
    {
        $category = Category::factory()->create([
            'name' => 'Лаптопи',
            'slug' => 'laptopi',
            'description' => 'Българско описание',
        ]);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Лаптоп Lenovo',
            'slug' => 'laptop-lenovo',
            'short_description' => 'Кратко описание',
            'description' => 'Пълно описание',
        ]);

        $this->assertSame('Лаптоп Lenovo', $product->localizedField('name', 'bg'));
        $this->assertSame('laptop-lenovo', $product->localizedField('slug', 'bg'));
        $this->assertSame('Лаптоп Lenovo', $product->localizedField('name', 'en'));
        $this->assertNull($product->localizedField('name', 'en', fallbackToPrimary: false));
        $this->assertSame('Лаптопи', $category->localizedField('name', 'bg'));
    }

    public function test_english_fields_can_be_stored_without_requiring_complete_translation(): void
    {
        $product = Product::factory()->create([
            'name' => 'Лаптоп Lenovo',
            'slug' => 'laptop-lenovo',
            'name_translations' => ['en' => 'Lenovo Laptop'],
            'slug_translations' => ['en' => 'lenovo-laptop'],
            'short_description_translations' => ['en' => 'Fast office laptop.'],
        ]);

        $this->assertSame('Lenovo Laptop', $product->localizedField('name', 'en', fallbackToPrimary: false));
        $this->assertSame('lenovo-laptop', $product->localizedField('slug', 'en', fallbackToPrimary: false));
        $this->assertNull($product->localizedField('description', 'en', fallbackToPrimary: false));
    }

    public function test_api_keeps_legacy_fields_and_adds_locale_payload(): void
    {
        $product = Product::factory()->create([
            'name' => 'Лаптоп Lenovo',
            'slug' => 'laptop-lenovo',
            'name_translations' => ['en' => 'Lenovo Laptop'],
            'slug_translations' => ['en' => 'lenovo-laptop'],
            'short_description_translations' => ['en' => 'Fast office laptop.'],
            'active' => true,
            'published_at' => now(),
        ]);

        $this
            ->getJson('/api/v1/products/'.$product->slug.'?locale=en')
            ->assertOk()
            ->assertJsonPath('data.name', 'Лаптоп Lenovo')
            ->assertJsonPath('data.slug', 'laptop-lenovo')
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.localized.name', 'Lenovo Laptop')
            ->assertJsonPath('data.localized.slug', 'lenovo-laptop')
            ->assertJsonPath('data.localized.short_description', 'Fast office laptop.');
    }

    public function test_admin_product_form_renders_optional_english_localization_fields(): void
    {
        $this->actingAsProductManager();

        $this
            ->get(ProductResource::getUrl('create'))
            ->assertOk()
            ->assertSee('English localization')
            ->assertSee('English product name')
            ->assertSee('English slug')
            ->assertSee('English SEO title');
    }

    public function test_attribute_labels_support_optional_translations(): void
    {
        $group = AttributeGroup::query()->create([
            'name' => 'Памет',
            'slug' => 'pamet',
            'name_translations' => ['en' => 'Memory'],
            'is_active' => true,
        ]);

        $this->assertSame('Памет', $group->localizedField('name', 'bg'));
        $this->assertSame('Memory', $group->localizedField('name', 'en', fallbackToPrimary: false));
    }

    public function test_hreflang_helper_prepares_bg_en_and_x_default_links(): void
    {
        $links = HreflangLinks::forPath('/p/laptop-lenovo', [
            'bg' => '/p/laptop-lenovo',
            'en' => '/p/lenovo-laptop',
        ]);

        $this->assertSame('bg', $links[0]['hreflang']);
        $this->assertStringEndsWith('/p/laptop-lenovo', $links[0]['url']);
        $this->assertSame('en', $links[1]['hreflang']);
        $this->assertStringEndsWith('/en/p/lenovo-laptop', $links[1]['url']);
        $this->assertSame('x-default', $links[2]['hreflang']);
        $this->assertStringEndsWith('/p/laptop-lenovo', $links[2]['url']);
    }

    public function test_catalog_sync_commercial_updates_do_not_touch_localized_manual_content(): void
    {
        $product = Product::factory()->create([
            'name_translations' => ['en' => 'Curated English Name'],
            'description_translations' => ['en' => 'Curated English Description'],
            'meta_title_translations' => ['en' => 'Curated English SEO'],
            'quantity' => 1,
            'price' => 100,
        ]);

        $product->update([
            'quantity' => 3,
            'price' => 120,
        ]);

        $product->refresh();

        $this->assertSame('Curated English Name', $product->name_translations['en']);
        $this->assertSame('Curated English Description', $product->description_translations['en']);
        $this->assertSame('Curated English SEO', $product->meta_title_translations['en']);
    }

    public function test_existing_content_lock_filter_does_not_expose_localized_fields_to_supplier_updates(): void
    {
        $product = Product::factory()->create([
            'lock_name' => true,
            'lock_seo' => true,
            'lock_descriptions' => true,
        ]);
        $updates = [
            'name' => 'Supplier Name',
            'meta_title' => 'Supplier SEO',
            'meta_description' => 'Supplier Meta',
            'description' => 'Supplier Description',
            'short_description' => 'Supplier Short',
            'quantity' => 5,
        ];

        $filtered = app(ProductSyncService::class)->filterLockedContentUpdates($product, $updates);

        $this->assertSame(['quantity' => 5], $filtered);
        $this->assertArrayNotHasKey('name_translations', $filtered);
        $this->assertArrayNotHasKey('description_translations', $filtered);
        $this->assertArrayNotHasKey('meta_title_translations', $filtered);
    }

    private function actingAsProductManager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('manager');

        $this->actingAs($user);

        return $user;
    }

    private function actingAsSuperAdmin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);
        $user->assignRole(User::ROLE_SUPER_ADMIN);

        $this->actingAs($user);

        return $user;
    }
}
