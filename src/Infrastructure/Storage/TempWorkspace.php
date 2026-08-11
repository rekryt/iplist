<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Storage;

use OpenCCK\Infrastructure\API\App;
use RuntimeException;
use Throwable;

use function OpenCCK\getEnv;

/**
 * Изолированный каталог под временные файлы одного запроса.
 *
 * Зачем отдельный каталог на запрос, а не общий `input/`+`output/` с
 * `microtime()` в имени файла: параллельные запросы больше не пересекаются
 * по путям, поэтому уборка сводится к удалению одного дерева и не может
 * задеть файлы соседнего запроса. Плюс промах по уникальности имени
 * (два запроса в одну микросекунду) становится невозможен — суффикс берётся
 * из `random_bytes`.
 *
 * Тело ответа в проекте буферизуется в строку (`AbstractController::__invoke`
 * считает `strlen($body)`), поэтому «файл отдан пользователю» наступает сразу
 * после `read()`. Правильное место уборки — `finally` вокруг генерации,
 * без дефера через EventLoop и без гонок с записью ответа.
 *
 * Почему синхронные mkdir/file_put_contents/unlink, а не `Amp\File`:
 * в образе нет `ext-uv`/`ext-eio`, поэтому `Amp\File` работает через
 * `ParallelFilesystemDriver`, т.е. каждая операция — IPC-раундтрип в
 * отдельный процесс-воркер. Замер на 409 файлах (типичный geoip-запрос по
 * всему каталогу): sync — 139 мс на запись и 81 мс на рекурсивное удаление;
 * `Amp\File` — 0.68 мс/файл на запись и 0.46 мс/файл на удаление, т.е. ~466 мс
 * на то же плюс разовый спавн пула воркеров. Здесь речь о сотнях мелких
 * операций с метаданными на локальном диске: воркеры дают проигрыш и по
 * времени, и по числу процессов, а сам проект уже пишет так же
 * (`Site::saveConfig`, `CIDRStorage::save`). Сознательный компромисс:
 * платим блокировкой loop'а на ~0.2 с в редком geoip-запросе (после перехода
 * на нативный writer файлов не будет вовсе), а для `srs` это 2-3 файла.
 */
final class TempWorkspace {
    private bool $destroyed = false;

    private function __construct(private readonly string $root) {
    }

    /**
     * Базовый каталог всех воркспейсов. `storage/` уже смонтирован volume'ом
     * в docker-compose, поэтому по умолчанию живём внутри него.
     */
    public static function basePath(): string {
        $path = getEnv('SYS_TMP_PATH') ?: PATH_ROOT . '/storage/tmp';
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * @throws RuntimeException
     */
    public static function create(string $prefix): self {
        $root = self::basePath() . '/' . $prefix . '-' . bin2hex(random_bytes(8));
        self::makeDirectory($root);

        return new self($root);
    }

    public function path(string $relative = ''): string {
        return $relative === '' ? $this->root : $this->root . '/' . ltrim($relative, '/');
    }

    /**
     * Записывает файл внутри воркспейса, создавая промежуточные каталоги.
     *
     * @return string абсолютный путь записанного файла
     * @throws RuntimeException
     */
    public function write(string $relative, string $contents): string {
        $path = $this->path($relative);
        self::makeDirectory(str_replace('\\', '/', dirname($path)));

        if (@file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Failed to write temporary file ' . $path);
        }

        return $path;
    }

    /**
     * @return string абсолютный путь созданного каталога
     * @throws RuntimeException
     */
    public function createDirectory(string $relative): string {
        $path = $this->path($relative);
        self::makeDirectory($path);

        return $path;
    }

    /**
     * @throws RuntimeException
     */
    public function read(string $relative): string {
        $path = $this->path($relative);
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Failed to read temporary file ' . $path);
        }

        return $contents;
    }

    /**
     * Идемпотентно удаляет воркспейс целиком. Никогда не бросает: уборка не
     * должна превращать успешный ответ в 500 — проблему достаточно залогировать.
     */
    public function destroy(): void {
        if ($this->destroyed) {
            return;
        }
        $this->destroyed = true;

        try {
            self::deleteRecursively($this->root);
        } catch (Throwable $e) {
            App::getLogger()->warning('TempWorkspace cleanup failed', [
                'path' => $this->root,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Подметает воркспейсы, осиротевшие после SIGKILL/падения процесса:
     * удаляет каталоги, у которых mtime старше $ttlSeconds. TTL заведомо
     * больше времени любой генерации, поэтому живой воркспейс не заденет.
     *
     * @return int сколько каталогов удалено
     */
    public static function sweep(int $ttlSeconds): int {
        $base = self::basePath();
        if (!is_dir($base)) {
            return 0;
        }

        clearstatcache();
        $deadline = time() - max(0, $ttlSeconds);
        $removed = 0;

        foreach (self::listEntries($base) as $entry) {
            $path = $base . '/' . $entry;
            try {
                if (!is_dir($path)) {
                    continue;
                }
                $mtime = @filemtime($path);
                if ($mtime === false || $mtime > $deadline) {
                    continue;
                }
                self::deleteRecursively($path);
                $removed++;
            } catch (Throwable $e) {
                App::getLogger()->warning('TempWorkspace sweep skipped entry', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($removed > 0) {
            App::getLogger()->notice('Orphaned temp workspaces removed', [$removed . ' items']);
        }

        return $removed;
    }

    /**
     * @throws RuntimeException
     */
    private static function makeDirectory(string $path): void {
        // Проверка после mkdir тоже нужна: между is_dir и mkdir каталог мог
        // создать параллельный запрос — это не ошибка.
        if (is_dir($path)) {
            return;
        }
        if (!@mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException('Failed to create temporary directory ' . $path);
        }
    }

    /**
     * @return array<int, string>
     */
    private static function listEntries(string $path): array {
        $entries = @scandir($path);
        if ($entries === false) {
            return [];
        }

        return array_values(array_filter($entries, fn(string $e) => $e !== '.' && $e !== '..'));
    }

    /**
     * @throws RuntimeException
     */
    private static function deleteRecursively(string $path): void {
        if (is_link($path) || is_file($path)) {
            if (!@unlink($path)) {
                throw new RuntimeException('Failed to delete temporary file ' . $path);
            }
            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (self::listEntries($path) as $entry) {
            self::deleteRecursively($path . '/' . $entry);
        }

        if (!@rmdir($path)) {
            throw new RuntimeException('Failed to delete temporary directory ' . $path);
        }
    }
}
