<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\AsyncTest;

final class AmneziaControllerTest extends AsyncTest {
    public function testIp4ReturnsJsonArrayOfHostnameIpPairs(): void {
        $response = $this->get('/', ['format' => 'amnezia', 'data' => 'ip4']);
        self::assertStringContainsString('application/json', $response->getHeader('content-type') ?? '');

        $data = json_decode($this->body($response), true);
        self::assertIsArray($data);
        self::assertContains(['hostname' => '203.0.113.1', 'ip' => ''], $data);
        self::assertContains(['hostname' => '192.0.2.1', 'ip' => ''], $data);
    }

    public function testDomains(): void {
        $data = json_decode($this->body($this->get('/', ['format' => 'amnezia', 'data' => 'domains'])), true);
        self::assertContains(['hostname' => 'game-a.com', 'ip' => ''], $data);
    }

    public function testMissingDataReturnsError(): void {
        self::assertStringContainsString(
            "'data' GET parameter is required",
            $this->body($this->get('/', ['format' => 'amnezia']))
        );
    }

    public function testFilesaveAddsJsonContentDisposition(): void {
        $response = $this->get('/', ['format' => 'amnezia', 'data' => 'ip4', 'filesave' => '1']);
        self::assertStringContainsString('ip-list.json', $response->getHeader('content-disposition') ?? '');
    }

    /**
     * Every buffered response must advertise an exact Content-Length so a
     * reverse-proxy cache (and the downloading client) can detect a truncated
     * body instead of persisting it as if complete. Pins the corrupt-download
     * regression: AMPHP does not derive Content-Length from a string body, so
     * AbstractController sets it explicitly from strlen().
     */
    public function testContentLengthMatchesBodyByteLength(): void {
        $response = $this->get('/', ['format' => 'amnezia', 'data' => 'cidr4', 'filesave' => '1']);
        $body = $this->body($response);
        $contentLength = $response->getHeader('content-length');

        self::assertNotNull($contentLength, 'Content-Length header must be present');
        self::assertSame(strlen($body), (int) $contentLength);
    }
}
