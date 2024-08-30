<?php

namespace OpenCCK\App\Controller;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;

interface ControllerInterface {
    /**
     * @param Request $request
     * @param array $headers
     */
    public function __construct(Request $request, array $headers = []);

    /**
     * @return Response
     */
    public function __invoke(): Response;
}
