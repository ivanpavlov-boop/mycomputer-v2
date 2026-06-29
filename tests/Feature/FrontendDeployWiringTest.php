<?php

namespace Tests\Feature;

use Tests\TestCase;

class FrontendDeployWiringTest extends TestCase
{
    public function test_docker_compose_defines_a_nuxt_frontend_service(): void
    {
        $compose = file_get_contents(base_path('docker-compose.yml'));

        $this->assertStringContainsString('frontend:', $compose);
        $this->assertStringContainsString('context: ./frontend', $compose);
        $this->assertStringContainsString('NITRO_HOST: 0.0.0.0', $compose);
        $this->assertStringContainsString('NITRO_PORT: 3000', $compose);
        $this->assertStringContainsString('NUXT_PUBLIC_API_BASE_URL: ${NUXT_PUBLIC_API_BASE_URL:-/api/v1}', $compose);
        $this->assertStringContainsString('NUXT_API_SERVER_BASE_URL: ${NUXT_API_SERVER_BASE_URL:-http://nginx/api/v1}', $compose);
        $this->assertStringContainsString('frontend:', $compose);
        $this->assertStringContainsString('condition: service_started', $compose);
    }

    public function test_nginx_routes_only_safe_storefront_paths_to_nuxt(): void
    {
        $config = file_get_contents(base_path('deploy/nginx/mycomputer.conf'));

        foreach ([
            'location = /',
            'location = /catalog',
            'location = /categories',
            'location ^~ /c/',
            'location ^~ /p/',
            'location ^~ /_nuxt/',
            'location ^~ /_ipx/',
        ] as $location) {
            $this->assertStringContainsString($location, $config);
        }

        $this->assertStringContainsString('proxy_pass http://frontend:3000;', $config);
    }

    public function test_nginx_keeps_laravel_admin_api_livewire_and_storage_paths_on_laravel(): void
    {
        $config = file_get_contents(base_path('deploy/nginx/mycomputer.conf'));

        foreach ([
            'location ^~ /admin',
            'location ^~ /api/',
            'location ^~ /livewire/',
            'location ^~ /vendor/',
            'location ^~ /build/',
            'location /storage/',
        ] as $location) {
            $this->assertStringContainsString($location, $config);
        }

        $this->assertStringContainsString('fastcgi_pass app:9000;', $config);
    }

    public function test_nginx_does_not_expose_disabled_customer_flows_to_nuxt(): void
    {
        $config = file_get_contents(base_path('deploy/nginx/mycomputer.conf'));

        foreach ([
            'location = /cart { return 404; }',
            'location = /checkout { return 404; }',
            'location = /account { return 404; }',
            'location = /login { return 404; }',
            'location = /register { return 404; }',
            'location = /wishlist { return 404; }',
            'location = /compare { return 404; }',
        ] as $blockedRoute) {
            $this->assertStringContainsString($blockedRoute, $config);
        }
    }

    public function test_frontend_container_builds_nuxt_for_node_runtime(): void
    {
        $dockerfile = file_get_contents(base_path('frontend/Dockerfile'));

        $this->assertStringContainsString('FROM node:22-alpine AS build', $dockerfile);
        $this->assertStringContainsString('RUN npm ci', $dockerfile);
        $this->assertStringContainsString('RUN npm run build', $dockerfile);
        $this->assertStringContainsString('npm prune --omit=dev', $dockerfile);
        $this->assertStringContainsString('FROM node:22-alpine AS runtime', $dockerfile);
        $this->assertStringContainsString('COPY --from=build /app/node_modules ./node_modules', $dockerfile);
        $this->assertStringContainsString('CMD ["node", ".output/server/index.mjs"]', $dockerfile);
    }

    public function test_nuxt_ssr_uses_private_api_base_url_without_leaking_it_to_the_browser(): void
    {
        $nuxtConfig = file_get_contents(base_path('frontend/nuxt.config.ts'));
        $useApi = file_get_contents(base_path('frontend/app/composables/useApi.ts'));

        $this->assertStringContainsString('apiServerBaseUrl', $nuxtConfig);
        $this->assertStringContainsString('NUXT_API_SERVER_BASE_URL', $nuxtConfig);
        $this->assertStringContainsString('import.meta.server', $useApi);
        $this->assertStringContainsString('config.apiServerBaseUrl', $useApi);
        $this->assertStringContainsString('config.public.apiBaseUrl', $useApi);
    }
}
