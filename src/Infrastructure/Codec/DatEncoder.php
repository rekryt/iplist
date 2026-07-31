<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Codec;

use OpenCCK\Infrastructure\API\App;
use OpenCCK\Infrastructure\Task\EncodeDatTask;
use Throwable;

use function Amp\delay;
use function Amp\Parallel\Worker\submit;
use function OpenCCK\getEnv;

/**
 * Выбор способа кодирования `.dat`: в процессе-воркере или на месте.
 *
 * Порог нужен потому, что у воркера есть постоянная цена (serialize аргументов,
 * IPC, первый запуск процесса), которая на выдаче в пару тысяч записей дороже
 * самого кодирования. Замер на полном каталоге (409 порталов, 318 756 записей):
 * `encode()` ~870 мс против ~5 мс на `serialize()` payload'а — там выигрыш
 * воркера безусловный, поэтому дефолт порога стоит существенно ниже.
 *
 * Inline-путь не просто «считает в лоб»: каждые YIELD_EVERY списков он отдаёт
 * управление event loop'у через `delay(0)`, чтобы соседние запросы не ждали
 * всю генерацию целиком.
 */
final class DatEncoder {
    /** Порог по числу записей, выше которого кодируем в воркере */
    public const DEFAULT_WORKER_THRESHOLD = 20000;

    /** Как часто inline-путь уступает управление event loop'у (в списках) */
    private const YIELD_EVERY = 32;

    public static function encode(string $kind, string $payload, int $itemCount, int $domainType = 0): string {
        $threshold = max(0, (int) (getEnv('SYS_ENCODE_WORKER_THRESHOLD') ?? self::DEFAULT_WORKER_THRESHOLD));

        if ($threshold > 0 && $itemCount < $threshold) {
            return self::inline($kind, $payload, $domainType);
        }

        try {
            return (string) submit(new EncodeDatTask($kind, $payload, $domainType))->await();
        } catch (Throwable $e) {
            // Воркер может не подняться (нет прав на запуск процессов, кончились
            // дескрипторы) — это не повод отдавать 500 на read-only выдачу
            App::getLogger()->warning('Dat encoding in worker failed, falling back to inline', [
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);

            return self::inline($kind, $payload, $domainType);
        }
    }

    private static function inline(string $kind, string $payload, int $domainType): string {
        $counter = 0;
        $yield = function () use (&$counter): void {
            if (++$counter % self::YIELD_EVERY === 0) {
                delay(0);
            }
        };

        return match ($kind) {
            EncodeDatTask::KIND_GEOIP => GeoipDatWriter::encode($payload, $yield),
            default => GeositeDatWriter::encode($payload, $domainType, $yield),
        };
    }
}
