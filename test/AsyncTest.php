<?php

declare(strict_types=1);

namespace OpenCCK;

use Amp\ByteStream\StreamException;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\PHPUnit\AsyncTestCase;

use OpenCCK\App\Service\IPListService;
use OpenCCK\Infrastructure\API\App;

/**
 * Base class for async controller tests.
 *
 * Boot is handled once by test/bootstrap.php (PATH_ROOT is pinned to
 * test/fixtures, App + Server are started, IPListService loads fixtures
 * with timeout=0 so no DNS/reload work happens during tests).
 *
 * Subclasses send HTTP requests via $this->get() and assert on the response.
 */
abstract class AsyncTest extends AsyncTestCase {
    protected HttpClient $httpClient;
    protected string $baseUrl;
    protected App $app;

    protected function setUp(): void {
        parent::setUp();
        $this->httpClient = (new HttpClientBuilder())->followRedirects(0)->build();
        $this->baseUrl = 'http://127.0.0.1:' . ($_ENV['HTTP_PORT'] ?? 8090);
        $this->app = App::getInstance();
    }

    /**
     * Send a GET request to the running test server.
     *
     * Query building preserves repeated keys like `exclude[group]=a&exclude[group]=b`.
     * Pass an array value to repeat the key: ['exclude[group]' => ['casino', 'porn']].
     *
     * @param array<string, string|int|array<int, string|int>> $query
     * @throws HttpException
     */
    protected function get(string $path = '/', array $query = []): Response {
        return $this->httpClient->request(new Request($this->buildUrl($path, $query), 'GET'));
    }

    /**
     * @param array<string, string|int|array<int, string|int>> $query
     */
    protected function buildUrl(string $path, array $query): string {
        $url = $this->baseUrl . $path;
        if (!$query) {
            return $url;
        }

        $parts = [];
        foreach ($query as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                $parts[] = rawurlencode($name) . '=' . rawurlencode((string) $item);
            }
        }
        return $url . '?' . implode('&', $parts);
    }

    /**
     * Buffer and return the response body as a string.
     *
     * @throws StreamException
     */
    protected function body(Response $response): string {
        return $response->getBody()->buffer();
    }

    protected function service(): IPListService {
        return IPListService::getInstance();
    }
}
