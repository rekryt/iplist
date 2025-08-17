<?php

namespace OpenCCK\App\Controller;

use OpenCCK\Domain\Factory\SiteFactory;
use OpenCCK\Domain\Helper\IP4Helper;
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
                $response[$site . '/' . $items[$site]->group] = SiteFactory::normalizeArray($items[$site]->$data, true);
            }
        } else {
            foreach ($this->getSites() as $siteEntity) {
                $response[$siteEntity->name . '/' . $siteEntity->group] = SiteFactory::normalizeArray(
                    $siteEntity->$data,
                    true
                );
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
}
