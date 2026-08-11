<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\AsyncTest;

final class NfsetControllerTest extends AsyncTest {
    public function testIp4UsesFamily4Suffix(): void {
        $lines = explode("\n", $this->body($this->get('/', ['format' => 'nfset', 'data' => 'ip4'])));
        self::assertContains('nftset=/203.0.113.1/4#inet#fw4#vpn_ip4', $lines);
    }

    public function testIp6UsesFamily6Suffix(): void {
        $lines = explode("\n", $this->body($this->get('/', ['format' => 'nfset', 'data' => 'ip6'])));
        self::assertContains('nftset=/2001:db8::1/6#inet#fw4#vpn_ip6', $lines);
    }

    public function testDomainsUseFamily4(): void {
        $lines = explode("\n", $this->body($this->get('/', ['format' => 'nfset', 'data' => 'domains'])));
        self::assertContains('nftset=/game-a.com/4#inet#fw4#vpn_domains', $lines);
    }
}
