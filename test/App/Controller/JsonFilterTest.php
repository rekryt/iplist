<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\AsyncTest;

/**
 * Filter / exclude matrix for ?format=json. Fixture layout:
 *   games   → game-a (203.0.113.1-3, 2001:db8::1,2), game-b (198.51.100.10-11, 2001:db8:1::1)
 *   tools   → tool-a (192.0.2.1-2, 2001:db8:2::1)
 *   casino  → casino-a (203.0.113.100-101, 2001:db8:ff::1)
 */
final class JsonFilterTest extends AsyncTest {
    /** @return array<string, mixed> */
    private function json(array $query): array {
        return json_decode($this->body($this->get('/', ['format' => 'json'] + $query)), true);
    }

    public function testSingleSiteFilter(): void {
        $data = $this->json(['site' => 'game-a']);
        self::assertSame(['game-a'], array_keys($data));
    }

    public function testMultiSiteFilter(): void {
        $data = $this->json(['site' => ['game-a', 'tool-a']]);
        self::assertEqualsCanonicalizing(['game-a', 'tool-a'], array_keys($data));
    }

    public function testSingleGroupFilter(): void {
        $data = $this->json(['group' => 'games']);
        self::assertEqualsCanonicalizing(['game-a', 'game-b'], array_keys($data));
    }

    public function testMultiGroupFilter(): void {
        $data = $this->json(['group' => ['games', 'tools']]);
        self::assertEqualsCanonicalizing(['game-a', 'game-b', 'tool-a'], array_keys($data));
        self::assertArrayNotHasKey('casino-a', $data);
    }

    public function testExcludeSingleSite(): void {
        $data = $this->json(['exclude[site]' => 'casino-a']);
        self::assertArrayNotHasKey('casino-a', $data);
        self::assertArrayHasKey('game-a', $data);
    }

    public function testExcludeSingleGroup(): void {
        // The original failing query path (/?format=mikrotik&data=ip4&exclude[group]=casino)
        $data = $this->json(['data' => 'ip4', 'exclude[group]' => 'casino']);
        self::assertArrayNotHasKey('casino-a', $data);
        self::assertArrayHasKey('game-a', $data);
        self::assertEqualsCanonicalizing(['203.0.113.1', '203.0.113.2', '203.0.113.3'], $data['game-a']);
    }

    public function testExcludeMultipleGroups(): void {
        $data = $this->json(['exclude[group]' => ['casino', 'tools']]);
        self::assertEqualsCanonicalizing(['game-a', 'game-b'], array_keys($data));
    }

    public function testExcludeIp4(): void {
        $data = $this->json([
            'data' => 'ip4',
            'exclude[ip4]' => ['203.0.113.1', '203.0.113.2'],
        ]);
        self::assertEqualsCanonicalizing(['203.0.113.3'], $data['game-a']);
        self::assertNotContains('203.0.113.1', $data['game-a']);
    }

    public function testExcludeCidr4(): void {
        $data = $this->json(['data' => 'cidr4', 'exclude[cidr4]' => '203.0.113.0/24']);
        // exclude matches the literal CIDR string, not network inclusion
        self::assertNotContains('203.0.113.0/24', $data['game-a']);
        self::assertContains('198.51.100.0/24', $data['game-b']);
    }

    public function testExcludeIp6(): void {
        $data = $this->json(['data' => 'ip6', 'exclude[ip6]' => '2001:db8::1']);
        self::assertNotContains('2001:db8::1', $data['game-a']);
        self::assertContains('2001:db8::2', $data['game-a']);
    }

    public function testExcludeCidr6(): void {
        $data = $this->json(['data' => 'cidr6', 'exclude[cidr6]' => '2001:db8::/48']);
        self::assertNotContains('2001:db8::/48', $data['game-a']);
    }

    public function testExcludeDomain(): void {
        $data = $this->json(['data' => 'domains', 'exclude[domain]' => 'game-a.com']);
        self::assertNotContains('game-a.com', $data['game-a']);
        self::assertContains('www.game-a.com', $data['game-a']);
    }

    public function testWildcardCollapsesDomains(): void {
        $data = $this->json(['data' => 'domains', 'wildcard' => '1']);
        // www.game-a.com, api.game-a.com → game-a.com (single registrable entry)
        self::assertContains('game-a.com', $data['game-a']);
        self::assertNotContains('www.game-a.com', $data['game-a']);
        self::assertNotContains('api.game-a.com', $data['game-a']);
        // cdn.game-a.co.uk collapses via TWO_LEVEL_DOMAIN_ZONES (co.uk) to game-a.co.uk
        self::assertContains('game-a.co.uk', $data['game-a']);
        self::assertNotContains('cdn.game-a.co.uk', $data['game-a']);
    }

    public function testFilesaveAddsJsonAttachmentHeader(): void {
        $response = $this->get('/', ['format' => 'json', 'filesave' => '1']);
        self::assertStringContainsString('attachment', $response->getHeader('content-disposition') ?? '');
        self::assertStringContainsString('ip-list.json', $response->getHeader('content-disposition') ?? '');
    }

    public function testGroupFilterCombinedWithExcludeSite(): void {
        $data = $this->json(['group' => 'games', 'exclude[site]' => 'game-b']);
        self::assertSame(['game-a'], array_keys($data));
    }

    public function testSiteCombinedWithWildcardAndExcludeDomain(): void {
        // After wildcard collapse, www.game-a.com → game-a.com, then excluded.
        $data = $this->json([
            'data' => 'domains',
            'site' => 'game-a',
            'wildcard' => '1',
            'exclude[domain]' => 'game-a.com',
        ]);
        self::assertNotContains('game-a.com', $data['game-a']);
        // game-a.co.uk collapses from cdn.game-a.co.uk and is not excluded
        self::assertContains('game-a.co.uk', $data['game-a']);
    }
}
