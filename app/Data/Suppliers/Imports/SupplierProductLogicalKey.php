<?php

namespace App\Data\Suppliers\Imports;

use App\Data\Suppliers\Snapshots\CanonicalSupplierContract;
use InvalidArgumentException;

final readonly class SupplierProductLogicalKey
{
    private const FIELDS = ['supplier_id', 'supplier_feed_id', 'supplier_sku_bytes'];

    // UCD 17.0.0 Cc OR Cf: 2 Cc and 21 Cf ranges, exactly 235 scalars.
    private const FORBIDDEN = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{00AD}\x{0600}-\x{0605}\x{061C}\x{06DD}\x{070F}\x{0890}-\x{0891}\x{08E2}\x{180E}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{206F}\x{FEFF}\x{FFF9}-\x{FFFB}\x{110BD}\x{110CD}\x{13430}-\x{1343F}\x{1BCA0}-\x{1BCA3}\x{1D173}-\x{1D17A}\x{E0001}\x{E0020}-\x{E007F}]/u';

    /** @param array{supplier_id: int, supplier_feed_id: int, supplier_sku_bytes: string} $values */
    private function __construct(private array $values) {}

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $values = CanonicalSupplierContract::ordered($values, self::FIELDS);
        CanonicalSupplierContract::positiveInteger($values['supplier_id'], 'supplier_id');
        CanonicalSupplierContract::positiveInteger($values['supplier_feed_id'], 'supplier_feed_id');
        self::validateSku($values['supplier_sku_bytes']);
        $values['supplier_sku_bytes'] = trim($values['supplier_sku_bytes']);
        self::validateSku($values['supplier_sku_bytes']);

        return new self($values);
    }

    /** @return array{supplier_id: int, supplier_feed_id: int, supplier_sku_bytes: string} */
    public function persistenceAttributes(): array
    {
        return $this->values;
    }

    private static function validateSku(mixed $value): void
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')
            || $value === '' || strlen($value) > 1020 || mb_strlen($value, 'UTF-8') > 255
            || preg_match(self::FORBIDDEN, $value) !== 0) {
            throw new InvalidArgumentException('invalid_supplier_product_logical_key_sku');
        }
    }
}
