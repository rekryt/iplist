<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\AsyncTest;
use OpenCCK\Infrastructure\Codec\GeositeDatWriter;
use RuntimeException;

/**
 * HTTP-контракт `?format=geosite`. Тело разбирается мини-парсером protobuf
 * прямо в тесте: проверяем именно то, что увидит xray, а не то, что вернул
 * наш же энкодер.
 */
final class GeositeControllerTest extends AsyncTest {
    public function testMissingDataReturnsError(): void {
        self::assertStringContainsString(
            "'data' GET parameter is required",
            $this->body($this->get('/', ['format' => 'geosite']))
        );
    }

    public function testInvalidDataReturnsError(): void {
        self::assertStringContainsString(
            "'data' GET parameter must be 'domains'",
            $this->body($this->get('/', ['format' => 'geosite', 'data' => 'cidr4']))
        );
    }

    public function testInvalidDomainTypeReturnsError(): void {
        self::assertStringContainsString(
            "'domaintype' GET parameter must be",
            $this->body($this->get('/', ['format' => 'geosite', 'data' => 'domains', 'domaintype' => 'nope']))
        );
    }

    public function testHeadersAndContentLength(): void {
        $response = $this->get('/', ['format' => 'geosite', 'data' => 'domains']);
        $body = $this->body($response);

        self::assertSame(200, $response->getStatus());
        self::assertSame('application/octet-stream', $response->getHeader('content-type'));
        self::assertStringContainsString('geosite.dat', $response->getHeader('content-disposition') ?? '');
        self::assertSame((string) strlen($body), $response->getHeader('content-length'));
    }

    public function testEntriesAreTaggedBySiteAndGroupInUpperCase(): void {
        $lists = $this->decode($this->body($this->get('/', ['format' => 'geosite', 'data' => 'domains'])));

        // портал
        self::assertArrayHasKey('GAME-A', $lists);
        self::assertArrayHasKey('CASINO-A', $lists);
        // группа
        self::assertArrayHasKey('GAMES', $lists);
        self::assertArrayHasKey('CASINO', $lists);

        self::assertContains('game-a.com', array_column($lists['GAME-A'], 'value'));
        // список группы объединяет домены всех своих порталов
        $games = array_column($lists['GAMES'], 'value');
        self::assertContains('game-a.com', $games);
        self::assertContains('game-b.com', $games);
    }

    public function testSiteFilterNarrowsOutput(): void {
        $lists = $this->decode(
            $this->body($this->get('/', ['format' => 'geosite', 'data' => 'domains', 'site' => 'game-a']))
        );

        // порядок — по коду списка: '-' (0x2D) сортируется раньше 'S'
        self::assertSame(['GAME-A', 'GAMES'], array_keys($lists));
        self::assertSame(array_column($lists['GAMES'], 'value'), array_column($lists['GAME-A'], 'value'));
    }

    public function testExcludeGroupRemovesLists(): void {
        $lists = $this->decode(
            $this->body($this->get('/', ['format' => 'geosite', 'data' => 'domains', 'exclude[group]' => 'games']))
        );

        self::assertArrayNotHasKey('GAMES', $lists);
        self::assertArrayNotHasKey('GAME-A', $lists);
        self::assertArrayHasKey('TOOLS', $lists);
    }

    public function testExcludeDomainRemovesSingleDomain(): void {
        $lists = $this->decode(
            $this->body(
                $this->get('/', [
                    'format' => 'geosite',
                    'data' => 'domains',
                    'site' => 'game-a',
                    'exclude[domain]' => 'api.game-a.com',
                ])
            )
        );

        $values = array_column($lists['GAME-A'], 'value');
        self::assertNotContains('api.game-a.com', $values);
        self::assertContains('game-a.com', $values);
    }

    public function testWildcardCollapsesDomains(): void {
        $lists = $this->decode(
            $this->body(
                $this->get('/', ['format' => 'geosite', 'data' => 'domains', 'site' => 'game-a', 'wildcard' => 1])
            )
        );

        $values = array_column($lists['GAME-A'], 'value');
        self::assertContains('game-a.com', $values);
        self::assertNotContains('www.game-a.com', $values);
        // cdn.game-a.co.uk сворачивается до трёхуровневого вида (co.uk — двухуровневая зона)
        self::assertContains('game-a.co.uk', $values);
    }

    /**
     * @dataProvider domainTypes
     */
    public function testDomainTypeIsReflectedInOutput(string $name, int $expectedType): void {
        $lists = $this->decode(
            $this->body(
                $this->get('/', [
                    'format' => 'geosite',
                    'data' => 'domains',
                    'site' => 'game-a',
                    'domaintype' => $name,
                ])
            )
        );

        foreach ($lists['GAME-A'] as $domain) {
            self::assertSame($expectedType, $domain['type']);
        }
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function domainTypes(): array {
        return [
            'suffix' => ['suffix', GeositeDatWriter::TYPE_ROOT_DOMAIN],
            'full' => ['full', GeositeDatWriter::TYPE_FULL],
            'keyword' => ['keyword', GeositeDatWriter::TYPE_PLAIN],
            'regex' => ['regex', GeositeDatWriter::TYPE_REGEX],
        ];
    }

    public function testOutputIsDeterministic(): void {
        $first = $this->body($this->get('/', ['format' => 'geosite', 'data' => 'domains']));
        $second = $this->body($this->get('/', ['format' => 'geosite', 'data' => 'domains']));

        self::assertSame($first, $second);
        self::assertNotSame('', $first);
    }

    /**
     * Оба пути кодирования (inline с уступкой loop'у и процесс-воркер) обязаны
     * давать байт-в-байт одно и то же — иначе выдача зависела бы от порога.
     */
    public function testWorkerPathProducesIdenticalBytes(): void {
        $previous = $_ENV['SYS_ENCODE_WORKER_THRESHOLD'] ?? null;

        try {
            $_ENV['SYS_ENCODE_WORKER_THRESHOLD'] = '1000000'; // всегда inline
            $inline = $this->body($this->get('/', ['format' => 'geosite', 'data' => 'domains']));

            $_ENV['SYS_ENCODE_WORKER_THRESHOLD'] = '0'; // всегда воркер
            $worker = $this->body($this->get('/', ['format' => 'geosite', 'data' => 'domains']));

            self::assertNotSame('', $inline);
            self::assertSame(bin2hex($inline), bin2hex($worker));
        } finally {
            if ($previous === null) {
                unset($_ENV['SYS_ENCODE_WORKER_THRESHOLD']);
            } else {
                $_ENV['SYS_ENCODE_WORKER_THRESHOLD'] = $previous;
            }
        }
    }

    public function testEmptyResultIsValidEmptyDat(): void {
        $body = $this->body($this->get('/', ['format' => 'geosite', 'data' => 'domains', 'site' => 'does-not-exist']));

        self::assertSame('', $body);
    }

    // --- мини-парсер protobuf -------------------------------------------------

    /**
     * GeoSiteList → ['CODE' => [['type' => int, 'value' => string], …]]
     *
     * @return array<string, array<int, array{type: int, value: string}>>
     */
    private function decode(string $binary): array {
        $lists = [];
        foreach (self::fields($binary) as [$field, $wire, $value]) {
            if ($field !== 1 || $wire !== ProtobufWire::LENGTH) {
                continue;
            }

            $code = '';
            $domains = [];
            foreach (self::fields((string) $value) as [$siteField, $siteWire, $siteValue]) {
                if ($siteField === 1 && $siteWire === ProtobufWire::LENGTH) {
                    $code = (string) $siteValue;
                } elseif ($siteField === 2 && $siteWire === ProtobufWire::LENGTH) {
                    $domains[] = self::decodeDomain((string) $siteValue);
                }
            }
            $lists[$code] = $domains;
        }

        return $lists;
    }

    /**
     * @return array{type: int, value: string}
     */
    private static function decodeDomain(string $binary): array {
        $type = GeositeDatWriter::TYPE_PLAIN; // поле опущено ⇒ значение по умолчанию
        $value = '';
        foreach (self::fields($binary) as [$field, $wire, $raw]) {
            if ($field === 1 && $wire === ProtobufWire::VARINT) {
                $type = (int) $raw;
            } elseif ($field === 2 && $wire === ProtobufWire::LENGTH) {
                $value = (string) $raw;
            }
        }

        return ['type' => $type, 'value' => $value];
    }

    /**
     * @return array<int, array{0: int, 1: int, 2: int|string}>
     */
    private static function fields(string $binary): array {
        $fields = [];
        $position = 0;
        $length = strlen($binary);

        while ($position < $length) {
            [$tag, $position] = self::varint($binary, $position);
            $field = $tag >> 3;
            $wire = $tag & 0x07;

            if ($wire === ProtobufWire::VARINT) {
                [$value, $position] = self::varint($binary, $position);
                $fields[] = [$field, $wire, $value];
            } elseif ($wire === ProtobufWire::LENGTH) {
                [$size, $position] = self::varint($binary, $position);
                $fields[] = [$field, $wire, substr($binary, $position, $size)];
                $position += $size;
            } else {
                throw new RuntimeException('Unsupported protobuf wire type ' . $wire);
            }
        }

        return $fields;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function varint(string $binary, int $position): array {
        $value = 0;
        $shift = 0;

        while (true) {
            if (!isset($binary[$position])) {
                throw new RuntimeException('Truncated varint at offset ' . $position);
            }
            $byte = ord($binary[$position++]);
            $value |= ($byte & 0x7f) << $shift;
            if (($byte & 0x80) === 0) {
                break;
            }
            $shift += 7;
        }

        return [$value, $position];
    }
}

/**
 * Локальные константы wire type — чтобы разбор в тесте читался, но не тянул
 * зависимость на продовый ProtobufWriter (тест должен проверять его, а не
 * повторять его же определения).
 */
final class ProtobufWire {
    public const VARINT = 0;
    public const LENGTH = 2;
}
