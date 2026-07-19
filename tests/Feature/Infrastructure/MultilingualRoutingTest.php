<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MultilingualRoutingTest extends TestCase
{
    public function test_explicit_english_storefront_routes_reuse_the_existing_frontend_upstream(): void
    {
        $config = $this->nginxConfig();

        $this->assertStringContainsString('proxy_pass http://frontend:3000;', $config);

        foreach ([
            '= /',
            '= /catalog',
            '= /categories',
            '^~ /c/',
            '^~ /p/',
            '= /en',
            '= /en/catalog',
            '= /en/categories',
            '^~ /en/c/',
            '^~ /en/p/',
        ] as $location) {
            $this->assertLocationUsesFrontend($config, $location);
        }
    }

    public function test_english_root_trailing_slash_redirects_relatively_and_preserves_query_strings(): void
    {
        $config = $this->nginxConfig();

        $this->assertStringContainsString(
            "location = /en/ {\n        return 308 /en\$is_args\$args;\n    }",
            $config,
        );
    }

    public function test_english_routing_has_no_broad_or_protected_nuxt_proxy(): void
    {
        $config = $this->nginxConfig();

        $this->assertDoesNotMatchRegularExpression('/location\\s+\\^~\\s+\/en\/\\s*\\{/', $config);

        foreach (['/en/admin', '/en/api', '/en/cart', '/en/checkout', '/en/account'] as $path) {
            $this->assertDoesNotMatchRegularExpression(
                '/location\\s+(?:=\\s+|\\^~\\s+)?'.preg_quote($path, '/').'/',
                $config,
            );
        }

        $this->assertDoesNotMatchRegularExpression('/computer2u\\.eu|mycomputer\\.bg/', $config);
    }

    public function test_laravel_does_not_claim_the_english_storefront_root_or_localized_protected_routes(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        $this->assertFalse(Route::has('storefront.en'));
        $this->assertSame(0, $routes->filter(fn (LaravelRoute $route): bool => $route->uri() === 'en')->count());
        $this->assertSame(0, $routes->filter(fn (LaravelRoute $route): bool => str_starts_with($route->uri(), 'en/admin'))->count());
        $this->assertSame(0, $routes->filter(fn (LaravelRoute $route): bool => str_starts_with($route->uri(), 'en/api'))->count());
        $this->assertTrue(Route::has('filament.admin.auth.password-reset.request'));
        $this->assertTrue(Route::has('filament.admin.auth.password-reset.reset'));
    }

    private function assertLocationUsesFrontend(string $config, string $location): void
    {
        $pattern = '/location\\s+'.preg_quote($location, '/').'\\s*\\{\\s*try_files\\s+\\$uri\\s+@frontend;\\s*\\}/s';

        $this->assertMatchesRegularExpression($pattern, $config, "{$location} must use the named frontend upstream.");
    }

    private function nginxConfig(): string
    {
        return (string) file_get_contents(base_path('deploy/nginx/mycomputer.conf'));
    }
}
