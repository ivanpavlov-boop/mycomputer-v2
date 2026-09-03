<?php

namespace Tests\Unit\Suppliers\SourceProfiles;

use App\Models\SupplierImportSourceProfile;
use Illuminate\Database\Eloquent\MassAssignmentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class SupplierImportSourceProfileModelTest extends TestCase
{
    public function test_model_is_guarded_hidden_and_immutable(): void
    {
        $model = new SupplierImportSourceProfile;

        try {
            $model->fill(['source_identity' => 'forbidden']);
            $this->fail('Mass assignment was accepted.');
        } catch (MassAssignmentException) {
            $this->assertTrue(true);
        }

        $model->setRawAttributes([
            'id' => 1,
            'source_identity' => 'snapshot-source-v1:profile:00112233445566778899aabbccddeeff',
            'source_locator_canonical_bytes' => 'locator-secret-free-bytes',
            'mapping_canonical_bytes' => 'mapping-bytes',
            'mapping_contract_fingerprint' => str_repeat('a', 64),
            'source_descriptor_fingerprint' => str_repeat('b', 64),
        ], true);
        $model->exists = true;

        $array = $model->toArray();
        $this->assertArrayNotHasKey('source_identity', $array);
        $this->assertArrayNotHasKey('source_locator_canonical_bytes', $array);
        $this->assertArrayNotHasKey('mapping_canonical_bytes', $array);
        $this->assertArrayNotHasKey('mapping_contract_fingerprint', $array);
        $this->assertArrayNotHasKey('source_descriptor_fingerprint', $array);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Immutable canonical supplier records cannot be mutated.');
        $model->update(['source_identity' => 'different']);
    }
}
