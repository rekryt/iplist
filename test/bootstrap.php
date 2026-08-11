<?php

declare(strict_types=1);

use OpenCCK\Infrastructure\API\App;
use OpenCCK\Infrastructure\API\Server;

require_once __DIR__ . '/../vendor/autoload.php';

// Pin PATH_ROOT to the test fixture tree before App::__construct() has a chance
// to define it. IPListService scans "$PATH_ROOT/config/<group>/<site>.json",
// DocumentRoot serves "$PATH_ROOT/public", CIDRStorage lives in "$PATH_ROOT/storage".
if (!defined('PATH_ROOT')) {
    define('PATH_ROOT', __DIR__ . '/fixtures');
}

foreach (['storage', 'public'] as $dir) {
    $path = PATH_ROOT . '/' . $dir;
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
}

// DNS resolution / external URL fetching must stay off by default — fixtures have
// timeout=0 so Site::preload()/reload() never fire, but make it explicit in case
// phpunit.xml env wiring differs by PHPUnit version.
foreach (
    [
        'SYS_DNS_RESOLVE_IP4' => 'false',
        'SYS_DNS_RESOLVE_IP6' => 'false',
        'HTTP_HOST' => '127.0.0.1',
        'HTTP_PORT' => $_ENV['HTTP_PORT'] ?? '8090',
        'DEBUG' => 'false',
        'STORAGE_SAVE_INTERVAL' => '3600',
    ]
    as $k => $v
) {
    if (!isset($_ENV[$k])) {
        $_ENV[$k] = $v;
    }
}

// App::__construct() wires dotenv, logger, signal error handler. Server::__construct()
// triggers IPListService::getInstance() which loads the fixture config tree.
App::getInstance();
Server::getInstance()->start();
