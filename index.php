<?php

use OpenCCK\Infrastructure\API\App;
use OpenCCK\Infrastructure\API\Server;

use Amp\ByteStream\BufferException;

require_once 'vendor/autoload.php';

App::getInstance()
    ->addModule(
        /**
         * @throws Throwable
         * @throws BufferException
         */ fn(App $app) => Server::getInstance()
    )
    ->start();
