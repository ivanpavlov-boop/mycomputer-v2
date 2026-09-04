<?php

use Database\Migrations\Support\CanonicalSupplierPhaseThreeP0Schema;
use Database\Migrations\Support\P0MigrationStep;
use Illuminate\Database\Migrations\Migration;

require_once __DIR__.'/support/CanonicalSupplierPhaseThreeP0Schema.php';

return new class extends Migration
{
    public function up(): void
    {
        if (! CanonicalSupplierPhaseThreeP0Schema::isMySql()) {
            return;
        }

        CanonicalSupplierPhaseThreeP0Schema::runForwardStep(P0MigrationStep::P0_03);
    }

    public function down(): void
    {
        if (! CanonicalSupplierPhaseThreeP0Schema::isMySql()) {
            return;
        }

        CanonicalSupplierPhaseThreeP0Schema::runTerminalDowngradeStep(P0MigrationStep::P0_03);
    }
};
