<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\AsyncTest;

final class TextControllerTest extends AsyncTest {
    public function testMissingDataReturnsErrorBody(): void {
        $response = $this->get('/', ['format' => 'text']);
        self::assertSame(200, $response->getStatus());
        self::assertStringContainsString("'data' GET parameter is required", $this->body($response));
    }

    public function testIp4IsFlatNewlineSeparated(): void {
        $response = $this->get('/', ['format' => 'text', 'data' => 'ip4']);
        self::assertSame(200, $response->getStatus());
        self::assertStringContainsString('text/plain', $response->getHeader('content-type') ?? '');

        $lines = explode("\n", $this->body($response));
        foreach (['203.0.113.1', '203.0.113.2', '198.51.100.10', '192.0.2.1', '203.0.113.100'] as $ip) {
            self::assertContains($ip, $lines);
        }
    }

    public function testIp6(): void {
        $lines = explode("\n", $this->body($this->get('/', ['format' => 'text', 'data' => 'ip6'])));
        self::assertContains('2001:db8::1', $lines);
        self::assertContains('2001:db8:1::1', $lines);
    }

    public function testDomains(): void {
        $lines = explode("\n", $this->body($this->get('/', ['format' => 'text', 'data' => 'domains'])));
        self::assertContains('game-a.com', $lines);
        self::assertContains('casino-a.com', $lines);
    }

    public function testCidr4IsMinimized(): void {
        $lines = explode("\n", $this->body($this->get('/', ['format' => 'text', 'data' => 'cidr4'])));
        // 203.0.113.100/31 is subsumed by 203.0.113.0/24 — minimizeSubnets must drop it.
        self::assertContains('203.0.113.0/24', $lines);
        self::assertNotContains('203.0.113.100/31', $lines);
        self::assertContains('198.51.100.0/24', $lines);
        self::assertContains('192.0.2.0/24', $lines);
    }
}
