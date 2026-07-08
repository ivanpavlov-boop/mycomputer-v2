<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AuditImportCapabilitiesTest extends TestCase
{
    public function test_command_is_registered_without_apply_option(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('suppliers:audit-import-capabilities', $commands);
        $this->assertFalse($commands['suppliers:audit-import-capabilities']->getDefinition()->hasOption('apply'));
        $this->assertTrue($commands['suppliers:audit-import-capabilities']->getDefinition()->hasOption('supplier'));
        $this->assertTrue($commands['suppliers:audit-import-capabilities']->getDefinition()->hasOption('limit'));
        $this->assertTrue($commands['suppliers:audit-import-capabilities']->getDefinition()->hasOption('format'));
        $this->assertTrue($commands['suppliers:audit-import-capabilities']->getDefinition()->hasOption('include-disabled'));
        $this->assertTrue($commands['suppliers:audit-import-capabilities']->getDefinition()->hasOption('only-with-issues'));
        $this->assertTrue($commands['suppliers:audit-import-capabilities']->getDefinition()->hasOption('show-drivers'));
        $this->assertTrue($commands['suppliers:audit-import-capabilities']->getDefinition()->hasOption('show-schedules'));
        $this->assertTrue($commands['suppliers:audit-import-capabilities']->getDefinition()->hasOption('show-config'));
        $this->assertTrue($commands['suppliers:audit-import-capabilities']->getDefinition()->hasOption('show-checklist'));
    }
}
