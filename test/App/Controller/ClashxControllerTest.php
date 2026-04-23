<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\AsyncTest;

final class ClashxControllerTest extends AsyncTest {
    public function testIp4UsesIpCidrWith32Mask(): void {
        $lines = explode("\n", $this->body($this->get('/', ['format' => 'clashx', 'data' => 'ip4'])));
        self::assertContains('IP-CIDR,203.0.113.1/32', $lines);
    }

    public function testIp6UsesIpCidr6With128Mask(): void {
        $lines = explode("\n", $this->body($this->get('/', ['format' => 'clashx', 'data' => 'ip6'])));
        self::assertContains('IP-CIDR6,2001:db8::1/128', $lines);
    }

    public function testCidr4(): void {
        $lines = explode("\n", $this->body($this->get('/', ['format' => 'clashx', 'data' => 'cidr4'])));
        self::assertContains('IP-CIDR,203.0.113.0/24', $lines);
    }

    public function testCidr6(): void {
        $lines = explode("\n", $this->body($this->get('/', ['format' => 'clashx', 'data' => 'cidr6'])));
        self::assertContains('IP-CIDR6,2001:db8::/48', $lines);
    }

    public function testDomainsUseSuffixRule(): void {
        $lines = explode("\n", $this->body($this->get('/', ['format' => 'clashx', 'data' => 'domains'])));
        self::assertContains('DOMAIN-SUFFIX,game-a.com', $lines);
    }
}
