<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\AsyncTest;

final class PacControllerTest extends AsyncTest {
    public function testDomainsEmitsShExpMatchLoop(): void {
        $response = $this->get('/', ['format' => 'pac', 'data' => 'domains']);
        self::assertSame(200, $response->getStatus());
        self::assertStringContainsString('text/javascript', $response->getHeader('content-type') ?? '');

        $body = $this->body($response);
        self::assertStringContainsString('const items = [', $body);
        self::assertStringContainsString('"game-a.com"', $body);
        self::assertStringContainsString('function FindProxyForURL(url, host)', $body);
        self::assertStringContainsString('shExpMatch(host, "*." + domain)', $body);
    }

    public function testCidr4EmitsIsInNetLoopWithMaskPairs(): void {
        $body = $this->body($this->get('/', ['format' => 'pac', 'data' => 'cidr4']));
        self::assertStringContainsString('isInNet(host, cidr[0], cidr[1])', $body);
        self::assertStringContainsString('["203.0.113.0","255.255.255.0"]', $body);
    }

    public function testCustomTemplate(): void {
        $body = $this->body(
            $this->get('/', ['format' => 'pac', 'data' => 'domains', 'template' => 'PROXY 10.0.0.1:8080'])
        );
        self::assertStringContainsString('return "PROXY 10.0.0.1:8080";', $body);
    }

    public function testUnsupportedDataReturnsError(): void {
        self::assertStringContainsString(
            "'data' GET parameter is must be 'domains' or 'cidr4'",
            $this->body($this->get('/', ['format' => 'pac', 'data' => 'ip4']))
        );
    }
}
