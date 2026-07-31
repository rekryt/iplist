<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Codec;

use InvalidArgumentException;
use OpenCCK\AsyncTest;

/**
 * Байтовые векторы для geosite.dat. Ожидания посчитаны вручную по wire format,
 * а не сняты с текущей реализации — иначе тест зафиксировал бы любую ошибку
 * кодирования как «правильную».
 *
 * Разбор одного вектора (`GA` → `example.com`, type = RootDomain(2)):
 *   Domain:      08 02                     field 1 (varint) = 2
 *                12 0b "example.com"       field 2 (len 11)
 *   GeoSite:     0a 02 "GA"                field 1 (len 2)
 *                12 0f <15 байт Domain>    field 2 (len 15)
 *   GeoSiteList: 0a 15 <21 байт GeoSite>   field 1 (len 21)
 */
final class GeositeDatWriterTest extends AsyncTest {
    public function testEmptyPayloadProducesEmptyOutput(): void {
        self::assertSame('', GeositeDatWriter::encode('', GeositeDatWriter::TYPE_ROOT_DOMAIN));
        self::assertSame('', GeositeDatWriter::payload([]));
        self::assertSame('', GeositeDatWriter::payload(['ga' => []]));
    }

    public function testRootDomainVector(): void {
        $payload = GeositeDatWriter::payload(['ga' => ['example.com']]);

        self::assertSame(
            "\x0a\x15" . "\x0a\x02GA" . "\x12\x0f" . "\x08\x02" . "\x12\x0bexample.com",
            GeositeDatWriter::encode($payload, GeositeDatWriter::TYPE_ROOT_DOMAIN)
        );
    }

    public function testFullTypeVector(): void {
        $payload = GeositeDatWriter::payload(['ga' => ['example.com']]);

        self::assertSame(
            "\x0a\x15" . "\x0a\x02GA" . "\x12\x0f" . "\x08\x03" . "\x12\x0bexample.com",
            GeositeDatWriter::encode($payload, GeositeDatWriter::TYPE_FULL)
        );
    }

    /**
     * Plain = 0 — значение по умолчанию, канонический protobuf его опускает:
     * Domain на 2 байта короче, длины внешних сообщений уезжают следом.
     */
    public function testPlainTypeOmitsTypeField(): void {
        $payload = GeositeDatWriter::payload(['ga' => ['example.com']]);

        self::assertSame(
            "\x0a\x13" . "\x0a\x02GA" . "\x12\x0d" . "\x12\x0bexample.com",
            GeositeDatWriter::encode($payload, GeositeDatWriter::TYPE_PLAIN)
        );
    }

    public function testCodeIsUppercasedAndEntriesSorted(): void {
        $encoded = GeositeDatWriter::encode(
            GeositeDatWriter::payload(['zeta' => ['z.test'], 'alpha' => ['a.test']]),
            GeositeDatWriter::TYPE_FULL
        );

        self::assertStringContainsString('ALPHA', $encoded);
        self::assertStringContainsString('ZETA', $encoded);
        self::assertLessThan(strpos($encoded, 'ZETA'), strpos($encoded, 'ALPHA'));
    }

    public function testDomainsAreDedupedAndSorted(): void {
        $encoded = GeositeDatWriter::encode(
            GeositeDatWriter::payload(['ga' => ['b.test', 'a.test', 'b.test']]),
            GeositeDatWriter::TYPE_FULL
        );

        self::assertSame(1, substr_count($encoded, 'b.test'));
        self::assertLessThan(strpos($encoded, 'b.test'), strpos($encoded, 'a.test'));
    }

    /**
     * Один и тот же код может прийти дважды: сайт добавляет домены и в свой
     * список, и в список группы, а групп-сайтов в группе несколько.
     */
    public function testRepeatedCodeIsMerged(): void {
        $payload = GeositeDatWriter::payload(['ga' => ['a.test']]) . "\x1e" . "ga\nb.test";
        $encoded = GeositeDatWriter::encode($payload, GeositeDatWriter::TYPE_FULL);

        self::assertSame(1, substr_count($encoded, 'GA'));
        self::assertStringContainsString('a.test', $encoded);
        self::assertStringContainsString('b.test', $encoded);
    }

    public function testYieldCallbackIsCalledPerList(): void {
        $calls = 0;
        GeositeDatWriter::encode(
            GeositeDatWriter::payload(['a' => ['a.test'], 'b' => ['b.test'], 'c' => ['c.test']]),
            GeositeDatWriter::TYPE_FULL,
            function () use (&$calls): void {
                $calls++;
            }
        );

        self::assertSame(3, $calls);
    }

    public function testUnknownDomainTypeThrows(): void {
        $this->expectException(InvalidArgumentException::class);
        GeositeDatWriter::encode(GeositeDatWriter::payload(['ga' => ['a.test']]), 42);
    }

    public function testVarintEncodesMultiByteValues(): void {
        self::assertSame("\x00", ProtobufWriter::varint(0));
        self::assertSame("\x7f", ProtobufWriter::varint(127));
        self::assertSame("\x80\x01", ProtobufWriter::varint(128));
        self::assertSame("\xac\x02", ProtobufWriter::varint(300));
    }

    public function testNegativeVarintThrows(): void {
        $this->expectException(InvalidArgumentException::class);
        ProtobufWriter::varint(-1);
    }

    /**
     * Домены длиннее 127 байт уводят длину сообщения в двухбайтовый varint —
     * самое вероятное место, где ошибка в кодировании длины осталась бы незамеченной.
     */
    public function testLongDomainUsesTwoByteLength(): void {
        $domain = str_repeat('a', 200) . '.test';
        $encoded = GeositeDatWriter::encode(
            GeositeDatWriter::payload(['ga' => [$domain]]),
            GeositeDatWriter::TYPE_ROOT_DOMAIN
        );

        // Domain: 08 02 | 12 <varint(205)> <domain>
        self::assertStringContainsString("\x12" . ProtobufWriter::varint(strlen($domain)) . $domain, $encoded);
        self::assertSame(2, strlen(ProtobufWriter::varint(strlen($domain))));
    }
}
