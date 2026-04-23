<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\AsyncTest;

final class MikrotikControllerTest extends AsyncTest {
    public function testMissingDataReturnsError(): void {
        $response = $this->get('/', ['format' => 'mikrotik']);
        self::assertSame(200, $response->getStatus());
        self::assertStringContainsString("'data' GET parameter is required", $this->body($response));
    }

    public function testIp4DefaultTemplate(): void {
        $response = $this->get('/', ['format' => 'mikrotik', 'data' => 'ip4']);
        self::assertSame(200, $response->getStatus());
        $body = $this->body($response);

        // Default template is {group}_{data}
        self::assertStringContainsString('/ip firewall address-list remove [find list="games_ip4"];', $body);
        self::assertStringContainsString('/ip firewall address-list', $body);
        self::assertMatchesRegularExpression('/add list=games_ip4 address=203\.0\.113\.\d+ comment=game-/', $body);
        self::assertStringContainsString(':delay 5s', $body);
        // Last entry per list is terminated with ";"
        self::assertMatchesRegularExpression('/add list=games_ip4 address=[^\s]+ comment=[^\s;]+;/', $body);
    }

    public function testCustomTemplate(): void {
        // MikrotikController only substitutes {group} and {data} in the list name
        // (see MikrotikController::getBody: the str_replace loop has no {site} entry).
        $body = $this->body(
            $this->get('/', ['format' => 'mikrotik', 'data' => 'ip4', 'template' => '{group}-{data}-list'])
        );
        self::assertStringContainsString('list="games-ip4-list"', $body);
        self::assertStringContainsString('list="casino-ip4-list"', $body);
    }

    public function testAppendIsAttachedToEachEntry(): void {
        $body = $this->body(
            $this->get('/', ['format' => 'mikrotik', 'data' => 'cidr4', 'append' => 'timeout=1d'])
        );
        self::assertStringContainsString('timeout=1d', $body);
    }
}
