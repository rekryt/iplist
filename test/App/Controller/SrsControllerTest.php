<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\AsyncTest;
use OpenCCK\Domain\Helper\IP4Helper;
use OpenCCK\Infrastructure\Process\ProcessRunner;
use OpenCCK\Infrastructure\Storage\TempWorkspace;

/**
 * HTTP-контракт `?format=srs` — бинарный sing-box rule-set.
 *
 * Контракты ошибок и инвариант «после запроса в storage/tmp пусто» проверяются
 * всегда; тесты, которым нужен настоящий бинарник, скипаются, если его нет
 * (тот же приём, что в GeoipControllerTest).
 *
 * Путь к бинарнику ищется в том числе в `$_SERVER`: под PHPUnit переменные,
 * унаследованные из шелла, видны только там (variables_order=GPCS, без E),
 * поэтому `SINGBOX_PATH=... vendor/bin/phpunit` иначе бы не работал.
 */
final class SrsControllerTest extends AsyncTest {
    private const MAGIC = 'SRS';

    private ?string $previousPath = null;
    private bool $pathOverridden = false;

    private function singboxBinary(): ?string {
        $candidates = array_filter([
            $_ENV['SINGBOX_PATH'] ?? null,
            $_SERVER['SINGBOX_PATH'] ?? null,
            '/usr/local/bin/sing-box',
            '/usr/bin/sing-box',
        ]);

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    protected function setUp(): void {
        parent::setUp();

        // Контроллер читает путь через OpenCCK\getEnv() (то есть из $_ENV),
        // поэтому прокидываем найденный бинарник туда
        $binary = $this->singboxBinary();
        if ($binary !== null && ($_ENV['SINGBOX_PATH'] ?? null) !== $binary) {
            $this->previousPath = $_ENV['SINGBOX_PATH'] ?? null;
            $this->pathOverridden = true;
            $_ENV['SINGBOX_PATH'] = $binary;
        }
    }

    protected function tearDown(): void {
        if ($this->pathOverridden) {
            if ($this->previousPath === null) {
                unset($_ENV['SINGBOX_PATH']);
            } else {
                $_ENV['SINGBOX_PATH'] = $this->previousPath;
            }
            $this->pathOverridden = false;
        }

        parent::tearDown();
    }

    private function requireBinary(): string {
        $binary = $this->singboxBinary();
        if ($binary === null) {
            self::markTestSkipped('sing-box binary not available — skipping integration test');
        }

        return $binary;
    }

    /**
     * @return array<int, string>
     */
    private function workspaces(): array {
        $base = TempWorkspace::basePath();
        if (!is_dir($base)) {
            return [];
        }
        clearstatcache();

        return array_values(array_filter(scandir($base) ?: [], fn(string $e) => str_starts_with($e, 'srs-')));
    }

    // --- контракты, не требующие бинарника ------------------------------------

    public function testMissingDataReturnsError(): void {
        self::assertStringContainsString(
            "'data' GET parameter is required",
            $this->body($this->get('/', ['format' => 'srs']))
        );
    }

    public function testInvalidDataReturnsError(): void {
        self::assertStringContainsString(
            "'data' GET parameter must be",
            $this->body($this->get('/', ['format' => 'srs', 'data' => 'nope']))
        );
    }

    public function testInvalidVersionReturnsError(): void {
        self::assertStringContainsString(
            "'version' GET parameter must be an integer",
            $this->body($this->get('/', ['format' => 'srs', 'data' => 'cidr4', 'version' => '9']))
        );
    }

    public function testMissingBinaryReturnsPlainTextError(): void {
        $previous = $_ENV['SINGBOX_PATH'] ?? null;
        $_ENV['SINGBOX_PATH'] = PATH_ROOT . '/definitely-missing-sing-box';

        try {
            $response = $this->get('/', ['format' => 'srs', 'data' => 'cidr4']);
            $body = $this->body($response);

            self::assertStringContainsString('sing-box binary is not available', $body);
            // Текст ошибки не должен уезжать под заголовками бинарного вложения
            self::assertStringContainsString('text/plain', $response->getHeader('content-type') ?? '');
            self::assertStringNotContainsString('rule-set.srs', $response->getHeader('content-disposition') ?? '');
            self::assertSame([], $this->workspaces());
        } finally {
            if ($previous === null) {
                unset($_ENV['SINGBOX_PATH']);
            } else {
                $_ENV['SINGBOX_PATH'] = $previous;
            }
        }
    }

    // --- интеграционные, требуют бинарник ------------------------------------

    public function testProducesBinaryRuleSetForCidr4(): void {
        $this->requireBinary();

        $response = $this->get('/', ['format' => 'srs', 'data' => 'cidr4']);
        $body = $this->body($response);

        self::assertSame(200, $response->getStatus());
        self::assertSame('application/octet-stream', $response->getHeader('content-type'));
        self::assertStringContainsString('rule-set.srs', $response->getHeader('content-disposition') ?? '');
        self::assertSame((string) strlen($body), $response->getHeader('content-length'));

        self::assertSame(self::MAGIC, substr($body, 0, 3), 'первые три байта должны быть магией SRS');
        self::assertSame(1, ord($body[3]), 'четвёртый байт — версия формата, по умолчанию 1');
        self::assertSame([], $this->workspaces(), 'воркспейс должен быть убран после ответа');
    }

    /**
     * `version` в source-JSON — это верхняя граница, а не точное значение:
     * `rule-set compile` пишет минимальную версию бинаря, достаточную для
     * использованных элементов правила. Проверено на 1.13.15: source v1 → v1,
     * v2 → v2, v3 → v2, v4 → v2 (для `ip_cidr` больше второй не нужно).
     * Значимый для совместимости инвариант — версия никогда не выше запрошенной.
     */
    public function testVersionByteNeverExceedsRequestedVersion(): void {
        $this->requireBinary();

        foreach ([1, 2, 3, 4] as $version) {
            $body = $this->body($this->get('/', ['format' => 'srs', 'data' => 'cidr4', 'version' => $version]));

            self::assertSame(self::MAGIC, substr($body, 0, 3), 'version=' . $version);
            self::assertLessThanOrEqual($version, ord($body[3]), 'version=' . $version);
            self::assertGreaterThanOrEqual(1, ord($body[3]), 'version=' . $version);
        }
    }

    public function testProducesBinaryRuleSetForDomains(): void {
        $this->requireBinary();

        $body = $this->body($this->get('/', ['format' => 'srs', 'data' => 'domains', 'site' => 'game-a']));

        self::assertSame(self::MAGIC, substr($body, 0, 3));
        self::assertGreaterThan(4, strlen($body));
        self::assertSame([], $this->workspaces());
    }

    /**
     * Обратная проверка настоящим sing-box: декомпилируем нашу выдачу и
     * сверяем **множество адресов**, а не строки CIDR.
     *
     * Строки сравнивать нельзя: IPSet внутри `.srs` хранит диапазоны, поэтому
     * при декомпиляции `172.217.17.206/32` + `172.217.17.207/32` схлопываются
     * в `172.217.17.206/31` — то же множество адресов в другой записи.
     */
    public function testDecompileRoundTripCoversSameAddresses(): void {
        $binary = $this->requireBinary();

        $workspace = TempWorkspace::create('test-srs-roundtrip');
        try {
            $srs = $this->body($this->get('/', ['format' => 'srs', 'data' => 'cidr4', 'site' => 'mock-google']));
            $workspace->write('rule-set.srs', $srs);

            $result = ProcessRunner::run([
                $binary,
                'rule-set',
                'decompile',
                '--output',
                $workspace->path('back.json'),
                $workspace->path('rule-set.srs'),
            ]);
            self::assertSame(0, $result['code'], 'decompile: ' . ProcessRunner::errorOutput($result));

            $decompiled = json_decode($workspace->read('back.json'), true, 512, JSON_THROW_ON_ERROR);
            // sing-box маршалит одноэлементные списки скаляром (Listable),
            // поэтому ip_cidr на выходе может быть строкой, а не массивом
            $cidrs = (array) $decompiled['rules'][0]['ip_cidr'];

            // Значения из replace дошли до бинарного формата
            self::assertTrue(IP4Helper::isInRange('172.217.17.206', $cidrs));
            self::assertTrue(IP4Helper::isInRange('172.217.17.207', $cidrs));
            // Родительская зона /16 заменена, а не добавлена
            self::assertFalse(IP4Helper::isInRange('172.217.0.1', $cidrs));
            self::assertFalse(IP4Helper::isInRange('172.217.17.208', $cidrs));
        } finally {
            $workspace->destroy();
        }
    }

    /**
     * Семантику проверяем не своим парсером, а самим sing-box: домен и его
     * поддомен матчатся, а похожий домен без границы метки — нет.
     */
    public function testMatchSemanticsOfProducedBinary(): void {
        $binary = $this->requireBinary();

        $workspace = TempWorkspace::create('test-srs-match');
        try {
            $srs = $this->body($this->get('/', ['format' => 'srs', 'data' => 'domains', 'site' => 'game-a']));
            $path = $workspace->write('rule-set.srs', $srs);

            $matches = function (string $domain) use ($binary, $path): bool {
                $result = ProcessRunner::run([$binary, 'rule-set', 'match', '-f', 'binary', $path, $domain]);
                self::assertSame(0, $result['code'], 'match exit code for ' . $domain);

                // sing-box печатает результат match в stderr, а не в stdout
                return str_contains($result['stderr'] . $result['stdout'], 'match rules.');
            };

            self::assertTrue($matches('game-a.com'), 'точное совпадение');
            self::assertTrue($matches('sub.game-a.com'), 'поддомен');
            self::assertFalse($matches('notgame-a.com'), 'граница метки: похожий домен не должен матчиться');
            self::assertFalse($matches('example.org'), 'посторонний домен');
        } finally {
            $workspace->destroy();
        }
    }

    public function testEmptyResultStillCompiles(): void {
        $this->requireBinary();

        $body = $this->body($this->get('/', ['format' => 'srs', 'data' => 'domains', 'site' => 'does-not-exist']));

        self::assertSame(self::MAGIC, substr($body, 0, 3));
        self::assertSame([], $this->workspaces());
    }
}
