<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\AsyncTest;

/**
 * Filter / exclude matrix for ?format=text. TextController flattens all
 * matched sites into a single sorted newline-separated list — no site key,
 * so assertions check which values appear in that merged list.
 */
final class TextFilterTest extends AsyncTest {
    /** @return string[] */
    private function lines(array $query): array {
        return explode("\n", $this->body($this->get('/', ['format' => 'text'] + $query)));
    }

    public function testSingleSiteFilter(): void {
        $lines = $this->lines(['data' => 'ip4', 'site' => 'game-a']);
        self::assertContains('203.0.113.1', $lines);
        self::assertNotContains('198.51.100.10', $lines); // from game-b
        self::assertNotContains('192.0.2.1', $lines); // from tool-a
    }

    public function testMultiSiteFilter(): void {
        $lines = $this->lines(['data' => 'ip4', 'site' => ['game-a', 'tool-a']]);
        self::assertContains('203.0.113.1', $lines);
        self::assertContains('192.0.2.1', $lines);
        self::assertNotContains('198.51.100.10', $lines);
        self::assertNotContains('203.0.113.100', $lines);
    }

    public function testSingleGroupFilter(): void {
        $lines = $this->lines(['data' => 'ip4', 'group' => 'games']);
        self::assertContains('203.0.113.1', $lines);
        self::assertContains('198.51.100.10', $lines);
        self::assertNotContains('192.0.2.1', $lines);
        self::assertNotContains('203.0.113.100', $lines);
    }

    public function testMultiGroupFilter(): void {
        $lines = $this->lines(['data' => 'ip4', 'group' => ['games', 'tools']]);
        self::assertContains('203.0.113.1', $lines);
        self::assertContains('192.0.2.1', $lines);
        self::assertNotContains('203.0.113.100', $lines);
    }

    public function testExcludeSingleSite(): void {
        $lines = $this->lines(['data' => 'ip4', 'exclude[site]' => 'casino-a']);
        self::assertNotContains('203.0.113.100', $lines);
        self::assertNotContains('203.0.113.101', $lines);
        self::assertContains('203.0.113.1', $lines);
    }

    public function testExcludeSingleGroup(): void {
        $lines = $this->lines(['data' => 'ip4', 'exclude[group]' => 'casino']);
        self::assertNotContains('203.0.113.100', $lines);
        self::assertContains('203.0.113.1', $lines);
        self::assertContains('192.0.2.1', $lines);
    }

    public function testExcludeMultipleGroups(): void {
        $lines = $this->lines(['data' => 'ip4', 'exclude[group]' => ['casino', 'tools']]);
        self::assertNotContains('192.0.2.1', $lines);
        self::assertNotContains('203.0.113.100', $lines);
        self::assertContains('203.0.113.1', $lines);
        self::assertContains('198.51.100.10', $lines);
    }

    public function testExcludeIp4(): void {
        $lines = $this->lines([
            'data' => 'ip4',
            'exclude[ip4]' => ['203.0.113.1', '203.0.113.100'],
        ]);
        self::assertNotContains('203.0.113.1', $lines);
        self::assertNotContains('203.0.113.100', $lines);
        self::assertContains('203.0.113.2', $lines);
        self::assertContains('203.0.113.101', $lines);
    }

    public function testExcludeCidr4(): void {
        $lines = $this->lines(['data' => 'cidr4', 'exclude[cidr4]' => '203.0.113.0/24']);
        self::assertNotContains('203.0.113.0/24', $lines);
        self::assertContains('198.51.100.0/24', $lines);
        self::assertContains('192.0.2.0/24', $lines);
    }

    public function testExcludeIp6(): void {
        $lines = $this->lines(['data' => 'ip6', 'exclude[ip6]' => '2001:db8::1']);
        self::assertNotContains('2001:db8::1', $lines);
        self::assertContains('2001:db8::2', $lines);
    }

    public function testExcludeCidr6(): void {
        $lines = $this->lines(['data' => 'cidr6', 'exclude[cidr6]' => '2001:db8::/48']);
        self::assertNotContains('2001:db8::/48', $lines);
        self::assertContains('2001:db8:1::/48', $lines);
    }

    public function testExcludeDomain(): void {
        $lines = $this->lines(['data' => 'domains', 'exclude[domain]' => 'game-a.com']);
        self::assertNotContains('game-a.com', $lines);
        self::assertContains('www.game-a.com', $lines);
        self::assertContains('game-b.com', $lines);
    }

    public function testWildcardCollapsesDomains(): void {
        // TextController does NOT thread wildcard through to getDomains unless the
        // controller honors it. WildcardController subclasses TextController and
        // forces wildcard=1; TextController itself applies wildcard via getSites()
        // (which calls getDomains($wildcard)), so wildcard=1 on format=text works.
        $lines = $this->lines(['data' => 'domains', 'wildcard' => '1']);
        self::assertContains('game-a.com', $lines);
        self::assertNotContains('www.game-a.com', $lines);
        self::assertContains('game-a.co.uk', $lines);
    }

    public function testFilesaveAddsTxtAttachmentHeader(): void {
        $response = $this->get('/', ['format' => 'text', 'data' => 'ip4', 'filesave' => '1']);
        self::assertStringContainsString('attachment', $response->getHeader('content-disposition') ?? '');
        self::assertStringContainsString('ip-list.txt', $response->getHeader('content-disposition') ?? '');
    }

    public function testGroupFilterCombinedWithExcludeSite(): void {
        $lines = $this->lines(['data' => 'ip4', 'group' => 'games', 'exclude[site]' => 'game-b']);
        self::assertContains('203.0.113.1', $lines);
        self::assertNotContains('198.51.100.10', $lines);
        self::assertNotContains('192.0.2.1', $lines);
    }

    public function testSiteCombinedWithWildcardAndExcludeDomain(): void {
        $lines = $this->lines([
            'data' => 'domains',
            'site' => 'game-a',
            'wildcard' => '1',
            'exclude[domain]' => 'game-a.com',
        ]);
        self::assertNotContains('game-a.com', $lines);
        self::assertContains('game-a.co.uk', $lines);
        // game-b wasn't requested — must not appear
        self::assertNotContains('game-b.com', $lines);
    }
}
