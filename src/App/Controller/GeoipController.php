<?php

namespace OpenCCK\App\Controller;

use OpenCCK\Domain\Entity\Site;
use OpenCCK\Domain\Factory\SiteFactory;
use OpenCCK\Domain\Helper\IP4Helper;
use OpenCCK\Domain\Helper\IP6Helper;
use Amp\File;
use Amp\Process\Process;
use Amp\Process\ProcessException;

/**
 * @see https://github.com/v2fly/geoip/blob/HEAD/configuration.md
 */
class GeoipController extends AbstractIPListController {
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

        $path = \OpenCCK\getEnv('GEOIP_PATH') ?? PATH_ROOT . '/geoip/';
        try {
            if (!File\isDirectory($path . 'input/')) {
                File\createDirectory($path . 'input/');
            }

            $inputConfig = [];
            foreach ($response as $siteNameAndGroup => $value) {
                if (!count($value)) {
                    continue;
                }
                [$siteName, $siteGroup] = explode('/', $siteNameAndGroup);
                $dataFilePath = $path . 'input/' . microtime(true) . '.' . $siteName . '.data.txt';
                if (File\exists($dataFilePath)) {
                    File\deleteFile($dataFilePath);
                }
                File\write($dataFilePath, implode("\n", $value));
                $inputConfig[] = [
                    'type' => 'text',
                    'action' => 'add',
                    'args' => [
                        'name' => $siteName,
                        'uri' => $dataFilePath,
                    ],
                ];
                $inputConfig[] = [
                    'type' => 'text',
                    'action' => 'add',
                    'args' => [
                        'name' => $siteGroup,
                        'uri' => $dataFilePath,
                    ],
                ];
            }

            $configFilePath = $path . microtime(true) . '.config.json';
            if (File\exists($configFilePath)) {
                File\deleteFile($configFilePath);
            }

            $outputDir = $path . 'output/';
            if (!File\exists($outputDir)) {
                File\createDirectory($outputDir);
            }
            $outputFileName = microtime(true) . '.iplist.dat';
            if (File\exists($outputFileName)) {
                File\deleteFile($outputFileName);
            }
            File\write(
                $configFilePath,
                json_encode([
                    'input' => $inputConfig,
                    'output' => [
                        [
                            'type' => 'v2rayGeoIPDat',
                            'action' => 'output',
                            'args' => [
                                'outputDir' => $path . 'output',
                                'outputName' => $outputFileName,
                            ],
                        ],
                    ],
                ])
            );
            $process = Process::start('./geoip -c ' . $configFilePath, $path);
            $process->join();

            //            foreach ($inputConfig as $config) {
            //                if (File\exists($config['args']['uri'])) {
            //                    File\deleteFile($config['args']['uri']);
            //                }
            //            }
            //            File\deleteFile($configFilePath);

            $rawData = File\read($path . 'output/' . $outputFileName);
            // File\deleteFile($path . 'output/' . $outputFileName);

            $this->setHeaders(['content-disposition' => 'attachment; filename="iplist.dat"']);
            return $rawData;
        } catch (\Throwable $e) {
            return '# Error: ' . $e->getMessage();
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
