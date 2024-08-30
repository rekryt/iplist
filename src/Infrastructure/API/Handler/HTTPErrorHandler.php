<?php

namespace OpenCCK\Infrastructure\API\Handler;

use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;

final class HTTPErrorHandler implements ErrorHandler {
    public function handleError(int $status, ?string $reason = null, ?Request $request = null): Response {
        return new Response(
            status: $status,
            headers: ['content-type' => 'application/json; charset=utf-8'],
            body: json_encode(['message' => $reason, 'code' => $status])
        );
    }
}
