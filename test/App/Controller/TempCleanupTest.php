<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\AsyncTest;
use OpenCCK\Infrastructure\Storage\TempWorkspace;

/**
 * Инвариант: после запроса, который шеллит внешний бинарник, в storage/tmp не
 * остаётся ничего — ни при успехе, ни при провале генерации.
 *
 * Считаем не общее число записей, а только каталоги с префиксом `geoip-`:
 * тест не должен зависеть ни от чужих воркспейсов, ни от подметальщика,
 * который висит на таймере в Server::startTempSweeper().
 *
 * Проверки состояния диска — обычными is_dir/scandir, а не `Amp\File`:
 * без ext-uv/ext-eio `Amp\File` уходит в пул процессов-воркеров, и поднятый
 * ради ассертов пул потом мешает остальным тестам в этом же процессе.
 */
final class TempCleanupTest extends AsyncTest {
    private ?string $previousEngine = null;

    /**
     * Временные файлы создаёт только путь через внешнюю утилиту, поэтому здесь
     * он включается принудительно: по умолчанию geoip.dat собирается нативно и
     * на диск ничего не пишет (см. ROADMAP §7).
     */
    protected function setUp(): void {
        parent::setUp();
        $this->previousEngine = $_ENV['SYS_GEOIP_NATIVE'] ?? null;
        $_ENV['SYS_GEOIP_NATIVE'] = 'false';
    }

    protected function tearDown(): void {
        if ($this->previousEngine === null) {
            unset($_ENV['SYS_GEOIP_NATIVE']);
        } else {
            $_ENV['SYS_GEOIP_NATIVE'] = $this->previousEngine;
        }

        parent::tearDown();
    }

    /**
     * @return array<int, string>
     */
    private function workspaces(string $prefix): array {
        $base = TempWorkspace::basePath();
        if (!is_dir($base)) {
            return [];
        }
        clearstatcache();
        $entries = scandir($base) ?: [];

        return array_values(array_filter($entries, fn(string $e) => str_starts_with($e, $prefix . '-')));
    }

    public function testGeoipRequestLeavesNoWorkspace(): void {
        // Бинарника в фикстурах нет, поэтому запрос заведомо падает на
        // Process::start — именно этот путь и должен убирать за собой.
        $body = $this->body($this->get('/', ['format' => 'geoip', 'data' => 'cidr4']));

        self::assertStringStartsWith('# Error:', $body);
        self::assertSame([], $this->workspaces('geoip'));
    }

    public function testGeoipRequestWithMissingBinaryDirLeavesNoWorkspace(): void {
        $previous = $_ENV['GEOIP_PATH'] ?? null;
        $_ENV['GEOIP_PATH'] = PATH_ROOT . '/definitely-missing-bin/';

        try {
            $body = $this->body($this->get('/', ['format' => 'geoip', 'data' => 'cidr4']));

            self::assertStringStartsWith('# Error:', $body);
            self::assertSame([], $this->workspaces('geoip'));
        } finally {
            if ($previous === null) {
                unset($_ENV['GEOIP_PATH']);
            } else {
                $_ENV['GEOIP_PATH'] = $previous;
            }
        }
    }

    public function testInvalidDataRequestCreatesNoWorkspace(): void {
        $this->body($this->get('/', ['format' => 'geoip', 'data' => 'domains']));

        self::assertSame([], $this->workspaces('geoip'));
    }

    public function testDestroyIsRecursiveAndIdempotent(): void {
        $workspace = TempWorkspace::create('test-nested');
        $workspace->write('input/deep/payload.txt', 'data');

        self::assertFileExists($workspace->path('input/deep/payload.txt'));

        $workspace->destroy();
        $workspace->destroy();

        self::assertDirectoryDoesNotExist($workspace->path());
    }

    public function testSweepKeepsFreshWorkspacesAndRemovesExpiredOnes(): void {
        $workspace = TempWorkspace::create('test-sweep');
        $workspace->write('payload.txt', 'data');

        try {
            // TTL заведомо больше возраста только что созданного каталога
            TempWorkspace::sweep(3600);
            self::assertDirectoryExists($workspace->path(), 'свежий воркспейс не должен подметаться');

            // TTL = 0 — просрочено всё, включая только что созданное
            self::assertGreaterThanOrEqual(1, TempWorkspace::sweep(0));
            self::assertDirectoryDoesNotExist($workspace->path());
        } finally {
            $workspace->destroy();
        }
    }
}
