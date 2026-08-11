<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\AsyncTest;

final class KvasControllerTest extends AsyncTest {
    public function testIp4PrependsKvasAddWith32Mask(): void {
        $lines = explode("\n", $this->body($this->get('/', ['format' => 'kvas', 'data' => 'ip4'])));
        self::assertContains('kvas add 203.0.113.1/32', $lines);
        self::assertContains('kvas add 192.0.2.1/32', $lines);
    }

    public function testIp6PrependsKvasAddWith128Mask(): void {
        $lines = explode("\n", $this->body($this->get('/', ['format' => 'kvas', 'data' => 'ip6'])));
        self::assertContains('kvas add 2001:db8::1/128', $lines);
    }

    public function testCidr4IsPassedThroughUnchanged(): void {
        $lines = explode("\n", $this->body($this->get('/', ['format' => 'kvas', 'data' => 'cidr4'])));
        self::assertContains('kvas add 203.0.113.0/24', $lines);
    }

    public function testDomainsGetYFlag(): void {
        $lines = explode("\n", $this->body($this->get('/', ['format' => 'kvas', 'data' => 'domains'])));
        self::assertContains('kvas add game-a.com Y', $lines);
    }

    public function testMissingDataReturnsError(): void {
        self::assertStringContainsString(
            "'data' GET parameter is required",
            $this->body($this->get('/', ['format' => 'kvas']))
        );
    }
}
