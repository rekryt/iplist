<?php

declare(strict_types=1);

namespace OpenCCK;

use Amp\Http\Client\Request;
use OpenCCK\Domain\Entity\Site;

/**
 * Large-fixture stress test. Gated behind RUN_STRESS=1 so CI stays fast.
 *
 * The test runs a ladder of increasing workloads against the original failing
 * query path (/?format=mikrotik&data=ip4&exclude[group]=casino) so we can see
 * where the implementation starts to sag. Every ladder step must pass the
 * per-step budget below; any regression blows past budget and fails loudly.
 *
 * The classic hotspot to guard against is quadratic dedup across sites that
 * share a single group — 20 sites in one group keeps enough pressure on that
 * code path to make a regression visible without running for minutes.
 *
 * Run manually:
 *   RUN_STRESS=1 ./vendor/bin/phpunit --filter StressTest
 */
final class StressTest extends AsyncTest {
    private const SITES_IN_HOT_GROUP = 20;

    /**
     * Two ladder steps are enough to watch optimization efficiency: a small
     * baseline that should stay trivially fast, and a medium point where any
     * accidental quadratic behaviour shows up immediately against the budget.
     *
     * @return array<string, array{int, float}> [ips_per_site, budget_seconds]
     */
    public static function ladder(): array {
        return [
            'ladder-00100-ips' => [100, 5.0],
            'ladder-01000-ips' => [1_000, 15.0],
        ];
    }

    protected function setUp(): void {
        parent::setUp();
        if (getenv('RUN_STRESS') !== '1') {
            self::markTestSkipped('Set RUN_STRESS=1 to run stress tests.');
        }
        $this->setTimeout(180.0);
    }

    /**
     * @dataProvider ladder
     */
    public function testMikrotikExcludeGroupCasinoUnderLoad(int $ipsPerSite, float $budget): void {
        $service = $this->service();
        $original = $service->sites;

        try {
            $service->sites = $this->buildStressFixture($ipsPerSite);
            $totalIps = self::SITES_IN_HOT_GROUP * $ipsPerSite;

            $start = microtime(true);
            $memBefore = memory_get_peak_usage(true);

            $request = new Request(
                $this->buildUrl('/', [
                    'format' => 'mikrotik',
                    'data' => 'ip4',
                    'exclude[group]' => 'casino',
                ]),
                'GET'
            );
            $request->setBodySizeLimit(512 * 1024 * 1024);
            $request->setTransferTimeout($budget * 3);
            $request->setInactivityTimeout($budget * 3);

            $response = $this->httpClient->request($request);
            $body = $this->body($response);

            $duration = microtime(true) - $start;
            $memDelta = memory_get_peak_usage(true) - $memBefore;

            self::assertSame(200, $response->getStatus());
            self::assertNotEmpty($body);
            self::assertStringContainsString('list="games_ip4"', $body);
            self::assertStringContainsString('list="tools_ip4"', $body);
            self::assertStringNotContainsString('list="casino_ip4"', $body);

            fprintf(
                STDERR,
                "\n[stress %d×%d=%d IPs] duration=%.2fs budget=%.0fs peak-delta=%.1f MB body=%.1f MB\n",
                self::SITES_IN_HOT_GROUP,
                $ipsPerSite,
                $totalIps,
                $duration,
                $budget,
                $memDelta / 1_048_576,
                strlen($body) / 1_048_576
            );

            self::assertLessThan(
                $budget,
                $duration,
                sprintf(
                    'exceeded %.0fs budget at %d IPs/site (%d total) — O(N^2) dedup regression?',
                    $budget,
                    $ipsPerSite,
                    $totalIps
                )
            );
        } finally {
            $service->sites = $original;
        }
    }

    /**
     * @return array<string, Site>
     */
    private function buildStressFixture(int $ipsPerSite): array {
        $sites = [];
        $counter = 0;

        $ipFor = function () use (&$counter): string {
            $n = $counter++;
            return sprintf('100.%d.%d.%d', 64 + intdiv($n, 65536), ($n >> 8) & 0xff, $n & 0xff);
        };

        for ($s = 0; $s < self::SITES_IN_HOT_GROUP; $s++) {
            $name = 'games-stress-' . $s;
            $ips = [];
            for ($i = 0; $i < $ipsPerSite; $i++) {
                $ips[] = $ipFor();
            }
            $sites[$name] = new Site($name, 'games', [], [], 0, $ips, [], [], []);
        }

        $sites['tools-small'] = new Site('tools-small', 'tools', [], [], 0, [$ipFor()], [], [], []);
        $sites['casino-small'] = new Site('casino-small', 'casino', [], [], 0, [$ipFor()], [], [], []);

        return $sites;
    }

    /**
     * Regression guard for the quadratic blow-up in `IP4Helper::minimizeSubnets`
     * when a portal's `replace.cidr4` expands into tens of thousands of /32
     * entries (the Adobe production scenario). Before the O(N log N) rewrite
     * this scenario ran for ~100 s at 10 000 entries; budget here is 5 s,
     * leaving plenty of headroom on slow CI while making any reintroduced
     * O(N²) scan fail loudly.
     *
     * @return array<string, array{int, float}> [slash32_count, budget_seconds]
     */
    public static function replaceLadder(): array {
        return [
            'replace-05000-slash32' => [5_000, 2.5],
            'replace-15000-slash32' => [15_000, 5.0],
        ];
    }

    /**
     * @dataProvider replaceLadder
     */
    public function testTextCidr4WithHugeReplaceMap(int $slash32Count, float $budget): void {
        $service = $this->service();
        $original = $service->sites;

        try {
            $service->sites = $this->buildReplaceFixture($slash32Count);

            $start = microtime(true);

            $request = new Request(
                $this->buildUrl('/', ['format' => 'text', 'data' => 'cidr4']),
                'GET'
            );
            $request->setBodySizeLimit(512 * 1024 * 1024);
            $request->setTransferTimeout($budget * 3);
            $request->setInactivityTimeout($budget * 3);

            $response = $this->httpClient->request($request);
            $body = $this->body($response);
            $duration = microtime(true) - $start;

            self::assertSame(200, $response->getStatus());
            self::assertNotEmpty($body);
            // Parent replace key must be gone from the output (substituted).
            self::assertStringNotContainsString('100.0.0.0/8', $body);
            // A representative /32 from the value array must be present —
            // `buildReplaceFixture` starts its IP walk at 100.64.0.0 and
            // emits one entry per $i, so 100.64.0.0 is always the first.
            self::assertStringContainsString('100.64.0.0/32', $body);

            fprintf(
                STDERR,
                "\n[stress text/cidr4 replace=%d /32s] duration=%.2fs budget=%.0fs body=%.1f MB\n",
                $slash32Count,
                $duration,
                $budget,
                strlen($body) / 1_048_576
            );

            self::assertLessThan(
                $budget,
                $duration,
                sprintf(
                    'exceeded %.1fs budget at %d /32s in replace.cidr4 — O(N^2) regression in minimizeSubnets?',
                    $budget,
                    $slash32Count
                )
            );
        } finally {
            $service->sites = $original;
        }
    }

    /**
     * One portal whose `replace.cidr4` maps parent `100.0.0.0/8` to an array
     * of `$count` scattered /32 entries — worst case for the containment
     * scan because no /32 is contained in any other, so the inner loop of
     * the old `minimizeSubnets` never short-circuits.
     *
     * @return array<string, Site>
     */
    private function buildReplaceFixture(int $count): array {
        $values = [];
        for ($i = 0; $i < $count; $i++) {
            $values[] = sprintf('100.%d.%d.%d/32', 64 + ($i >> 16), ($i >> 8) & 0xff, $i & 0xff);
        }
        $replace = (object) [
            'cidr4' => (object) ['100.0.0.0/8' => $values],
            'cidr6' => new \stdClass(),
        ];
        return [
            'adobe-like' => new Site(
                'adobe-like',
                'tools',
                [],
                [],
                0,
                [],
                [],
                ['100.0.0.0/8'],
                [],
                new \stdClass(),
                $replace
            ),
        ];
    }
}
