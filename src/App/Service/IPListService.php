<?php

namespace OpenCCK\App\Service;

use OpenCCK\Domain\Entity\Site;
use OpenCCK\Domain\Factory\SiteFactory;
use OpenCCK\Infrastructure\API\App;

use Exception;
use Monolog\Logger;

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
        foreach (scandir($dir) as $file) {
            if (str_ends_with($file, '.json')) {
                $this->loadConfig(substr($file, 0, -5), json_decode(file_get_contents($dir . $file)));
            }
        }
    }

    /**
     * @param ?Logger $logger
     * @return IPListService
     * @throws Exception
     */
    public static function getInstance(Logger $logger = null): IPListService {
        return self::$_instance ??= new self($logger);
    }

    private function loadConfig(string $name, object $config): void {
        $this->sites[$name] = SiteFactory::create($name, $config);
    }
}
