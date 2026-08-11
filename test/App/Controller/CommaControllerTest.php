<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\AsyncTest;

final class CommaControllerTest extends AsyncTest {
    public function testIp4CommaSeparated(): void {
        $response = $this->get('/', ['format' => 'comma', 'data' => 'ip4']);
        self::assertSame(200, $response->getStatus());

        $body = $this->body($response);
        $items = array_map('trim', explode(',', $body));
        foreach (['203.0.113.1', '203.0.113.100', '198.51.100.10', '192.0.2.1'] as $ip) {
            self::assertContains($ip, $items);
        }
        // Delimiter is ", " (comma + space), not bare comma
        self::assertStringContainsString(', ', $body);
    }

    public function testMissingDataReturnsError(): void {
        $response = $this->get('/', ['format' => 'comma']);
        self::assertStringContainsString("'data' GET parameter is required", $this->body($response));
    }
}
