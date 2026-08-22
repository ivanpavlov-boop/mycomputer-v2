<?php

namespace App\Data\Suppliers\Snapshots;

use InvalidArgumentException;

final readonly class CanonicalSupplierDispatchAlert extends FixedOrderCanonicalJson
{
    public const SCHEMA = 'supplier-import-dispatch-alert-v1';

    public const ALERT_TYPE = 'dispatch_watchdog_overdue';

    protected const DOMAIN = 'supplier-import-dispatch-monitor-alert-v1';

    protected const FIELDS = [
        'schema',
        'alert_type',
        'dispatch_outbox_id',
        'delivery_watchdog_at',
        'severity',
        'critical_bucket',
    ];

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $values = CanonicalSupplierContract::ordered($values, self::FIELDS);

        if ($values['schema'] !== self::SCHEMA) {
            throw new InvalidArgumentException('invalid_schema');
        }
        if ($values['alert_type'] !== self::ALERT_TYPE) {
            throw new InvalidArgumentException('invalid_alert_type');
        }

        CanonicalSupplierContract::positiveInteger($values['dispatch_outbox_id'], 'dispatch_outbox_id');
        CanonicalSupplierContract::mysqlUtcMicroseconds($values['delivery_watchdog_at'], 'delivery_watchdog_at');
        CanonicalSupplierContract::enum($values['severity'], 'severity', ['warning', 'critical']);

        if ($values['severity'] === 'warning' && $values['critical_bucket'] !== null) {
            throw new InvalidArgumentException('invalid_critical_bucket');
        }

        if ($values['severity'] === 'critical') {
            CanonicalSupplierContract::unsignedInteger($values['critical_bucket'], 'critical_bucket');
        }

        return new self($values);
    }
}
