<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Task;

use Amp\Cancellation;
use Amp\Sync\Channel;
use InvalidArgumentException;
use OpenCCK\Infrastructure\Codec\GeoipDatWriter;
use OpenCCK\Infrastructure\Codec\GeositeDatWriter;

/**
 * Кодирование `.dat` в отдельном процессе-воркере (`amphp/parallel`).
 *
 * Даёт то же, что и внешний Go-бинарник — CPU-работа уходит из event loop'а, —
 * но без fork/exec сторонней утилиты и без временных файлов.
 *
 * Ограничения, которые нельзя нарушать:
 * - аргументы и результат проходят через `serialize()`, поэтому payload
 *   передаётся одной плоской строкой (см. GeositeDatWriter::payload());
 * - в воркере нет ни PATH_ROOT, ни синглтонов App/IPListService — задача
 *   работает только с переданными данными.
 */
readonly class EncodeDatTask implements TaskInterface {
    public const KIND_GEOSITE = 'geosite';
    public const KIND_GEOIP = 'geoip';

    public function __construct(
        private string $kind,
        private string $payload,
        private int $domainType = GeositeDatWriter::TYPE_ROOT_DOMAIN
    ) {
    }

    /**
     * @param Channel $channel
     * @param Cancellation $cancellation
     * @return string
     * @throws InvalidArgumentException
     */
    public function run(Channel $channel, Cancellation $cancellation): mixed {
        return match ($this->kind) {
            self::KIND_GEOSITE => GeositeDatWriter::encode($this->payload, $this->domainType),
            self::KIND_GEOIP => GeoipDatWriter::encode($this->payload),
            default => throw new InvalidArgumentException('Unknown encode kind: ' . $this->kind),
        };
    }
}
