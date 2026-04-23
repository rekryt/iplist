<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\AsyncTest;

/**
 * Filter / exclude matrix for ?format=mikrotik. Mikrotik script is grouped
 * by "add list=<group>_<data> address=<IP> comment=<site>", so assertions check
 * substrings of the full body.
 *
 * The original failing query path — /?format=mikrotik&data=ip4&exclude[group]=casino —
 * is regression-tested here: must produce a valid script without hanging / OOM.
 */
final class MikrotikFilterTest extends AsyncTest {
    private function script(array $query): string {
        return $this->body($this->get('/', ['format' => 'mikrotik'] + $query));
    }

    public function testSingleSiteFilter(): void {
        $body = $this->script(['data' => 'ip4', 'site' => 'game-a']);
        self::assertStringContainsString('address=203.0.113.1 comment=game-a', $body);
        self::assertStringNotContainsString('comment=game-b', $body);
        self::assertStringNotContainsString('comment=tool-a', $body);
        self::assertStringNotContainsString('comment=casino-a', $body);
    }

    public function testMultiSiteFilter(): void {
        $body = $this->script(['data' => 'ip4', 'site' => ['game-a', 'tool-a']]);
        self::assertStringContainsString('comment=game-a', $body);
        self::assertStringContainsString('comment=tool-a', $body);
        self::assertStringNotContainsString('comment=game-b', $body);
        self::assertStringNotContainsString('comment=casino-a', $body);
    }

    public function testSingleGroupFilter(): void {
        $body = $this->script(['data' => 'ip4', 'group' => 'games']);
        self::assertStringContainsString('list="games_ip4"', $body);
        self::assertStringNotContainsString('list="tools_ip4"', $body);
        self::assertStringNotContainsString('list="casino_ip4"', $body);
    }

    public function testMultiGroupFilter(): void {
        $body = $this->script(['data' => 'ip4', 'group' => ['games', 'tools']]);
        self::assertStringContainsString('list="games_ip4"', $body);
        self::assertStringContainsString('list="tools_ip4"', $body);
        self::assertStringNotContainsString('list="casino_ip4"', $body);
    }

    public function testExcludeSingleSite(): void {
        $body = $this->script(['data' => 'ip4', 'exclude[site]' => 'casino-a']);
        self::assertStringNotContainsString('comment=casino-a', $body);
        self::assertStringContainsString('comment=game-a', $body);
    }

    public function testExcludeSingleGroupCasinoRegressionGuard(): void {
        // Original failing query path — asserts a complete, well-formed script is returned.
        $body = $this->script(['data' => 'ip4', 'exclude[group]' => 'casino']);

        self::assertStringNotContainsString('list="casino_ip4"', $body);
        self::assertStringNotContainsString('comment=casino-a', $body);
        self::assertStringNotContainsString('203.0.113.100', $body);
        self::assertStringNotContainsString('203.0.113.101', $body);

        self::assertStringContainsString('list="games_ip4"', $body);
        self::assertStringContainsString('list="tools_ip4"', $body);
        self::assertStringContainsString('add list=games_ip4 address=203.0.113.1 comment=game-a', $body);

        // Every list block ends the last entry with a trailing ";"
        self::assertMatchesRegularExpression('/comment=(game-a|game-b);/', $body);
    }

    public function testExcludeMultipleGroups(): void {
        $body = $this->script(['data' => 'ip4', 'exclude[group]' => ['casino', 'tools']]);
        self::assertStringContainsString('list="games_ip4"', $body);
        self::assertStringNotContainsString('list="tools_ip4"', $body);
        self::assertStringNotContainsString('list="casino_ip4"', $body);
    }

    public function testExcludeIp4(): void {
        $body = $this->script([
            'data' => 'ip4',
            'exclude[ip4]' => ['203.0.113.1', '203.0.113.100'],
        ]);
        self::assertStringNotContainsString('address=203.0.113.1 ', $body);
        self::assertStringNotContainsString('address=203.0.113.100 ', $body);
        self::assertStringContainsString('address=203.0.113.2 ', $body);
        self::assertStringContainsString('address=203.0.113.101 ', $body);
    }

    public function testExcludeCidr4(): void {
        $body = $this->script(['data' => 'cidr4', 'exclude[cidr4]' => '203.0.113.0/24']);
        self::assertStringNotContainsString('address=203.0.113.0/24', $body);
        self::assertStringContainsString('address=198.51.100.0/24', $body);
    }

    public function testExcludeIp6(): void {
        $body = $this->script(['data' => 'ip6', 'exclude[ip6]' => '2001:db8::1']);
        self::assertStringNotContainsString('address=2001:db8::1 ', $body);
        self::assertStringContainsString('address=2001:db8::2 ', $body);
    }

    public function testExcludeCidr6(): void {
        $body = $this->script(['data' => 'cidr6', 'exclude[cidr6]' => '2001:db8::/48']);
        self::assertStringNotContainsString('address=2001:db8::/48', $body);
        self::assertStringContainsString('address=2001:db8:1::/48', $body);
    }

    public function testExcludeDomain(): void {
        $body = $this->script(['data' => 'domains', 'exclude[domain]' => 'game-a.com']);
        self::assertStringNotContainsString('address=game-a.com ', $body);
        self::assertStringContainsString('address=www.game-a.com ', $body);
        self::assertStringContainsString('address=game-b.com ', $body);
    }

    public function testWildcardCollapsesDomains(): void {
        $body = $this->script(['data' => 'domains', 'wildcard' => '1']);
        self::assertStringContainsString('address=game-a.com ', $body);
        self::assertStringNotContainsString('address=www.game-a.com ', $body);
        self::assertStringContainsString('address=game-a.co.uk ', $body);
    }

    public function testFilesaveAddsTxtAttachmentHeader(): void {
        // mikrotik isn't in the filesave ext map → falls back to .txt
        $response = $this->get('/', ['format' => 'mikrotik', 'data' => 'ip4', 'filesave' => '1']);
        self::assertStringContainsString('attachment', $response->getHeader('content-disposition') ?? '');
        self::assertStringContainsString('ip-list.txt', $response->getHeader('content-disposition') ?? '');
    }

    public function testGroupFilterCombinedWithExcludeSite(): void {
        $body = $this->script(['data' => 'ip4', 'group' => 'games', 'exclude[site]' => 'game-b']);
        self::assertStringContainsString('comment=game-a', $body);
        self::assertStringNotContainsString('comment=game-b', $body);
        self::assertStringNotContainsString('list="tools_ip4"', $body);
    }

    public function testSiteCombinedWithWildcardAndExcludeDomain(): void {
        $body = $this->script([
            'data' => 'domains',
            'site' => 'game-a',
            'wildcard' => '1',
            'exclude[domain]' => 'game-a.com',
        ]);
        self::assertStringNotContainsString('address=game-a.com ', $body);
        self::assertStringContainsString('address=game-a.co.uk ', $body);
        self::assertStringNotContainsString('comment=game-b', $body);
    }
}
