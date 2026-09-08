<?php

namespace Tests\Unit\Suppliers\Imports;

use App\Data\Suppliers\Imports\CanonicalSupplierImportSourcePayloadReceipt as Receipt;
use App\Models\SupplierImportSourcePayloadReceipt;
use App\Repositories\Suppliers\SupplierImportSourcePayloadReceiptRepository;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use LogicException;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SupplierImportSourcePayloadReceiptContractTest extends TestCase
{
    public function test_independent_lf_crlf_vectors_have_exact_sorted_bytes_and_fingerprints(): void
    {
        $vectors = [
            ['6162630a', 4, 'edeaaff3f1774ad2888673770c6d64097e391bc362d7d6fb34982ddf0efd18cb', '3cf87de2d792891ebdfb97c5d6bc39706b4b293371c7c2c54011a662f791f0f7'],
            ['6162630d0a', 5, '552bab6864c7a7b69a502ed1854b9245c0e1a30f008aaa0b281da62585fdb025', 'e74b5597725ca73cee31dbcf5eb5f551ad62d22e6da3cc80845267589f8ee1bf'],
        ];
        foreach ($vectors as [$hex, $size, $digest, $fingerprint]) {
            $this->assertSame($size, strlen(hex2bin($hex)));
            $this->assertSame($digest, hash('sha256', hex2bin($hex)));
            $json = '{"accepted_payload_bytes":'.$size.',"accepted_payload_sha256":"'.$digest.'","schema":"supplier_import_source_payload_receipt_v1","source_execution_fingerprint":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","supplier_import_source_execution_id":7}';
            $literal = "supplier_import_source_payload_receipt_v1\0".$json;
            $value = Receipt::fromArray([...$this->values(), 'accepted_payload_bytes' => $size, 'accepted_payload_sha256' => $digest]);
            $this->assertSame(354, strlen($literal));
            $this->assertSame($fingerprint, hash('sha256', $literal));
            $this->assertSame($literal, $value->canonicalBytes());
            $this->assertSame($fingerprint, $value->fingerprint());
            $this->assertSame($literal, Receipt::fromCanonicalBytes($literal, $fingerprint)->canonicalBytes());
            $this->assertSame($literal, Receipt::fromArray(array_reverse($value->toCanonicalArray(), true))->canonicalBytes());
            $this->assertSame(7, $value->sourceExecutionId());
            $this->assertSame(str_repeat('a', 64), $value->sourceExecutionFingerprint());
            $this->assertCount(6, $value->persistenceAttributes());
            $this->assertArrayNotHasKey('created_at', $value->persistenceAttributes());
        }
    }

    public function test_shape_and_all_types_are_closed_without_coercion_or_operational_limits(): void
    {
        $valid = $this->values();
        $invalid = [[], array_values($valid), [...$valid, 'schema' => 'v2']];
        foreach (array_keys($valid) as $key) {
            $candidate = $valid;
            unset($candidate[$key]);
            $invalid[] = $candidate;
        }
        foreach (['created_at', 'path', 'payload', 'payload_receipt_fingerprint', 'file_identity'] as $extra) {
            $invalid[] = [...$valid, $extra => 'synthetic'];
        }
        foreach (['supplier_import_source_execution_id', 'accepted_payload_bytes'] as $field) {
            foreach ([0, -1, null, '7', 7.0, true, false, [], '18446744073709551616', PHP_INT_MAX + 1] as $bad) {
                $invalid[] = [...$valid, $field => $bad];
            }
            $this->assertSame(PHP_INT_MAX, Receipt::fromArray([...$valid, $field => PHP_INT_MAX])->toCanonicalArray()[$field]);
        }
        foreach (['source_execution_fingerprint', 'accepted_payload_sha256'] as $field) {
            foreach ([null, 42, false, [], '', str_repeat('A', 64), str_repeat('g', 64), str_repeat('a', 63), str_repeat('a', 65), str_repeat('a', 64)."\n"] as $bad) {
                $invalid[] = [...$valid, $field => $bad];
            }
        }
        foreach ($invalid as $candidate) {
            try {
                Receipt::fromArray($candidate);
                $this->fail('Invalid receipt input accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_canonical_decoder_rejects_forgery_wrong_domain_and_noncanonical_json(): void
    {
        $value = Receipt::fromArray($this->values());
        $bytes = $value->canonicalBytes();
        $badBytes = [
            $bytes."\n", str_replace("\0", '', $bytes), str_replace("\0", "\0\0", $bytes),
            'wrong'.$bytes, str_replace('_v1', '_v2', $bytes),
            str_replace('"accepted_payload_bytes":4', '"accepted_payload_bytes":"4"', $bytes),
            str_replace('"accepted_payload_bytes":4', '"accepted_payload_bytes":4.0', $bytes),
            str_replace('{', '{ ', $bytes),
            Receipt::VERSION."\0".json_encode($this->values()),
            Receipt::VERSION."\0null", Receipt::VERSION."\0{bad}",
            str_replace('"accepted_payload_bytes":4', '"accepted_payload_bytes":4,"accepted_payload_bytes":4', $bytes),
        ];
        foreach ($badBytes as $candidate) {
            try {
                Receipt::fromCanonicalBytes($candidate, hash('sha256', $candidate));
                $this->fail('Noncanonical receipt bytes accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([str_repeat('0', 64), strtoupper($value->fingerprint()), ''] as $fingerprint) {
            try {
                Receipt::fromCanonicalBytes($bytes, $fingerprint);
                $this->fail('Forged fingerprint accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_model_rejects_mass_assignment_and_all_immutable_paths_without_database_access(): void
    {
        foreach (['fill', 'forceFill'] as $method) {
            foreach ([false, true] as $unguarded) {
                try {
                    $operation = fn () => (new SupplierImportSourcePayloadReceipt)->{$method}(['accepted_payload_bytes' => 4]);
                    $unguarded ? SupplierImportSourcePayloadReceipt::unguarded($operation) : $operation();
                    $this->fail('Mass assignment accepted.');
                } catch (MassAssignmentException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        $mutations = [
            fn ($m) => $m->save(), fn ($m) => $m->saveQuietly(), fn ($m) => $m->update(),
            fn ($m) => $m->updateQuietly(), fn ($m) => $m->updateOrFail(),
            fn ($m) => $m->touch(), fn ($m) => $m->touchQuietly(),
            fn ($m) => $m->delete(), fn ($m) => $m->deleteQuietly(), fn ($m) => $m->forceDelete(),
            fn ($m) => $m->increment('accepted_payload_bytes'), fn ($m) => $m->decrement('accepted_payload_bytes'),
            fn ($m) => $m->incrementQuietly('accepted_payload_bytes'), fn ($m) => $m->decrementQuietly('accepted_payload_bytes'),
        ];
        foreach ([false, true] as $persisted) {
            foreach ($mutations as $mutation) {
                $model = new SupplierImportSourcePayloadReceipt;
                $model->exists = $persisted;
                $model->setRawAttributes(['id' => 1, ...Receipt::fromArray($this->values())->persistenceAttributes()], true);
                try {
                    $mutation($model);
                    $this->fail('Immutable model mutation accepted.');
                } catch (LogicException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    public function test_only_two_exact_receipt_unique_keys_are_eligible_duplicate_races(): void
    {
        $method = new ReflectionMethod(SupplierImportSourcePayloadReceiptRepository::class, 'isEligibleDuplicate');
        foreach ([
            ['23000', 1062, 'uq_import_source_payload_receipt_execution', true],
            ['23000', 1062, 'uq_import_source_payload_receipt_fingerprint', true],
            ['23000', 1062, 'PRIMARY', false],
            ['23000', 1452, 'uq_import_source_payload_receipt_execution', false],
            ['40001', 1213, 'uq_import_source_payload_receipt_execution', false],
            ['08S01', 2013, 'uq_import_source_payload_receipt_execution', false],
            ['23000', '1062', 'uq_import_source_payload_receipt_execution', false],
        ] as [$state, $code, $key, $eligible]) {
            $previous = new PDOException('synthetic');
            $previous->errorInfo = [$state, $code, "Duplicate entry 'synthetic' for key 'supplier_import_source_payload_receipts.{$key}'"];
            $exception = new QueryException('disposable', 'synthetic', [], $previous);
            $this->assertSame($eligible, $method->invoke(new SupplierImportSourcePayloadReceiptRepository, $exception));
        }
    }

    /** @return array<string, int|string> */
    private function values(): array
    {
        return [
            'schema' => Receipt::VERSION,
            'supplier_import_source_execution_id' => 7,
            'source_execution_fingerprint' => str_repeat('a', 64),
            'accepted_payload_bytes' => 4,
            'accepted_payload_sha256' => 'edeaaff3f1774ad2888673770c6d64097e391bc362d7d6fb34982ddf0efd18cb',
        ];
    }
}
