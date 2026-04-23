<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\AsyncTest;

final class IpsetControllerTest extends AsyncTest {
    public function testIp4(): void {
        $lines = explode("\n", $this->body($this->get('/', ['format' => 'ipset', 'data' => 'ip4'])));
        self::assertContains('ipset=/203.0.113.1/vpn_ip4', $lines);
        self::assertContains('ipset=/192.0.2.1/vpn_ip4', $lines);
    }

    public function testDomains(): void {
        $lines = explode("\n", $this->body($this->get('/', ['format' => 'ipset', 'data' => 'domains'])));
        self::assertContains('ipset=/game-a.com/vpn_domains', $lines);
    }
}
