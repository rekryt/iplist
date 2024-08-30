<?php

namespace OpenCCK\Infrastructure\API\Handler;

use Amp\Http\Server\RequestHandler;

interface HandlerInterface {
    /**
     * @return RequestHandler
     */
    public function getHandler(): RequestHandler;
}
