<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\Infrastructure\Process\ProcessRunner;
use OpenCCK\Infrastructure\Storage\TempWorkspace;
use Throwable;

use function OpenCCK\getEnv;

/**
 * sing-box rule-set в бинарном формате `.srs`.
 *
 * Наследуется от SingboxController: правило, валидация параметров и фильтры —
 * те же, отличается только последний шаг. Source-JSON компилируется штатной
 * командой `sing-box rule-set compile`, а не своим энкодером: доменная часть
 * `.srs` — это succinct-trie (LOUDS), и ошибка в битовой раскладке дала бы
 * файл, который sing-box примет и будет матчить молча неправильно.
 *
 * Версия бинарного формата берётся из поля `version` в source-JSON, поэтому
 * отдельного флага компиляции не нужно: `?version=1..5` работает как для JSON.
 *
 * @see https://sing-box.sagernet.org/configuration/rule-set/
 */
class SrsController extends SingboxController {
    private const SOURCE_NAME = 'source.json';
    private const OUTPUT_NAME = 'rule-set.srs';

    /**
     * @return array<string, string>
     */
    protected function responseHeaders(): array {
        return [
            'content-type' => 'application/octet-stream',
            'content-disposition' => 'attachment; filename="rule-set.srs"',
        ];
    }

    /**
     * @param array<int, string> $response
     * @return string
     */
    protected function render(array $response): string {
        $source = parent::render($response);

        $binary = self::singboxBinary();
        if ($binary === null) {
            $this->renderFailed = true;

            return '# Error: sing-box binary is not available — set SINGBOX_PATH or use format=singbox';
        }

        // Уборка в finally: тело ответа буферизуется в строку
        // (AbstractController::__invoke считает strlen), поэтому к моменту
        // destroy() данные пользователю уже отданы.
        $workspace = null;
        try {
            $workspace = TempWorkspace::create('srs');
            $sourcePath = $workspace->write(self::SOURCE_NAME, $source);
            $outputPath = $workspace->path(self::OUTPUT_NAME);

            $result = ProcessRunner::run([$binary, 'rule-set', 'compile', '--output', $outputPath, $sourcePath]);
            if ($result['code'] !== 0) {
                $this->logger->warning('sing-box rule-set compile failed', $result);
                $this->renderFailed = true;
                $errorOutput = ProcessRunner::errorOutput($result);

                return '# Error: sing-box rule-set compile exited with ' .
                    $result['code'] .
                    ($errorOutput !== '' ? ': ' . $errorOutput : '');
            }

            return $workspace->read(self::OUTPUT_NAME);
        } catch (Throwable $e) {
            $this->renderFailed = true;

            return '# Error: ' . $e->getMessage();
        } finally {
            $workspace?->destroy();
        }
    }

    /**
     * Путь к бинарнику sing-box либо null, если его нет. Явный `SINGBOX_PATH`
     * имеет приоритет, дальше — стандартные места установки (в наш образ
     * бинарник кладётся в /usr/local/bin, см. Dockerfile).
     */
    public static function singboxBinary(): ?string {
        $candidates = array_filter([getEnv('SINGBOX_PATH'), '/usr/local/bin/sing-box', '/usr/bin/sing-box']);

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
