<?php

namespace App\Repositories\Suppliers;

use App\Contracts\Suppliers\SupplierSourceIdentityGenerator;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierSourceProfileDescriptor;
use App\Data\Suppliers\SourceProfiles\SupplierSourceProfileIdentity;
use App\Exceptions\SupplierImportSourceProfileDescriptorCollisionException;
use App\Exceptions\SupplierImportSourceProfileIdentityCollisionExhaustedException;
use App\Exceptions\SupplierImportSourceProfileOwnerNotFoundException;
use App\Exceptions\SupplierImportSourceProfilePersistenceException;
use App\Models\SupplierImportSourceProfile;
use App\Services\Suppliers\SourceProfiles\SupplierSourceIdentityCollisionClassifier;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Throwable;

final readonly class SupplierImportSourceProfileRepository
{
    private const MAXIMUM_IDENTITY_ATTEMPTS = 4;

    public function __construct(
        private DatabaseManager $database,
        private SupplierSourceIdentityGenerator $identityGenerator,
        private SupplierSourceIdentityCollisionClassifier $collisionClassifier,
    ) {}

    public function resolveOrCreate(
        CanonicalSupplierSourceProfileDescriptor $descriptor,
    ): SupplierImportSourceProfile {
        $connection = $this->database->connection();

        try {
            return $connection->transaction(function () use ($connection, $descriptor): SupplierImportSourceProfile {
                $attributes = $descriptor->persistenceAttributes();
                $owner = $connection->table('supplier_feeds')
                    ->where('id', $attributes['supplier_feed_id'])
                    ->where('supplier_id', $attributes['supplier_id'])
                    ->lockForUpdate()
                    ->first(['id', 'supplier_id']);

                if ($owner === null) {
                    throw new SupplierImportSourceProfileOwnerNotFoundException;
                }

                $existing = $connection->table('supplier_import_source_profiles')
                    ->where('supplier_id', $attributes['supplier_id'])
                    ->where('supplier_feed_id', $attributes['supplier_feed_id'])
                    ->where('source_descriptor_fingerprint', $attributes['source_descriptor_fingerprint'])
                    ->first();

                if ($existing !== null) {
                    $this->assertByteIdentical($existing, $attributes);

                    return $this->modelFromRow($existing, $connection->getName());
                }

                for ($attempt = 1; $attempt <= self::MAXIMUM_IDENTITY_ATTEMPTS; $attempt++) {
                    $identity = SupplierSourceProfileIdentity::fromRandomBytes(
                        $this->identityGenerator->bytes(),
                    );

                    try {
                        $id = $connection->table('supplier_import_source_profiles')->insertGetId([
                            ...$attributes,
                            'source_identity' => $identity->value(),
                            'created_at' => Carbon::now('UTC')->format('Y-m-d H:i:s.u'),
                        ]);
                    } catch (QueryException $exception) {
                        if (! $this->collisionClassifier->isEligible($exception)) {
                            throw SupplierImportSourceProfilePersistenceException::fromErrorInfo(
                                $exception->errorInfo,
                            );
                        }

                        if ($attempt === self::MAXIMUM_IDENTITY_ATTEMPTS) {
                            throw new SupplierImportSourceProfileIdentityCollisionExhaustedException;
                        }

                        continue;
                    }

                    $created = $connection->table('supplier_import_source_profiles')->find($id);
                    if ($created === null) {
                        throw new SupplierImportSourceProfilePersistenceException(null, null);
                    }

                    return $this->modelFromRow($created, $connection->getName());
                }

                throw new SupplierImportSourceProfileIdentityCollisionExhaustedException;
            }, 1);
        } catch (QueryException $exception) {
            throw SupplierImportSourceProfilePersistenceException::fromErrorInfo($exception->errorInfo);
        } catch (SupplierImportSourceProfilePersistenceException
            |SupplierImportSourceProfileIdentityCollisionExhaustedException
            |SupplierImportSourceProfileDescriptorCollisionException
            |SupplierImportSourceProfileOwnerNotFoundException $exception) {
                throw $exception;
            } catch (Throwable) {
                throw new SupplierImportSourceProfilePersistenceException(null, null);
            }
    }

    /** @param array<string, int|string> $expected */
    private function assertByteIdentical(object $existing, array $expected): void
    {
        try {
            SupplierSourceProfileIdentity::fromString($existing->source_identity);
        } catch (Throwable) {
            throw new SupplierImportSourceProfileDescriptorCollisionException;
        }

        foreach ($expected as $field => $value) {
            if (! property_exists($existing, $field) || $existing->{$field} !== $value) {
                throw new SupplierImportSourceProfileDescriptorCollisionException;
            }
        }
    }

    private function modelFromRow(object $row, string $connection): SupplierImportSourceProfile
    {
        return (new SupplierImportSourceProfile)
            ->setConnection($connection)
            ->newFromBuilder((array) $row);
    }
}
