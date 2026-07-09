<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PreviewStagingImportTest extends TestCase
{
    public function test_command_is_registered_without_apply_option(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('suppliers:preview-staging-import', $commands);
        $this->assertFalse($commands['suppliers:preview-staging-import']->getDefinition()->hasOption('apply'));
    }
}
