<?php

namespace Tests\Feature\Localization;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApiLocaleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_defaults_to_bulgarian_and_sets_the_content_language_header(): void
    {
        $this
            ->withHeader('Accept-Language', '')
            ->getJson('/api/v1/health')
            ->assertOk()
            ->assertHeader('Content-Language', 'bg');

        $this->assertSame('bg', app()->getLocale());
    }

    public function test_explicit_supported_locale_header_takes_precedence(): void
    {
        $this
            ->withHeader('X-Locale', 'bg')
            ->withHeader('Accept-Language', 'en-GB,en;q=0.9')
            ->getJson('/api/v1/health')
            ->assertOk()
            ->assertHeader('Content-Language', 'bg');

        $this
            ->withHeader('X-Locale', 'en')
            ->getJson('/api/v1/health')
            ->assertOk()
            ->assertHeader('Content-Language', 'en');

        $this->assertSame('en', app()->getLocale());
    }

    public function test_accept_language_uses_supported_primary_language_tags(): void
    {
        $this
            ->withHeader('Accept-Language', 'en-GB,en;q=0.9,bg;q=0.8')
            ->getJson('/api/v1/health')
            ->assertOk()
            ->assertHeader('Content-Language', 'en');

        $this
            ->withHeader('Accept-Language', 'bg-BG,bg;q=0.9,en;q=0.8')
            ->getJson('/api/v1/health')
            ->assertOk()
            ->assertHeader('Content-Language', 'bg');

        $this
            ->withHeader('Accept-Language', 'fr-FR,fr;q=0.9')
            ->getJson('/api/v1/health')
            ->assertOk()
            ->assertHeader('Content-Language', 'bg');
    }

    public function test_unsupported_or_malformed_explicit_locales_safely_fall_back_to_bulgarian(): void
    {
        foreach (['de', 'fr', '../../en', 'en_US<script>'] as $locale) {
            $this
                ->withHeader('X-Locale', $locale)
                ->getJson('/api/v1/health')
                ->assertOk()
                ->assertHeader('Content-Language', 'bg');
        }
    }

    public function test_legacy_validated_query_locale_remains_a_low_priority_api_fallback(): void
    {
        $this
            ->getJson('/api/v1/health?locale=en')
            ->assertOk()
            ->assertHeader('Content-Language', 'en');

        $this
            ->withHeader('Accept-Language', 'bg-BG,bg;q=0.9')
            ->getJson('/api/v1/health?locale=en')
            ->assertOk()
            ->assertHeader('Content-Language', 'bg');
    }

    public function test_api_locale_resolution_does_not_mutate_catalog_or_supplier_records(): void
    {
        $before = [
            'products' => Product::query()->count(),
            'supplier_products' => DB::table('supplier_products')->count(),
            'product_supplier_offers' => DB::table('product_supplier_offers')->count(),
        ];

        $this
            ->withHeader('X-Locale', 'en')
            ->getJson('/api/v1/products')
            ->assertOk()
            ->assertHeader('Content-Language', 'en');

        $this->assertSame($before['products'], Product::query()->count());
        $this->assertSame($before['supplier_products'], DB::table('supplier_products')->count());
        $this->assertSame($before['product_supplier_offers'], DB::table('product_supplier_offers')->count());
    }

    public function test_api_locale_resolution_does_not_localize_filament_or_admin_routes(): void
    {
        app()->setLocale('bg');

        $this
            ->get(route('filament.admin.auth.password-reset.request'))
            ->assertOk();

        $this->get('/en/admin')->assertNotFound();
        $this->assertSame('bg', app()->getLocale());
    }
}
