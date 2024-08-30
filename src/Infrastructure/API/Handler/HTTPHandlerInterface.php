<?php

namespace OpenCCK\Infrastructure\API\Handler;

use Amp\Http\Server\RequestHandler;

interface HTTPHandlerInterface extends HandlerInterface {
    /**
     * @return RequestHandler
     */
    public function getHandler(): RequestHandler;
}
