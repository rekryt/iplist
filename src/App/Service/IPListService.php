<?php

namespace OpenCCK\App\Service;

use OpenCCK\Domain\Entity\Site;
use OpenCCK\Domain\Factory\SiteFactory;
use OpenCCK\Infrastructure\API\App;

use Exception;
use Monolog\Logger;
use Revolt\EventLoop;
use function Amp\delay;
use function OpenCCK\dbg;

class IPListService {
    private static IPListService $_instance;
    private ?Logger $logger;

    /**
     * @var array<string, Site>
     */
    public array $sites = [];

    /**
     * @throws Exception
     */
    private function __construct(Logger $logger = null) {
        $this->logger = $logger ?? App::getLogger();

        $dir = PATH_ROOT . '/config/';
        if (!is_dir($dir)) {
            throw new Exception('config directory not found');
        }

        foreach (scandir($dir) as $item) {
            if (in_array($item, ['.', '..'])) {
                continue;
            }
            $path = $dir . $item;
            if (is_dir($path)) {
                foreach (scandir($path) as $file) {
                    if (is_file($path . '/' . $file)) {
                        $this->loadConfig($path . '/' . $file);
                    }
                }
            }
        }

        $this->logWorkloadSummary();

        EventLoop::queue(function () {
            foreach ($this->sites as $siteEntity) {
                if ($siteEntity->timeout) {
                    $siteEntity->preload();
                    $siteEntity->reload();
                    delay(1);
                }
            }
        });
    }

    /**
     * @param ?Logger $logger
     * @return IPListService
     * @throws Exception
     */
    public static function getInstance(Logger $logger = null): IPListService {
        return self::$_instance ??= new self($logger);
    }

    /**
     * @param string $path
     * @return void
     */
    private function loadConfig(string $path): void {
        if (str_ends_with($path, '.json')) {
            $parts = explode('/', $path);
            $filename = array_pop($parts);
            $name = substr($filename, 0, -5);
            $config = json_decode(file_get_contents($path));
            $group = array_pop($parts);

            if (isset($this->sites[$name])) {
                $this->logger->error(sprintf('Site config "%s" already exists', $name));
                delay(5);
                exit();
            }

            $this->sites[$name] = SiteFactory::create($name, $group, $config);
        }
    }

    /**
     * Size of the in-memory dataset at boot. Logged once so operators know the
     * N before hunting a slow request. Split per group so skewed groups (e.g.
     * one group carrying most of the data) are visible.
     */
    private function logWorkloadSummary(): void {
        $groups = [];
        foreach ($this->sites as $site) {
            $g = $site->group;
            if (!isset($groups[$g])) {
                $groups[$g] = ['sites' => 0, 'domains' => 0, 'ip4' => 0, 'ip6' => 0, 'cidr4' => 0, 'cidr6' => 0];
            }
            $groups[$g]['sites']++;
            $groups[$g]['domains'] += count($site->domains);
            $groups[$g]['ip4'] += count($site->ip4);
            $groups[$g]['ip6'] += count($site->ip6);
            $groups[$g]['cidr4'] += count($site->cidr4);
            $groups[$g]['cidr6'] += count($site->cidr6);
        }

        $totals = ['sites' => 0, 'domains' => 0, 'ip4' => 0, 'ip6' => 0, 'cidr4' => 0, 'cidr6' => 0];
        foreach ($groups as $row) {
            foreach ($row as $k => $v) {
                $totals[$k] += $v;
            }
        }

        $this->logger->info(sprintf(
            'Workload: %d sites across %d groups — %s domains, %s IPv4, %s IPv6, %s CIDRv4, %s CIDRv6',
            $totals['sites'],
            count($groups),
            number_format($totals['domains']),
            number_format($totals['ip4']),
            number_format($totals['ip6']),
            number_format($totals['cidr4']),
            number_format($totals['cidr6'])
        ));

        // Top groups by IPv4 — usually the dominant cost dimension.
        $byIp4 = $groups;
        uasort($byIp4, fn(array $a, array $b) => $b['ip4'] <=> $a['ip4']);
        foreach (array_slice($byIp4, 0, 5, true) as $groupName => $row) {
            if ($row['ip4'] === 0 && $row['cidr4'] === 0) {
                break;
            }
            $this->logger->debug(sprintf(
                '  group=%s sites=%d domains=%s ip4=%s ip6=%s cidr4=%s cidr6=%s',
                $groupName,
                $row['sites'],
                number_format($row['domains']),
                number_format($row['ip4']),
                number_format($row['ip6']),
                number_format($row['cidr4']),
                number_format($row['cidr6'])
            ));
        }
    }
}
