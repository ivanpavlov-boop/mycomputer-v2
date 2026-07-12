<?php

namespace Tests\Unit\Suppliers\Onboarding;

use PHPUnit\Framework\TestCase;

class SupplierReadinessMatrixSafetyTest extends TestCase
{
    public function test_matrix_surface_has_no_write_network_queue_or_image_calls(): void
    {
        $root = dirname(__DIR__, 4);
        $paths = [
            $root.'/app/Services/Suppliers/Onboarding/SupplierReadinessMatrixService.php',
            $root.'/app/Data/Suppliers/Onboarding/SupplierReadinessMatrixRow.php',
            $root.'/app/Data/Suppliers/Onboarding/SupplierReadinessMatrixReport.php',
            $root.'/app/Console/Commands/AuditSupplierOnboardingReadinessMatrix.php',
        ];
        $forbidden = [
            '->save(',
            '->create(',
            '->update(',
            '->delete(',
            '->upsert(',
            'DB::insert(',
            'DB::update(',
            'DB::delete(',
            'DB::statement(',
            'Http::',
            'dispatch(',
            'downloadImage',
            'importImage',
        ];

        foreach ($paths as $path) {
            $contents = (string) file_get_contents($path);

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $contents, $path);
            }
        }
    }
}
