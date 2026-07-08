<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CleanupUnsafeSchedulesTest extends TestCase
{
    public function test_command_is_registered(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('suppliers:cleanup-unsafe-schedules', $commands);
        $this->assertTrue($commands['suppliers:cleanup-unsafe-schedules']->getDefinition()->hasOption('supplier'));
        $this->assertTrue($commands['suppliers:cleanup-unsafe-schedules']->getDefinition()->hasOption('limit'));
        $this->assertTrue($commands['suppliers:cleanup-unsafe-schedules']->getDefinition()->hasOption('format'));
        $this->assertTrue($commands['suppliers:cleanup-unsafe-schedules']->getDefinition()->hasOption('apply'));
        $this->assertTrue($commands['suppliers:cleanup-unsafe-schedules']->getDefinition()->hasOption('dry-run'));
        $this->assertTrue($commands['suppliers:cleanup-unsafe-schedules']->getDefinition()->hasOption('only-unsafe'));
        $this->assertTrue($commands['suppliers:cleanup-unsafe-schedules']->getDefinition()->hasOption('disable-schedules-only'));
    }
}
