<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Process;

use Amp\Process\Process;
use Amp\Process\ProcessException;

use function Amp\async;
use function Amp\ByteStream\buffer;

/**
 * Запуск внешнего бинарника с корректным вычитыванием пайпов.
 *
 * Общий хелпер для генераторов, которым нужен внешний инструмент
 * (`geoip` для geoip.dat, `sing-box rule-set compile` для .srs), чтобы
 * важная тонкость была реализована в одном месте: stdout и stderr нужно
 * читать **конкурентно** с ожиданием кода возврата. Если пайпы не вычитывать,
 * буфер ОС заполняется, дочерний процесс встаёт на `write()`, и `join()`
 * не возвращается никогда — запрос висит до таймаута клиента.
 */
final class ProcessRunner {
    /** Ограничение на длину вывода в теле ошибки */
    public const OUTPUT_LIMIT = 1000;

    /**
     * Команда передаётся массивом, а не строкой: так она не зависит от кавычек
     * и пробелов в путях и не проходит через shell.
     *
     * @param array<int, string> $command
     * @return array{code: int, stdout: string, stderr: string}
     * @throws ProcessException
     */
    public static function run(array $command, ?string $workingDirectory = null): array {
        $process = Process::start($command, $workingDirectory);

        // Amp\ByteStream\buffer(), а не ->buffer(): у ReadableResourceStream,
        // который отдают пайпы процесса, такого метода нет — он есть только у
        // Payload (например, у тела HTTP-ответа).
        $stdout = async(fn() => buffer($process->getStdout()));
        $stderr = async(fn() => buffer($process->getStderr()));

        $code = $process->join();

        return ['code' => $code, 'stdout' => $stdout->await(), 'stderr' => $stderr->await()];
    }

    /**
     * Короткая выжимка вывода процесса для тела ошибки: сначала stderr,
     * если он пуст — stdout.
     *
     * @param array{code: int, stdout: string, stderr: string} $result
     */
    public static function errorOutput(array $result): string {
        return substr(trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']), 0, self::OUTPUT_LIMIT);
    }
}
