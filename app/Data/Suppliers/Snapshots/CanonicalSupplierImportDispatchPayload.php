<?php

namespace App\Data\Suppliers\Snapshots;

use InvalidArgumentException;

final readonly class CanonicalSupplierImportDispatchPayload extends FixedOrderCanonicalJson
{
    public const SCHEMA = 'supplier-import-dispatch-payload-v1';

    protected const DOMAIN = 'mycomputer:supplier-dispatch-payload:v1';

    protected const FIELDS = [
        'schema_version',
        'execution_claim_id',
        'logical_execution_key',
        'parent_type',
        'parent_id',
        'transport_deadline_at',
        'force',
    ];

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $values = CanonicalSupplierContract::ordered($values, self::FIELDS);

        if ($values['schema_version'] !== self::SCHEMA) {
            throw new InvalidArgumentException('invalid_schema_version');
        }

        CanonicalSupplierContract::positiveInteger($values['execution_claim_id'], 'execution_claim_id');
        CanonicalSupplierContract::hex64($values['logical_execution_key'], 'logical_execution_key');
        CanonicalSupplierContract::enum($values['parent_type'], 'parent_type', [
            'supplier_import_run',
            'supplier_feed',
        ]);
        CanonicalSupplierContract::positiveInteger($values['parent_id'], 'parent_id');
        CanonicalSupplierContract::mysqlUtcMicroseconds($values['transport_deadline_at'], 'transport_deadline_at');
        CanonicalSupplierContract::boolean($values['force'], 'force');

        return new self($values);
    }
}
