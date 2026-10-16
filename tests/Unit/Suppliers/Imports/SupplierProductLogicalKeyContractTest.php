<?php

namespace Tests\Unit\Suppliers\Imports;

use App\Data\Suppliers\Imports\SupplierProductLogicalKey as Key;
use Error;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Stringable;

final class SupplierProductLogicalKeyContractTest extends TestCase
{
    private const RANGES = [
        [0x0000, 0x001F], [0x007F, 0x009F],
        [0x00AD, 0x00AD], [0x0600, 0x0605], [0x061C, 0x061C], [0x06DD, 0x06DD],
        [0x070F, 0x070F], [0x0890, 0x0891], [0x08E2, 0x08E2], [0x180E, 0x180E],
        [0x200B, 0x200F], [0x202A, 0x202E], [0x2060, 0x2064], [0x2066, 0x206F],
        [0xFEFF, 0xFEFF], [0xFFF9, 0xFFFB], [0x110BD, 0x110BD], [0x110CD, 0x110CD],
        [0x13430, 0x1343F], [0x1BCA0, 0x1BCA3], [0x1D173, 0x1D17A], [0xE0001, 0xE0001],
        [0xE0020, 0xE007F],
    ];

    public function test_logical_key_has_only_three_ordered_immutable_fields(): void
    {
        $key = Key::fromArray(['supplier_sku_bytes' => ' SKU ', 'supplier_feed_id' => 2, 'supplier_id' => 1]);
        $this->assertSame(['supplier_id' => 1, 'supplier_feed_id' => 2, 'supplier_sku_bytes' => 'SKU'], $key->persistenceAttributes());
        $copy = $key->persistenceAttributes();
        $copy['supplier_sku_bytes'] = 'changed';
        $this->assertSame('SKU', $key->persistenceAttributes()['supplier_sku_bytes']);
        $reflection = new ReflectionClass(Key::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
        $this->assertTrue($reflection->getConstructor()->isPrivate());
        $this->expectException(Error::class);
        $reflection->getProperty('values')->setValue($key, $copy);
    }

    #[DataProvider('originalCases')]
    public function test_original_twenty_one_sku_cases_preserve_exact_bytes(mixed $raw, ?string $hex): void
    {
        if ($hex === null) {
            $this->expectException(InvalidArgumentException::class);
        }
        $actual = $this->key($raw)->persistenceAttributes()['supplier_sku_bytes'];
        $this->assertSame($hex, bin2hex($actual));
    }

    public static function originalCases(): array
    {
        return [
            'edge spaces' => [hex2bin('2020534b552d312020'), '534b552d31'],
            'uppercase distinct' => [hex2bin('4162'), '4162'],
            'lowercase distinct' => [hex2bin('6162'), '6162'],
            'internal spaces' => [hex2bin('41202042'), '41202042'],
            'NBSP' => [hex2bin('c2a041c2a0'), 'c2a041c2a0'],
            'precomposed' => [hex2bin('c3a9'), 'c3a9'],
            'decomposed' => [hex2bin('65cc81'), '65cc81'],
            'null' => [null, null], 'empty' => ['', null], 'spaces' => [hex2bin('2020'), null],
            'TAB' => [hex2bin('0941'), null], 'LF' => [hex2bin('410a'), null],
            'NUL' => [hex2bin('0041'), null], 'DEL' => [hex2bin('417f'), null],
            'C1' => [hex2bin('41c285'), null], 'malformed' => [hex2bin('c328'), null],
            '255 ASCII' => [str_repeat('A', 255), str_repeat('41', 255)],
            '256 ASCII' => [str_repeat('A', 256), null],
            'raw 256 trimmable' => [' '.str_repeat('A', 255), null],
            '255 astral 1020 bytes' => [str_repeat(hex2bin('f09f9880'), 255), str_repeat('f09f9880', 255)],
            '256 astral 1024 bytes' => [str_repeat(hex2bin('f09f9880'), 256), null],
        ];
    }

    #[DataProvider('forbiddenCases')]
    public function test_all_pinned_cc_cf_scalars_reject_in_four_raw_positions(string $raw): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->key($raw);
    }

    public static function forbiddenCases(): array
    {
        $cases = [];
        foreach (self::RANGES as [$start, $end]) {
            foreach (range($start, $end) as $cp) {
                $c = mb_chr($cp, 'UTF-8');
                foreach (['beginning' => $c.'A', 'middle' => 'A'.$c.'B', 'end' => 'A'.$c, 'between ASCII spaces' => ' '.$c.' '] as $position => $raw) {
                    $cases[sprintf('U+%04X %s', $cp, $position)] = [$raw];
                }
            }
        }

        return $cases;
    }

    public function test_fixture_cardinality_and_every_interval_boundary(): void
    {
        $this->assertCount(21, self::originalCases());
        $this->assertCount(23, self::RANGES);
        $this->assertCount(940, self::forbiddenCases());
        $set = [];
        foreach (self::RANGES as [$start, $end]) {
            foreach (range($start, $end) as $cp) {
                $set[$cp] = true;
            }
        }
        $this->assertCount(235, $set);
        foreach (self::RANGES as [$start, $end]) {
            foreach ([$start - 1, $start, $end, $end + 1] as $cp) {
                if ($cp < 0) {
                    continue;
                }
                $raw = 'A'.mb_chr($cp, 'UTF-8').'B';
                try {
                    $actual = $this->key($raw)->persistenceAttributes()['supplier_sku_bytes'];
                    $this->assertArrayNotHasKey($cp, $set);
                    $this->assertSame($raw, $actual);
                } catch (InvalidArgumentException) {
                    $this->assertArrayHasKey($cp, $set);
                }
            }
        }
    }

    public function test_strict_utf8_raw_and_trimmed_boundaries_without_repair(): void
    {
        $hostile = new class implements Stringable
        {
            public function __toString(): string
            {
                throw new Error('Coercion is forbidden.');
            }
        };
        foreach ([false, true, 1, 1.5, [], new \stdClass, $hostile, "\x80", "\xc0\xaf", "\xe0\x80\xaf", "\xed\xa0\x80", "\xf4\x90\x80\x80", "\xf0\x9f\x98", str_repeat('A', 254)."\u{200D}", str_repeat('A', 255)."\u{200D}", str_repeat("e\u{0301}", 128), str_repeat("\u{1F600}", 255).'A'] as $raw) {
            try {
                $this->key($raw);
                $this->fail('Invalid raw SKU accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('invalid_supplier_product_logical_key_sku', $exception->getMessage());
            }
        }
        $value = str_repeat("e\u{0301}", 127).'e';
        $this->assertSame($value, $this->key($value)->persistenceAttributes()['supplier_sku_bytes']);
    }

    public function test_non_cc_cf_and_version_distinguishing_scalars_are_preserved(): void
    {
        foreach ([0x0416, 0x034F, 0xFE0F, 0xE0100, 0x13440, 0x2028, 0x2029, 0x2065, 0x00A0, 0xE000, 0x0378] as $cp) {
            $raw = mb_chr($cp, 'UTF-8');
            $this->assertSame($raw, $this->key($raw)->persistenceAttributes()['supplier_sku_bytes']);
        }
    }

    public function test_strict_ids_and_exact_field_shape(): void
    {
        $valid = ['supplier_id' => 1, 'supplier_feed_id' => 2, 'supplier_sku_bytes' => 'SKU'];
        foreach (['supplier_id', 'supplier_feed_id'] as $field) {
            foreach ([null, false, true, 0, -1, '1', 1.0, [], new \stdClass] as $value) {
                try {
                    Key::fromArray([...$valid, $field => $value]);
                    $this->fail('Coerced or invalid ID accepted.');
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        foreach ([[], array_slice($valid, 0, 2), [...$valid, 'source_identity' => 'A'], [...$valid, 'schema' => 'v1']] as $value) {
            try {
                Key::fromArray($value);
                $this->fail('Noncanonical shape accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_source_independent_key_is_stable_across_a_to_b_and_retry(): void
    {
        $captured = $this->key(' SKU ')->persistenceAttributes();
        $this->assertSame($captured, $this->key('SKU')->persistenceAttributes());
        $this->assertNotSame($captured, $this->key('sku')->persistenceAttributes());
        $this->assertNotSame($captured, Key::fromArray([...$captured, 'supplier_feed_id' => 3])->persistenceAttributes());
        $this->assertNotSame($captured, Key::fromArray([...$captured, 'supplier_id' => 3])->persistenceAttributes());
    }

    private function key(mixed $raw): Key
    {
        return Key::fromArray(['supplier_id' => 1, 'supplier_feed_id' => 2, 'supplier_sku_bytes' => $raw]);
    }
}
