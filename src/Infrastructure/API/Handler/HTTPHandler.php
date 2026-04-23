<?php

namespace OpenCCK\Infrastructure\API\Handler;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;

use Psr\Log\LoggerInterface;
use Throwable;

use function OpenCCK\dbg;
use function OpenCCK\getEnv;

final class HTTPHandler extends Handler implements HTTPHandlerInterface {
    private function __construct(private readonly LoggerInterface $logger, array $headers = null) {
    }

    public static function getInstance(LoggerInterface $logger, array $headers = null): HTTPHandler {
        return new self($logger, $headers);
    }

    /**
     * @param string $controllerName
     * @return RequestHandler
     */
    public function getHandler(string $controllerName = 'main'): RequestHandler {
        return new ClosureRequestHandler(function (Request $request) use ($controllerName): Response {
            $controllerClass = ucfirst($request->getQueryParameter('format') ?: $controllerName);
            $startNs = hrtime(true);

            try {
                $response = $this->getController($controllerClass, $request, $this->headers ?? [])();
            } catch (Throwable $e) {
                $this->logger->warning('Exception', [
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                    'code' => $e->getCode(),
                ]);
                $response = new Response(
                    status: $e->getCode() ?: 500,
                    headers: $this->headers ?? ['content-type' => 'application/json; charset=utf-8'],
                    body: json_encode(
                        array_merge(
                            ['message' => $e->getMessage(), 'code' => $e->getCode()],
                            getEnv('DEBUG') === 'true'
                                ? ['file' => $e->getFile() . ':' . $e->getLine(), 'trace' => $e->getTrace()]
                                : []
                        )
                    )
                );
            }

            // Per-request diagnostic — one line per request, gated to DEBUG so
            // prod logs stay quiet until an operator flips DEBUG=true to investigate.
            $durationMs = (hrtime(true) - $startNs) / 1e6;
            $uri = $request->getUri();
            $pathQuery = $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : '');
            $this->logger->debug(sprintf(
                '%s %s → %d | %s | %.1fms | peak %.1f MiB',
                $request->getMethod(),
                $pathQuery,
                $response->getStatus(),
                $controllerClass,
                $durationMs,
                memory_get_peak_usage(true) / 1_048_576
            ));

            return $response;
        });
    }
}
