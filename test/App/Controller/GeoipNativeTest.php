<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\AsyncTest;
use RuntimeException;

/**
 * HTTP-контракт `?format=geoip` на нативном движке (`SYS_GEOIP_NATIVE=true`).
 *
 * Тело разбирается мини-парсером protobuf прямо в тесте — тем же алгоритмом,
 * которым проверена читаемость официального `geoip.dat` от v2fly (см. ROADMAP §17).
 */
final class GeoipNativeTest extends AsyncTest {
    /**
     * @param array<string, string|int|array<int, string|int>> $query
     * @return array<string, array<int, array{ip: string, prefix: int}>>
     */
    private function lists(array $query): array {
        return $this->decode($this->body($this->get('/', array_merge(['format' => 'geoip'], $query))));
    }

    public function testCidr4ListsAreTaggedBySiteAndGroup(): void {
        $lists = $this->lists(['data' => 'cidr4']);

        self::assertArrayHasKey('GAME-A', $lists);
        self::assertArrayHasKey('GAMES', $lists);
        self::assertArrayHasKey('TOOLS', $lists);

        foreach ($lists['GAME-A'] as $entry) {
            self::assertSame(4, strlen(inet_pton($entry['ip'])), 'IPv4-адрес должен быть 4 байта');
            self::assertGreaterThan(0, $entry['prefix']);
            self::assertLessThanOrEqual(32, $entry['prefix']);
        }
    }

    public function testIp4RowsGetHostMask(): void {
        $lists = $this->lists(['data' => 'ip4', 'site' => 'game-a']);

        self::assertNotEmpty($lists['GAME-A']);
        foreach ($lists['GAME-A'] as $entry) {
            self::assertSame(32, $entry['prefix']);
        }
    }

    public function testCidr6UsesSixteenByteAddresses(): void {
        $lists = $this->lists(['data' => 'cidr6', 'site' => 'mock-google']);

        self::assertNotEmpty($lists['MOCK-GOOGLE']);
        foreach ($lists['MOCK-GOOGLE'] as $entry) {
            self::assertSame(16, strlen(inet_pton($entry['ip'])));
        }
    }

    public function testIp6RowsGetHostMask(): void {
        $lists = $this->lists(['data' => 'ip6', 'site' => 'mock-google']);

        foreach ($lists['MOCK-GOOGLE'] as $entry) {
            self::assertSame(128, $entry['prefix']);
        }
    }

    /**
     * Замена CIDR должна работать так же, как в остальных форматах.
     */
    public function testReplaceIsApplied(): void {
        $entries = $this->lists(['data' => 'cidr4', 'site' => 'mock-google'])['MOCK-GOOGLE'];
        $rows = array_map(fn(array $e) => $e['ip'] . '/' . $e['prefix'], $entries);

        self::assertNotContains('172.217.0.0/16', $rows);
        self::assertContains('172.217.17.206/32', $rows);
        self::assertContains('172.217.17.207/32', $rows);
    }

    public function testNativeReturnsRawCidr(): void {
        $entries = $this->lists(['data' => 'cidr4', 'site' => 'mock-google', 'native' => 1])['MOCK-GOOGLE'];
        $rows = array_map(fn(array $e) => $e['ip'] . '/' . $e['prefix'], $entries);

        self::assertContains('172.217.0.0/16', $rows);
        self::assertNotContains('172.217.17.206/32', $rows);
    }

    public function testExcludeGroupRemovesLists(): void {
        $lists = $this->lists(['data' => 'cidr4', 'exclude[group]' => 'games']);

        self::assertArrayNotHasKey('GAMES', $lists);
        self::assertArrayNotHasKey('GAME-A', $lists);
        self::assertArrayHasKey('TOOLS', $lists);
    }

    public function testHeadersAndDeterminism(): void {
        $response = $this->get('/', ['format' => 'geoip', 'data' => 'cidr4']);
        $body = $this->body($response);

        self::assertSame(200, $response->getStatus());
        self::assertSame('application/octet-stream', $response->getHeader('content-type'));
        self::assertStringContainsString('iplist.dat', $response->getHeader('content-disposition') ?? '');
        self::assertSame((string) strlen($body), $response->getHeader('content-length'));

        $again = $this->body($this->get('/', ['format' => 'geoip', 'data' => 'cidr4']));
        self::assertSame(bin2hex($body), bin2hex($again));
    }

    public function testEmptyResultIsEmptyDat(): void {
        self::assertSame(
            '',
            $this->body($this->get('/', ['format' => 'geoip', 'data' => 'cidr4', 'site' => 'does-not-exist']))
        );
    }

    /**
     * Оба пути кодирования обязаны давать байт-в-байт одно и то же.
     */
    public function testWorkerPathProducesIdenticalBytes(): void {
        $previous = $_ENV['SYS_ENCODE_WORKER_THRESHOLD'] ?? null;

        try {
            $_ENV['SYS_ENCODE_WORKER_THRESHOLD'] = '1000000'; // всегда inline
            $inline = $this->body($this->get('/', ['format' => 'geoip', 'data' => 'cidr4']));

            $_ENV['SYS_ENCODE_WORKER_THRESHOLD'] = '0'; // всегда воркер
            $worker = $this->body($this->get('/', ['format' => 'geoip', 'data' => 'cidr4']));

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

    /**
     * При SYS_GEOIP_NATIVE=false включается прежний путь через внешнюю утилиту:
     * бинарника в фикстурах нет, поэтому ожидаем ошибку — и чистый storage/tmp.
     */
    public function testBinaryEngineStillReachable(): void {
        $previous = $_ENV['SYS_GEOIP_NATIVE'] ?? null;
        $_ENV['SYS_GEOIP_NATIVE'] = 'false';

        try {
            $body = $this->body($this->get('/', ['format' => 'geoip', 'data' => 'cidr4']));
            self::assertStringStartsWith('# Error:', $body);
        } finally {
            if ($previous === null) {
                unset($_ENV['SYS_GEOIP_NATIVE']);
            } else {
                $_ENV['SYS_GEOIP_NATIVE'] = $previous;
            }
        }
    }

    // --- мини-парсер protobuf -------------------------------------------------

    /**
     * GeoIPList → ['CODE' => [['ip' => '1.2.3.0', 'prefix' => 24], …]]
     *
     * @return array<string, array<int, array{ip: string, prefix: int}>>
     */
    private function decode(string $binary): array {
        $lists = [];
        foreach (self::fields($binary) as [$field, $wire, $value]) {
            if ($field !== 1 || $wire !== 2) {
                continue;
            }

            $code = '';
            $cidrs = [];
            foreach (self::fields((string) $value) as [$entryField, $entryWire, $entryValue]) {
                if ($entryField === 1 && $entryWire === 2) {
                    $code = (string) $entryValue;
                } elseif ($entryField === 2 && $entryWire === 2) {
                    $cidrs[] = self::decodeCidr((string) $entryValue);
                }
            }
            $lists[$code] = $cidrs;
        }

        return $lists;
    }

    /**
     * @return array{ip: string, prefix: int}
     */
    private static function decodeCidr(string $binary): array {
        $ip = '';
        $prefix = 0; // поле опущено ⇒ значение по умолчанию
        foreach (self::fields($binary) as [$field, $wire, $value]) {
            if ($field === 1 && $wire === 2) {
                $ip = inet_ntop((string) $value);
            } elseif ($field === 2 && $wire === 0) {
                $prefix = (int) $value;
            }
        }

        return ['ip' => $ip, 'prefix' => $prefix];
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

            if ($wire === 0) {
                [$value, $position] = self::varint($binary, $position);
                $fields[] = [$field, $wire, $value];
            } elseif ($wire === 2) {
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
