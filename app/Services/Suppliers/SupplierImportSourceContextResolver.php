<?php

namespace App\Services\Suppliers;

use App\Contracts\Suppliers\SupplierImportSourceDescriptorProvider;
use App\Data\Suppliers\Imports\CanonicalSupplierImportSourceExecution;
use App\Data\Suppliers\Imports\ImportJobIdentity;
use App\Data\Suppliers\Imports\ResolvedSupplierImportSourceContext;
use App\Data\Suppliers\Snapshots\CanonicalSupplierContract;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierImportMapping;
use App\Models\SupplierFeed;
use App\Models\SupplierImportSourceExecution;
use App\Models\SupplierImportSourceProfile;
use App\Repositories\Suppliers\SupplierImportSourceExecutionRepository;
use App\Repositories\Suppliers\SupplierImportSourceProfileRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class SupplierImportSourceContextResolver
{
    public function __construct(
        private DatabaseManager $database,
        private SupplierImportSourceDescriptorProvider $descriptorProvider,
        private SupplierImportSourceProfileRepository $profileRepository,
        private SupplierImportSourceExecutionRepository $executionRepository,
    ) {}

    public function resolveSourceContext(
        int $importJobId,
        int $expectedClaimIdentity,
    ): ResolvedSupplierImportSourceContext {
        CanonicalSupplierContract::positiveInteger($importJobId, 'import_job_id');
        CanonicalSupplierContract::positiveInteger($expectedClaimIdentity, 'expected_claim_identity');

        $connection = $this->database->connection();
        if ($connection->getDriverName() !== 'mysql') {
            throw new RuntimeException('source_context_mysql_8_4_required');
        }

        if ($connection->transactionLevel() !== 0) {
            throw new RuntimeException('source_context_nested_transaction_forbidden');
        }

        try {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

            return $connection->transaction(function () use (
                $connection,
                $importJobId,
                $expectedClaimIdentity,
            ): ResolvedSupplierImportSourceContext {
                $job = $connection->table('import_jobs')
                    ->where('id', $importJobId)
                    ->lockForUpdate()
                    ->first([
                        'id',
                        'supplier_id',
                        'supplier_feed_id',
                    ]);

                if ($job === null) {
                    throw new RuntimeException('source_context_import_job_not_found');
                }

                $claim = $connection->table('supplier_import_execution_claims')
                    ->where('id', $expectedClaimIdentity)
                    ->where('import_job_id', $importJobId)
                    ->lockForUpdate()
                    ->first();

                if ($claim === null) {
                    throw new RuntimeException('source_context_claim_mismatch');
                }

                $history = $this->lockAndVerifyOwnership($connection, $job, $claim);
                $existing = $this->executionRepository->findByHistoryForUpdate(
                    $connection,
                    (int) $history->id,
                );

                if ($existing !== null) {
                    return $this->resolveExistingContext($connection, $job, $claim, $existing);
                }

                $job = $connection->table('import_jobs')
                    ->where('id', $importJobId)
                    ->lockForUpdate()
                    ->first([
                        'id',
                        'supplier_id',
                        'supplier_feed_id',
                        'xml_mapping_template_id',
                        'type',
                    ]);

                if ($job === null) {
                    throw new RuntimeException('source_context_import_job_not_found');
                }

                $feedRow = $connection->table('supplier_feeds')
                    ->where('id', $job->supplier_feed_id)
                    ->lockForUpdate()
                    ->first();

                if ($feedRow === null
                    || (int) $feedRow->supplier_id !== (int) $job->supplier_id
                    || $feedRow->status !== 'active'
                    || $feedRow->feed_type !== $job->type) {
                    throw new RuntimeException('source_context_feed_selector_mismatch');
                }

                $mapping = $this->resolveMapping($connection, $job, $feedRow);
                $feed = (new SupplierFeed)
                    ->setConnection($connection->getName())
                    ->newFromBuilder((array) $feedRow);
                $descriptor = $this->descriptorProvider->descriptorFor($feed, $mapping);
                $descriptorValues = $descriptor->toCanonicalArray();

                if ($descriptorValues['supplier_id'] !== (int) $job->supplier_id
                    || $descriptorValues['supplier_feed_id'] !== (int) $job->supplier_feed_id
                    || $descriptorValues['feed_type'] !== $job->type
                    || $descriptorValues['mapping_contract_fingerprint'] !== $mapping->fingerprint()) {
                    throw new RuntimeException('source_context_descriptor_mismatch');
                }

                $profile = $this->profileRepository->resolveOrCreate($descriptor);
                $context = ResolvedSupplierImportSourceContext::fromProfile($profile);
                if ($context->sourceDescriptorFingerprint() !== $descriptor->fingerprint()) {
                    throw new RuntimeException('source_context_profile_mismatch');
                }

                $jobIdentity = $this->jobIdentityFromLockedRow($job);
                $execution = CanonicalSupplierImportSourceExecution::fromContracts(
                    $jobIdentity,
                    $context,
                    (int) $history->id,
                    CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.u\Z'),
                );
                $this->executionRepository->resolveOrInsertWithinTransaction($connection, $execution);

                return $context;
            }, 1);
        } catch (QueryException) {
            throw new RuntimeException('source_context_resolution_failed');
        } catch (InvalidArgumentException|RuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new RuntimeException('source_context_resolution_failed');
        }
    }

    private function lockAndVerifyOwnership(Connection $connection, object $job, object $claim): object
    {
        if ($claim->supplier_feed_id === null
            || $claim->import_history_id === null
            || (int) $claim->supplier_id !== (int) $job->supplier_id
            || (int) $claim->supplier_feed_id !== (int) $job->supplier_feed_id) {
            throw new RuntimeException('source_context_claim_ownership_mismatch');
        }

        $history = $connection->table('import_histories')
            ->where('id', $claim->import_history_id)
            ->lockForUpdate()
            ->first(['id', 'import_job_id', 'supplier_id', 'supplier_feed_id']);

        if ($history === null
            || (int) $history->import_job_id !== (int) $job->id
            || (int) $history->supplier_id !== (int) $job->supplier_id
            || (int) $history->supplier_feed_id !== (int) $job->supplier_feed_id) {
            throw new RuntimeException('source_context_history_ownership_mismatch');
        }

        if ($claim->supplier_import_run_id !== null) {
            $run = $connection->table('supplier_import_runs')
                ->where('id', $claim->supplier_import_run_id)
                ->lockForUpdate()
                ->first(['id', 'supplier_id', 'supplier_feed_id', 'import_job_id']);

            if ($run === null
                || (int) $run->supplier_id !== (int) $job->supplier_id
                || (int) $run->supplier_feed_id !== (int) $job->supplier_feed_id
                || (int) $run->import_job_id !== (int) $job->id) {
                throw new RuntimeException('source_context_run_ownership_mismatch');
            }
        }

        return $history;
    }

    private function resolveMapping(Connection $connection, object $job, object $feed): CanonicalSupplierImportMapping
    {
        if ($job->type === 'xml') {
            if ($job->xml_mapping_template_id === null) {
                throw new RuntimeException('source_context_xml_template_required');
            }

            $template = $connection->table('xml_mapping_templates')
                ->where('id', $job->xml_mapping_template_id)
                ->lockForUpdate()
                ->first([
                    'id',
                    'supplier_id',
                    'root_path',
                    'field_map',
                    'validation_rules',
                    'defaults',
                    'is_active',
                ]);

            if ($template === null
                || ! (bool) $template->is_active
                || ($template->supplier_id !== null
                    && (int) $template->supplier_id !== (int) $job->supplier_id)) {
                throw new RuntimeException('source_context_xml_template_mismatch');
            }

            $effectiveMapping = [
                'root_path' => $template->root_path,
                'field_map' => $this->decodeJsonObject($template->field_map),
                'validation_rules' => $this->decodeJsonObject($template->validation_rules),
                'defaults' => $this->decodeJsonObject($template->defaults),
            ];
        } elseif ($job->type === 'csv') {
            if ($job->xml_mapping_template_id !== null) {
                throw new RuntimeException('source_context_csv_template_forbidden');
            }

            $effectiveMapping = $this->decodeJsonObject($feed->mapping);
        } else {
            throw new RuntimeException('source_context_import_type_unsupported');
        }

        return CanonicalSupplierImportMapping::fromArray([
            'schema' => CanonicalSupplierImportMapping::VERSION,
            'feed_type' => $job->type,
            'effective_mapping' => $effectiveMapping,
        ]);
    }

    private function resolveExistingContext(
        Connection $connection,
        object $job,
        object $claim,
        SupplierImportSourceExecution $existing,
    ): ResolvedSupplierImportSourceContext {
        if ((int) $existing->getRawOriginal('import_job_id') !== (int) $job->id
            || (int) $existing->getRawOriginal('supplier_id') !== (int) $job->supplier_id
            || (int) $existing->getRawOriginal('supplier_feed_id') !== (int) $job->supplier_feed_id
            || (int) $existing->getRawOriginal('import_history_id') !== (int) $claim->import_history_id) {
            throw new RuntimeException('source_execution_fingerprint_collision');
        }

        $profileRow = $connection->table('supplier_import_source_profiles')
            ->where('id', $existing->getRawOriginal('supplier_import_source_profile_id'))
            ->where('supplier_id', $existing->getRawOriginal('supplier_id'))
            ->where('supplier_feed_id', $existing->getRawOriginal('supplier_feed_id'))
            ->where('source_identity', $existing->getRawOriginal('source_identity'))
            ->where('source_descriptor_fingerprint', $existing->getRawOriginal('source_descriptor_fingerprint'))
            ->first();

        if ($profileRow === null) {
            throw new RuntimeException('source_context_profile_not_found');
        }

        $context = ResolvedSupplierImportSourceContext::fromProfile(
            $this->profileFromRow($profileRow, $connection->getName()),
        );
        $identity = ImportJobIdentity::fromCanonicalBytes(
            (string) $existing->getRawOriginal('import_job_identity_canonical_bytes'),
            (string) $existing->getRawOriginal('import_job_identity_fingerprint'),
        );
        $capturedAt = $existing->getAttribute('captured_at')
            ->utc()
            ->format('Y-m-d\TH:i:s.u\Z');
        $canonical = CanonicalSupplierImportSourceExecution::fromContracts(
            $identity,
            $context,
            (int) $existing->getRawOriginal('import_history_id'),
            $capturedAt,
        );
        $this->executionRepository->assertByteIdentical($existing, $canonical->persistenceAttributes());

        return $context;
    }

    private function jobIdentityFromLockedRow(object $job): ImportJobIdentity
    {
        return ImportJobIdentity::fromArray([
            'schema' => ImportJobIdentity::VERSION,
            'import_job_id' => (int) $job->id,
            'supplier_id' => (int) $job->supplier_id,
            'supplier_feed_id' => (int) $job->supplier_feed_id,
            'xml_mapping_template_id' => $job->xml_mapping_template_id === null
                ? null
                : (int) $job->xml_mapping_template_id,
            'import_type' => $job->type,
        ]);
    }

    /** @return array<string, mixed> */
    private function decodeJsonObject(mixed $value): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $exception) {
                throw new InvalidArgumentException('invalid_source_context_mapping', previous: $exception);
            }
        }

        return CanonicalSupplierContract::canonicalObject($value, 'source_context_mapping');
    }

    private function profileFromRow(object $row, string $connection): SupplierImportSourceProfile
    {
        return (new SupplierImportSourceProfile)
            ->setConnection($connection)
            ->newFromBuilder((array) $row);
    }
}
