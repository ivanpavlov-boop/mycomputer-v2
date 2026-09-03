<?php

namespace Tests\Unit\Suppliers\SourceProfiles;

use App\Exceptions\SupplierImportSourceProfileIdentityCollisionExhaustedException;
use App\Exceptions\SupplierImportSourceProfilePersistenceException;
use App\Services\Suppliers\SourceProfiles\SupplierSourceIdentityCollisionClassifier;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SupplierSourceIdentityCollisionClassifierTest extends TestCase
{
    private const VALID_DIAGNOSTIC = "Duplicate entry 'opaque' for key 'supplier_import_source_profiles.uq_import_source_profile_identity'";

    public function test_only_the_exact_mysql_84_identity_collision_shape_is_eligible(): void
    {
        $classifier = new SupplierSourceIdentityCollisionClassifier;

        $this->assertTrue($classifier->isEligible(
            $this->queryException(['23000', 1062, self::VALID_DIAGNOSTIC]),
        ));
    }

    /** @param array<int, mixed>|null $errorInfo */
    #[DataProvider('ineligibleErrorInfo')]
    public function test_every_loose_or_malformed_collision_shape_is_ineligible(?array $errorInfo): void
    {
        $this->assertFalse((new SupplierSourceIdentityCollisionClassifier)->isEligible(
            $this->queryException($errorInfo),
        ));
    }

    /** @return iterable<string, array{0: array<int, mixed>|null}> */
    public static function ineligibleErrorInfo(): iterable
    {
        yield 'missing info' => [null];
        yield 'string errno' => [['23000', '1062', self::VALID_DIAGNOSTIC]];
        yield 'wrong SQLSTATE' => [['HY000', 1062, self::VALID_DIAGNOSTIC]];
        yield 'bare key' => [['23000', 1062, "Duplicate entry 'opaque' for key 'uq_import_source_profile_identity'"]];
        yield 'database qualified' => [['23000', 1062, "Duplicate entry 'opaque' for key 'database.supplier_import_source_profiles.uq_import_source_profile_identity'"]];
        yield 'other key' => [['23000', 1062, "Duplicate entry 'opaque' for key 'supplier_import_source_profiles.uq_import_source_profile_descriptor'"]];
        yield 'empty payload' => [['23000', 1062, "Duplicate entry '' for key 'supplier_import_source_profiles.uq_import_source_profile_identity'"]];
        yield 'trailing text' => [['23000', 1062, self::VALID_DIAGNOSTIC.' trailing']];
        yield 'leading text' => [['23000', 1062, 'leading '.self::VALID_DIAGNOSTIC]];
        yield 'non-string diagnostic' => [['23000', 1062, 123]];
    }

    public function test_sanitized_errors_retain_no_original_exception_or_diagnostic(): void
    {
        $persistence = SupplierImportSourceProfilePersistenceException::fromErrorInfo([
            '23000',
            1062,
            self::VALID_DIAGNOSTIC,
        ]);
        $exhausted = new SupplierImportSourceProfileIdentityCollisionExhaustedException;

        $this->assertSame([
            'code' => 'source_profile_persistence_failed',
            'sqlstate' => '23000',
            'driver_code' => 1062,
            'operation' => 'INSERT_SUPPLIER_IMPORT_SOURCE_PROFILE',
        ], $persistence->metadata());
        $this->assertNull($persistence->getPrevious());
        $this->assertStringNotContainsString('opaque', serialize($persistence->metadata()));
        $this->assertSame([
            'code' => 'source_profile_identity_collision_exhausted',
            'operation' => 'INSERT_SUPPLIER_IMPORT_SOURCE_PROFILE',
            'attempt' => 4,
            'maximum' => 4,
            'constraint' => 'uq_import_source_profile_identity',
        ], $exhausted->metadata());
        $this->assertNull($exhausted->getPrevious());
    }

    /** @param array<int, mixed>|null $errorInfo */
    private function queryException(?array $errorInfo): QueryException
    {
        $previous = new PDOException('sensitive diagnostic');
        $previous->errorInfo = $errorInfo;

        return new QueryException('mysql', 'sensitive sql', [], $previous);
    }
}
