<?php

namespace OpenCCK\App\Controller;

use OpenCCK\Domain\Entity\Site;
use OpenCCK\Domain\Factory\SiteFactory;
use OpenCCK\Domain\Helper\IP4Helper;
use OpenCCK\Domain\Helper\IP6Helper;
use OpenCCK\Infrastructure\Codec\DatEncoder;
use OpenCCK\Infrastructure\Codec\GeoipDatWriter;
use OpenCCK\Infrastructure\Process\ProcessRunner;
use OpenCCK\Infrastructure\Storage\TempWorkspace;
use OpenCCK\Infrastructure\Task\EncodeDatTask;
use Throwable;

use function OpenCCK\getEnv;

/**
 * v2ray/xray `geoip.dat`.
 *
 * По умолчанию файл собирается на месте (`GeoipDatWriter`) — без временных
 * файлов и без fork/exec: на полном каталоге прежний путь писал ~409 входных
 * файлов и запускал Go-утилиту на каждый запрос. Путь через внешний бинарник
 * остаётся под `SYS_GEOIP_NATIVE=false` как страховка на один релиз.
 *
 * @see https://github.com/v2fly/geoip/blob/HEAD/configuration.md
 */
class GeoipController extends AbstractIPListController {
    /** Имя выходного файла внутри воркспейса запроса. */
    private const OUTPUT_NAME = 'iplist.dat';

    /**
     * @return string
     */
    public function getBody(): string {
        $this->setHeaders(['content-type' => 'text/plain']);

        $sites = SiteFactory::normalizeArray($this->request->getQueryParameters()['site'] ?? []);
        $data = $this->request->getQueryParameter('data') ?? '';
        if ($data == '') {
            return "# Error: The 'data' GET parameter is required in the URL to access this page";
        }
        if (!in_array($data, ['ip4', 'cidr4', 'ip6', 'cidr6'])) {
            return "# Error: The 'data' GET parameter must be 'ip4', 'cidr4', 'ip6' or 'cidr6'";
        }

        $response = [];
        if (count($sites)) {
            $items = $this->getSites();
            foreach ($sites as $site) {
                if (!isset($items[$site])) {
                    continue;
                }
                $response[$site . '/' . $items[$site]->group] = $this->siteRows($items[$site], $data);
            }
        } else {
            foreach ($this->getSites() as $siteEntity) {
                $response[$siteEntity->name . '/' . $siteEntity->group] = $this->siteRows($siteEntity, $data);
            }
        }

        if ((getEnv('SYS_GEOIP_NATIVE') ?? 'true') === 'true') {
            return $this->renderNative($response);
        }

        return $this->renderWithBinary($response);
    }

    /**
     * Нативная сборка: те же данные, что уходили в Go-утилиту, только
     * кодируются на месте или в процессе-воркере (по объёму выдачи).
     *
     * @param array<string, array<int, string>> $response ключ — `сайт/группа`
     */
    private function renderNative(array $response): string {
        // Каждый список маркируется дважды — под именем портала и под именем
        // группы: ровно то, что делал input-конфиг для Go-утилиты, где один и
        // тот же файл регистрировался под двумя именами.
        $entries = [];
        $rowCount = 0;
        foreach ($response as $siteNameAndGroup => $rows) {
            if (!count($rows)) {
                continue;
            }
            [$siteName, $siteGroup] = explode('/', $siteNameAndGroup);
            foreach ([$siteName, $siteGroup] as $code) {
                $entries[$code] = isset($entries[$code]) ? array_merge($entries[$code], $rows) : $rows;
            }
            $rowCount += count($rows) * 2;
        }

        $body = DatEncoder::encode(EncodeDatTask::KIND_GEOIP, GeoipDatWriter::payload($entries), $rowCount);

        $this->setHeaders([
            'content-type' => 'application/octet-stream',
            'content-disposition' => 'attachment; filename="iplist.dat"',
        ]);

        return $body;
    }

    /**
     * Путь через внешнюю утилиту `v2fly/geoip` — страховка на один релиз,
     * включается `SYS_GEOIP_NATIVE=false`.
     *
     * @param array<string, array<int, string>> $response
     */
    private function renderWithBinary(array $response): string {
        $binaryDir = rtrim(getEnv('GEOIP_PATH') ?: PATH_ROOT . '/geoip/', '/\\');

        // Весь ввод/вывод запроса живёт в собственном каталоге: имена не могут
        // столкнуться с параллельным запросом, а уборка сводится к удалению
        // одного дерева в finally. Тело ответа буферизуется в строку
        // (AbstractController::__invoke считает strlen), поэтому к моменту
        // destroy() данные пользователю уже отданы — ждать флаша сокета не нужно.
        $workspace = null;
        try {
            $workspace = TempWorkspace::create('geoip');

            $inputConfig = [];
            $index = 0;
            foreach ($response as $siteNameAndGroup => $value) {
                if (!count($value)) {
                    continue;
                }
                [$siteName, $siteGroup] = explode('/', $siteNameAndGroup);
                // Имя файла — индекс, а не имя портала: в именах порталов есть
                // точки и '@', а сам файл живёт только до конца запроса.
                $dataFilePath = $workspace->write('input/' . $index++ . '.txt', implode("\n", $value));
                foreach ([$siteName, $siteGroup] as $listName) {
                    $inputConfig[] = [
                        'type' => 'text',
                        'action' => 'add',
                        'args' => [
                            'name' => $listName,
                            'uri' => $dataFilePath,
                        ],
                    ];
                }
            }

            $outputDir = $workspace->createDirectory('output');
            $configFilePath = $workspace->write(
                'config.json',
                json_encode(
                    [
                        'input' => $inputConfig,
                        'output' => [
                            [
                                'type' => 'v2rayGeoIPDat',
                                'action' => 'output',
                                'args' => [
                                    'outputDir' => $outputDir,
                                    'outputName' => self::OUTPUT_NAME,
                                ],
                            ],
                        ],
                    ],
                    JSON_THROW_ON_ERROR
                )
            );

            $result = ProcessRunner::run(['./geoip', '-c', $configFilePath], $binaryDir);
            if ($result['code'] !== 0) {
                $this->logger->warning('geoip failed', $result);
                $errorOutput = ProcessRunner::errorOutput($result);

                return '# Error: geoip exited with ' .
                    $result['code'] .
                    ($errorOutput !== '' ? ': ' . $errorOutput : '');
            }

            $rawData = $workspace->read('output/' . self::OUTPUT_NAME);

            $this->setHeaders([
                'content-type' => 'application/octet-stream',
                'content-disposition' => 'attachment; filename="iplist.dat"',
            ]);
            return $rawData;
        } catch (Throwable $e) {
            return '# Error: ' . $e->getMessage();
        } finally {
            $workspace?->destroy();
        }
    }

    /**
     * Per-site row source. For cidr4/cidr6 we only pay `applyReplace` +
     * per-site `minimizeSubnets` when the site declares a replacement —
     * otherwise the raw property is already in canonical form (both cidr4
     * and cidr6 are minimized at load and kept minimized across reloads).
     * The final `normalizeArray` pass still sorts/dedupes and strips
     * private ranges before handing off to the geoip binary.
     *
     * @return array<int, string>
     */
    private function siteRows(Site $site, string $data): array {
        $rows = match (true) {
            $data === 'cidr4' && !$this->native && $site->hasReplace('cidr4') => IP4Helper::minimizeSubnets(
                $this->resolvedCidr($site, 'cidr4')
            ),
            $data === 'cidr6' && !$this->native && $site->hasReplace('cidr6') => IP6Helper::minimizeSubnets(
                $this->resolvedCidr($site, 'cidr6')
            ),
            default => $site->$data ?? [],
        };
        return SiteFactory::normalizeArray($rows, true);
    }
}
