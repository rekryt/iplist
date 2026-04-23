<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\AsyncTest;

/**
 * Regression guard for the conditional-clone optimization in
 * `AbstractIPListController::getSites()`.
 *
 * For requests without `exclude[*]` and without `wildcard=1` the method
 * returns the live Site entity directly instead of a clone. This test pins
 * the invariant: no controller may modify any Site field while rendering, in
 * either the fast path or the clone path.
 *
 * If a future controller introduces a write to `$site->ip4`, `$site->domains`,
 * etc., this test fails, and the options are: restore the clone, or move the
 * mutation to a local buffer.
 */
final class SiteMutationGuardTest extends AsyncTest {
    /**
     * Snapshots every public field of every in-memory Site.
     *
     * @return array<string, array<string, mixed>>
     */
    private function snapshot(): array {
        $out = [];
        foreach ($this->service()->sites as $name => $site) {
            $out[$name] = [
                'name' => $site->name,
                'group' => $site->group,
                'domains' => $site->domains,
                'dns' => $site->dns,
                'timeout' => $site->timeout,
                'ip4' => $site->ip4,
                'ip6' => $site->ip6,
                'cidr4' => $site->cidr4,
                'cidr6' => $site->cidr6,
                'external' => $site->external,
            ];
        }
        return $out;
    }

    /**
     * Fast path — no `exclude[*]`, no `wildcard=1`. getSites() returns the
     * original Site entity by reference. If any controller writes to it,
     * the snapshot comparison fails.
     *
     * @dataProvider formatsWithoutFilters
     */
    public function testFastPathLeavesSitesUntouched(string $format, string $data): void {
        $before = $this->snapshot();
        $this->get('/', ['format' => $format, 'data' => $data]);
        self::assertEquals($before, $this->snapshot());
    }

    /** @return array<string, array{string, string}> */
    public static function formatsWithoutFilters(): array {
        return [
            'json-all'          => ['json', ''],
            'json-ip4'          => ['json', 'ip4'],
            'text-ip4'          => ['text', 'ip4'],
            'text-cidr4'        => ['text', 'cidr4'],
            'text-domains'      => ['text', 'domains'],
            'mikrotik-ip4'      => ['mikrotik', 'ip4'],
            'mikrotik-cidr4'    => ['mikrotik', 'cidr4'],
            'bat-ip4'           => ['bat', 'ip4'],
            'bat-cidr4'         => ['bat', 'cidr4'],
            'amnezia-ip4'       => ['amnezia', 'ip4'],
            'kvas-ip4'          => ['kvas', 'ip4'],
            'clashx-ip4'        => ['clashx', 'ip4'],
            'clashx-cidr4'      => ['clashx', 'cidr4'],
            'nfset-ip4'         => ['nfset', 'ip4'],
            'ipset-ip4'         => ['ipset', 'ip4'],
            'pac-cidr4'         => ['pac', 'cidr4'],
            'pac-domains'       => ['pac', 'domains'],
            'switchy-domains'   => ['switchy', 'domains'],
            'comma-ip4'         => ['comma', 'ip4'],
        ];
    }

    /**
     * Clone path — exclude[ip4]/wildcard=1 force the clone branch. Must also
     * leave the ORIGINAL Site entity unchanged (the filter modifies the clone).
     */
    public function testClonePathWithExcludeIp4LeavesOriginalUntouched(): void {
        $before = $this->snapshot();
        $this->get('/', [
            'format' => 'text',
            'data' => 'ip4',
            'exclude[ip4]' => ['203.0.113.1', '198.51.100.10'],
        ]);
        self::assertEquals($before, $this->snapshot());
    }

    public function testClonePathWithExcludeCidr4LeavesOriginalUntouched(): void {
        $before = $this->snapshot();
        $this->get('/', [
            'format' => 'text',
            'data' => 'cidr4',
            'exclude[cidr4]' => '203.0.113.0/24',
        ]);
        self::assertEquals($before, $this->snapshot());
    }

    public function testClonePathWithExcludeDomainLeavesOriginalUntouched(): void {
        $before = $this->snapshot();
        $this->get('/', [
            'format' => 'text',
            'data' => 'domains',
            'exclude[domain]' => 'game-a.com',
        ]);
        self::assertEquals($before, $this->snapshot());
    }

    public function testClonePathWithWildcardLeavesOriginalUntouched(): void {
        // wildcard=1 materializes collapsed domains on the clone. Must not
        // touch the original domains list.
        $before = $this->snapshot();
        $this->get('/', [
            'format' => 'text',
            'data' => 'domains',
            'wildcard' => '1',
        ]);
        self::assertEquals($before, $this->snapshot());
    }

    /**
     * Stress combination — every exclude type + wildcard at once. If any
     * filter in getSites() accidentally shares state with the source arrays
     * (e.g. `array_filter` on a by-reference alias), it surfaces here.
     */
    public function testClonePathWithAllExcludesLeavesOriginalUntouched(): void {
        $before = $this->snapshot();
        $this->get('/', [
            'format' => 'mikrotik',
            'data' => 'ip4',
            'wildcard' => '1',
            'exclude[domain]' => 'game-a.com',
            'exclude[ip4]' => '203.0.113.1',
            'exclude[cidr4]' => '203.0.113.0/24',
            'exclude[ip6]' => '2001:db8::1',
            'exclude[cidr6]' => '2001:db8::/48',
        ]);
        self::assertEquals($before, $this->snapshot());
    }
}
