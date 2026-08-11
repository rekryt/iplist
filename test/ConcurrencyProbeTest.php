<?php

declare(strict_types=1);

namespace OpenCCK;

use Amp\Http\Client\Request;
use OpenCCK\Domain\Entity\Site;

use function Amp\async;
use function Amp\delay;

/**
 * Concurrency probe — observes, rather than asserts, how a lightweight request
 * behaves while a heavy Mikrotik request is in flight. Prints a line per run
 * with heavy/light durations so optimizations can be compared against today's
 * baseline.
 *
 * At small scale (10k IPs / 5s controller time) the event loop turns out to
 * stay largely responsive — a concurrent JSON request for a tiny site returns
 * in milliseconds. Raise HOT_SITES/IPS_PER_SITE when comparing behaviour at
 * prod-like scales.
 *
 * Gated behind RUN_STRESS=1 — injects stress fixtures and takes a few seconds.
 *
 * Run manually:
 *   RUN_STRESS=1 ./vendor/bin/phpunit --filter ConcurrencyProbeTest
 */
final class ConcurrencyProbeTest extends AsyncTest {
    private const HOT_SITES = 20;
    private const IPS_PER_SITE = 500;

    protected function setUp(): void {
        parent::setUp();
        if (getenv('RUN_STRESS') !== '1') {
            self::markTestSkipped('Set RUN_STRESS=1 to run the concurrency probe.');
        }
        $this->setTimeout(120.0);
    }

    public function testHeavyRequestBlocksLightweightRequest(): void {
        $service = $this->service();
        $original = $service->sites;

        try {
            $service->sites = $this->buildStressFixture();

            // Fire a deliberately heavy request in the background. It renders
            // 10k IPs through MikrotikController, which today runs O(N²) dedup
            // synchronously without yielding.
            $heavyStart = microtime(true);
            $heavyFuture = async(function () use ($heavyStart) {
                $request = new Request(
                    $this->buildUrl('/', [
                        'format' => 'mikrotik',
                        'data' => 'ip4',
                        'exclude[group]' => 'casino',
                    ]),
                    'GET'
                );
                $request->setBodySizeLimit(64 * 1024 * 1024);
                $request->setTransferTimeout(60.0);
                $request->setInactivityTimeout(60.0);
                $response = $this->httpClient->request($request);
                return [
                    'status' => $response->getStatus(),
                    'bytes' => strlen($this->body($response)),
                    'duration' => microtime(true) - $heavyStart,
                ];
            });

            // Give the server a beat to actually start processing the heavy
            // request before we fire the light one. Without this, both land
            // on the event loop back-to-back and the "blocking" picture is
            // less clear.
            delay(0.1);

            // Lightweight request — filters down to a single small site. If the
            // event loop were cooperative, this should respond in milliseconds.
            $lightStart = microtime(true);
            $lightResponse = $this->get('/', ['format' => 'json', 'site' => 'tools-small']);
            $lightDuration = microtime(true) - $lightStart;

            $heavy = $heavyFuture->await();

            fprintf(
                STDERR,
                "\n[concurrency] heavy=%.2fs light=%.2fs ratio=%.1f%% light_delayed_by=%.2fs\n",
                $heavy['duration'],
                $lightDuration,
                $lightDuration / max($heavy['duration'], 0.001) * 100,
                max($lightDuration - 0.05, 0.0)
            );

            self::assertSame(200, $heavy['status']);
            self::assertSame(200, $lightResponse->getStatus());

            // Observational — not a hard budget. The exact ratio depends on
            // where the heavy request spends its time (CPU dedup vs streaming
            // response bytes) and the stress ladder step chosen. Baselines:
            //   20×500 IPs today:  heavy ≈ 5s, light ≈ 10–20 ms → ratio < 1%
            //   20×1000 IPs today: heavy ≈ 14s, light ≈ ?      → TBD
            // Both durations should stay small and the ratio low; a regression
            // would show ratio creeping toward 100%.
            self::assertGreaterThan(0.0, $heavy['duration']);
            self::assertGreaterThan(0.0, $lightDuration);
        } finally {
            $service->sites = $original;
        }
    }

    /**
     * @return array<string, Site>
     */
    private function buildStressFixture(): array {
        $sites = [];
        $counter = 0;

        $ipFor = function () use (&$counter): string {
            $n = $counter++;
            return sprintf('100.%d.%d.%d', 64 + intdiv($n, 65536), ($n >> 8) & 0xff, $n & 0xff);
        };

        for ($s = 0; $s < self::HOT_SITES; $s++) {
            $name = 'games-stress-' . $s;
            $ips = [];
            for ($i = 0; $i < self::IPS_PER_SITE; $i++) {
                $ips[] = $ipFor();
            }
            $sites[$name] = new Site($name, 'games', [], [], 0, $ips, [], [], []);
        }

        $sites['tools-small'] = new Site('tools-small', 'tools', [], [], 0, [$ipFor()], [], [], []);
        $sites['casino-small'] = new Site('casino-small', 'casino', [], [], 0, [$ipFor()], [], [], []);

        return $sites;
    }
}
