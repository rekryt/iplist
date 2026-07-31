<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Codec;

use OpenCCK\AsyncTest;

/**
 * Байтовые векторы для geoip.dat. Ожидания посчитаны вручную по wire format.
 *
 * Разбор вектора (`GA` → `1.2.3.0/24`):
 *   CIDR:       0a 04 01 02 03 00        field 1 (bytes, 4 байта адреса)
 *               10 18                    field 2 (varint) = 24
 *   GeoIP:      0a 02 "GA"               field 1 (len 2)
 *               12 08 <8 байт CIDR>      field 2 (len 8)
 *   GeoIPList:  0a 0e <14 байт GeoIP>    field 1 (len 14)
 */
final class GeoipDatWriterTest extends AsyncTest {
    public function testEmptyPayloadProducesEmptyOutput(): void {
        self::assertSame('', GeoipDatWriter::encode(''));
        self::assertSame('', GeoipDatWriter::payload([]));
    }

    public function testIpv4CidrVector(): void {
        self::assertSame(
            "\x0a\x0e" . "\x0a\x02GA" . "\x12\x08" . "\x0a\x04\x01\x02\x03\x00" . "\x10\x18",
            GeoipDatWriter::encode(GeoipDatWriter::payload(['ga' => ['1.2.3.0/24']]))
        );
    }

    /**
     * Одиночный адрес без маски — как в `data=ip4` — получает полную маску.
     */
    public function testBareIpv4GetsFullMask(): void {
        self::assertSame(
            "\x0a\x0e" . "\x0a\x02GA" . "\x12\x08" . "\x0a\x04\x0a\x00\x00\x01" . "\x10\x20",
            GeoipDatWriter::encode(GeoipDatWriter::payload(['ga' => ['10.0.0.1']]))
        );
    }

    public function testIpv6UsesSixteenBytes(): void {
        $encoded = GeoipDatWriter::encode(GeoipDatWriter::payload(['ga' => ['2001:db8::/32']]));

        // 16 байт адреса + маска 32 (0x20)
        self::assertStringContainsString("\x0a\x10" . inet_pton('2001:db8::') . "\x10\x20", $encoded);
    }

    public function testBareIpv6GetsFullMask(): void {
        $encoded = GeoipDatWriter::encode(GeoipDatWriter::payload(['ga' => ['2001:db8::1']]));

        // маска 128 не влезает в один байт varint: 0x80 0x01
        self::assertStringContainsString("\x0a\x10" . inet_pton('2001:db8::1') . "\x10\x80\x01", $encoded);
    }

    /**
     * prefix = 0 — значение по умолчанию, канонический protobuf такое поле
     * опускает, поэтому у `0.0.0.0/0` остаётся только адрес.
     */
    public function testZeroPrefixIsOmitted(): void {
        self::assertSame(
            "\x0a\x0c" . "\x0a\x02GA" . "\x12\x06" . "\x0a\x04\x00\x00\x00\x00",
            GeoipDatWriter::encode(GeoipDatWriter::payload(['ga' => ['0.0.0.0/0']]))
        );
    }

    public function testCodeIsUppercasedAndListsSorted(): void {
        $encoded = GeoipDatWriter::encode(GeoipDatWriter::payload(['zeta' => ['10.0.0.1'], 'alpha' => ['10.0.0.2']]));

        self::assertStringContainsString('ALPHA', $encoded);
        self::assertLessThan(strpos($encoded, 'ZETA'), strpos($encoded, 'ALPHA'));
    }

    public function testDuplicateRowsAreCollapsed(): void {
        $single = GeoipDatWriter::encode(GeoipDatWriter::payload(['ga' => ['10.0.0.1']]));
        $doubled = GeoipDatWriter::encode(GeoipDatWriter::payload(['ga' => ['10.0.0.1', '10.0.0.1']]));

        self::assertSame($single, $doubled);
    }

    /**
     * Мусорные строки пропускаются молча: одна битая запись не должна ломать
     * весь файл.
     */
    public function testInvalidRowsAreSkipped(): void {
        $encoded = GeoipDatWriter::encode(
            GeoipDatWriter::payload(['ga' => ['not-an-ip', '10.0.0.1', '10.0.0.2/99', '10.0.0.3/-1']])
        );

        self::assertSame(GeoipDatWriter::encode(GeoipDatWriter::payload(['ga' => ['10.0.0.1']])), $encoded);
    }

    public function testListWithOnlyInvalidRowsIsDropped(): void {
        self::assertSame('', GeoipDatWriter::encode(GeoipDatWriter::payload(['ga' => ['nonsense']])));
    }

    public function testYieldCallbackIsCalledPerList(): void {
        $calls = 0;
        GeoipDatWriter::encode(
            GeoipDatWriter::payload(['a' => ['10.0.0.1'], 'b' => ['10.0.0.2'], 'c' => ['10.0.0.3']]),
            function () use (&$calls): void {
                $calls++;
            }
        );

        self::assertSame(3, $calls);
    }
}
