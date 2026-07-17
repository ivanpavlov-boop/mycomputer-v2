<?php

namespace Tests\Feature\Infrastructure;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class DockerComposeRestartPolicyTest extends TestCase
{
    /**
     * @var array<int, string>
     */
    private const REQUIRED_SERVICES = [
        'app',
        'nginx',
        'frontend',
        'queue',
        'scheduler',
        'mysql',
        'redis',
        'meilisearch',
    ];

    public function test_permanent_services_use_unless_stopped_restart_policy(): void
    {
        $services = $this->services();

        $this->assertSame(self::REQUIRED_SERVICES, array_keys($services));

        foreach (self::REQUIRED_SERVICES as $service) {
            $this->assertArrayHasKey('restart', $services[$service], "{$service} must declare a restart policy.");
            $this->assertSame('unless-stopped', $services[$service]['restart'], "{$service} must restart unless intentionally stopped.");
            $this->assertNotContains($services[$service]['restart'], ['always', 'on-failure', 'no']);
        }
    }

    public function test_critical_healthchecks_and_dependencies_remain_declared(): void
    {
        $services = $this->services();

        foreach (['app', 'mysql', 'redis', 'meilisearch'] as $service) {
            $this->assertArrayHasKey('healthcheck', $services[$service]);
        }

        $this->assertServiceDependsOn($services, 'app', ['mysql', 'redis', 'meilisearch']);
        $this->assertServiceDependsOn($services, 'queue', ['mysql', 'redis', 'meilisearch']);
        $this->assertServiceDependsOn($services, 'scheduler', ['mysql', 'redis']);

        $this->assertSame('service_healthy', $services['nginx']['depends_on']['app']['condition']);
        $this->assertSame('service_started', $services['nginx']['depends_on']['frontend']['condition']);

        foreach ($services as $service) {
            $this->assertArrayNotHasKey('container_name', $service);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $services
     * @param  array<int, string>  $dependencies
     */
    private function assertServiceDependsOn(array $services, string $service, array $dependencies): void
    {
        $this->assertArrayHasKey('depends_on', $services[$service]);

        foreach ($dependencies as $dependency) {
            $this->assertSame(
                'service_healthy',
                $services[$service]['depends_on'][$dependency]['condition'] ?? null,
                "{$service} must wait for healthy {$dependency}.",
            );
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function services(): array
    {
        $compose = Yaml::parseFile(base_path('docker-compose.yml'));

        $this->assertIsArray($compose);
        $this->assertArrayHasKey('services', $compose);
        $this->assertIsArray($compose['services']);

        return $compose['services'];
    }
}
