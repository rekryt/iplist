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
}
