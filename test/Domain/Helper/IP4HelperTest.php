<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Helper;

use OpenCCK\AsyncTest;

/**
 * Extends AsyncTest (not plain TestCase) because IP4Helper::processCIDR uses
 * Amp\async(...)->await() — calling await() outside a fiber throws FiberError.
 * AsyncTestCase wraps each test in a fiber for us.
 */
final class IP4HelperTest extends AsyncTest {
    public function testMinimizeSubnetsEmpty(): void {
        self::assertSame([], IP4Helper::minimizeSubnets([]));
    }

    public function testMinimizeSubnetsSinglePassesThrough(): void {
        self::assertSame(['10.0.0.0/24'], IP4Helper::minimizeSubnets(['10.0.0.0/24']));
    }

    public function testMinimizeSubnetsDropsSubsumedSubnets(): void {
        // /24 is contained in /8 → dropped.
        self::assertSame(['10.0.0.0/8'], IP4Helper::minimizeSubnets(['10.0.0.0/8', '10.0.0.0/24']));
    }

    public function testMinimizeSubnetsHandlesUnorderedInput(): void {
        // The internal sortSubnets() must put the shorter prefix first so it
        // wins over the /24 regardless of input order.
        self::assertSame(['10.0.0.0/8'], IP4Helper::minimizeSubnets(['10.0.0.0/24', '10.0.0.0/8', '10.1.2.3/32']));
    }

    public function testMinimizeSubnetsKeepsDistinctRanges(): void {
        $result = IP4Helper::minimizeSubnets(['10.0.0.0/24', '11.0.0.0/24', '192.0.2.0/24']);
        self::assertEqualsCanonicalizing(['10.0.0.0/24', '11.0.0.0/24', '192.0.2.0/24'], $result);
    }

    public function testMinimizeSubnetsFiltersFalsyEntries(): void {
        self::assertSame(['10.0.0.0/24'], IP4Helper::minimizeSubnets(['', '10.0.0.0/24', '0']));
    }

    public function testSortSubnetsSortsByIpThenPrefix(): void {
        self::assertSame(
            ['1.0.0.0/24', '10.0.0.0/8', '10.0.0.0/24'],
            IP4Helper::sortSubnets(['10.0.0.0/24', '1.0.0.0/24', '10.0.0.0/8'])
        );
    }

    public function testIsInCIDRMatchesContainedIp(): void {
        self::assertTrue(IP4Helper::isInCIDR('10.0.0.55', '10.0.0.0/24'));
        self::assertTrue(IP4Helper::isInCIDR('10.255.255.255', '10.0.0.0/8'));
    }

    public function testIsInCIDRRejectsOutOfRangeIp(): void {
        self::assertFalse(IP4Helper::isInCIDR('11.0.0.1', '10.0.0.0/24'));
        self::assertFalse(IP4Helper::isInCIDR('10.0.1.1', '10.0.0.0/24'));
    }

    public function testIsInRangeChecksAnyCidr(): void {
        self::assertTrue(IP4Helper::isInRange('192.0.2.5', ['10.0.0.0/8', '192.0.2.0/24']));
        self::assertFalse(IP4Helper::isInRange('203.0.113.1', ['10.0.0.0/8', '192.0.2.0/24']));
        self::assertFalse(IP4Helper::isInRange('10.0.0.1', []));
    }

    public function testFormatShortIpMaskForCommonPrefixes(): void {
        self::assertSame('0.0.0.0', IP4Helper::formatShortIpMask('0'));
        self::assertSame('255.0.0.0', IP4Helper::formatShortIpMask('8'));
        self::assertSame('255.255.0.0', IP4Helper::formatShortIpMask('16'));
        self::assertSame('255.255.255.0', IP4Helper::formatShortIpMask('24'));
        self::assertSame('255.255.255.255', IP4Helper::formatShortIpMask('32'));
    }

    public function testFormatShortIpMaskDefaultsToSlash32OnEmpty(): void {
        self::assertSame('255.255.255.255', IP4Helper::formatShortIpMask(''));
    }

    public function testTrimCIDRsKeepsOnlyEntriesWithSlash(): void {
        self::assertSame(
            ['10.0.0.0/8', '192.0.2.0/24'],
            IP4Helper::trimCIDRs(['10.0.0.0/8', '1.2.3.4', 'not-a-cidr', '192.0.2.0/24'])
        );
    }

    public function testProcessCIDREmptyInputReturnsEmpty(): void {
        self::assertSame([], IP4Helper::processCIDR([]));
    }

    public function testProcessCIDRSkipsLoopbackWithoutShelling(): void {
        // 127.0.0.1 is explicitly skipped at the top of the loop, so no shell
        // call fires and the returned set stays whatever was passed as $results.
        self::assertSame([], IP4Helper::processCIDR(['127.0.0.1']));
    }

    public function testProcessCIDRSkipsIpAlreadyInExistingRange(): void {
        // 10.0.0.5 is contained in the pre-seeded 10.0.0.0/8 → early-return in
        // the async body before any shell_exec runs. The returned CIDRs are
        // the pre-seeded ones, passed through minimizeSubnets.
        self::assertSame(['10.0.0.0/8'], IP4Helper::processCIDR(['10.0.0.5'], ['10.0.0.0/8']));
    }

    // ------------------------------------------------------------------
    // growReplace / applyReplace — the CIDR-replacement feature (IPv4)
    // ------------------------------------------------------------------

    /**
     * Builds a $replace-shaped stdClass for tests. cidr4 is an object
     * whose properties are CIDR strings mapping to arrays of replacement
     * entries — the same shape JSON decode produces.
     */
    private function replace(array $cidr4 = [], array $cidr6 = []): object {
        $build = function (array $map): \stdClass {
            $obj = new \stdClass();
            foreach ($map as $key => $values) {
                $obj->{$key} = $values;
            }
            return $obj;
        };
        return (object) ['cidr4' => $build($cidr4), 'cidr6' => $build($cidr6)];
    }

    public function testGrowReplaceAppendsContainedIpsAsSlash32(): void {
        $replace = $this->replace(['172.217.0.0/16' => []]);
        IP4Helper::growReplace($replace, ['172.217.17.206', '172.217.18.1', '1.2.3.4']);

        // 1.2.3.4 is outside the zone → not added. The two inside ones are
        // appended as /32. normalize + minimize leave them intact.
        self::assertEqualsCanonicalizing(['172.217.17.206/32', '172.217.18.1/32'], $replace->cidr4->{'172.217.0.0/16'});
    }

    public function testGrowReplaceIsIdempotent(): void {
        // Second call with the same ips must not grow the list further.
        $replace = $this->replace(['172.217.0.0/16' => []]);
        IP4Helper::growReplace($replace, ['172.217.17.206', '172.217.18.1']);
        $after1 = $replace->cidr4->{'172.217.0.0/16'};
        IP4Helper::growReplace($replace, ['172.217.17.206', '172.217.18.1']);
        $after2 = $replace->cidr4->{'172.217.0.0/16'};

        self::assertSame($after1, $after2);
    }

    public function testGrowReplaceAbsorbsHostEntriesIntoAdminProvidedSubnet(): void {
        // Admin put /24 in the value array explicitly. A /32 inside that /24
        // must be absorbed by minimizeSubnets, so the final list stays minimal.
        $replace = $this->replace(['172.217.0.0/16' => ['172.217.18.0/24']]);
        IP4Helper::growReplace($replace, ['172.217.18.5', '172.217.19.9']);

        self::assertEqualsCanonicalizing(['172.217.18.0/24', '172.217.19.9/32'], $replace->cidr4->{'172.217.0.0/16'});
    }

    public function testGrowReplaceNoOpWhenCidr4MissingOrWrongType(): void {
        // Missing cidr4 — no-op, no exception.
        $replace = (object) [];
        IP4Helper::growReplace($replace, ['1.2.3.4']);
        self::assertFalse(isset($replace->cidr4));

        // Wrong type on cidr4 (array, not object) — treated as missing.
        $replace = (object) ['cidr4' => []];
        IP4Helper::growReplace($replace, ['1.2.3.4']);
        self::assertSame([], $replace->cidr4);
    }

    public function testGrowReplaceEmptyMapIsNoOp(): void {
        $replace = $this->replace([]);
        IP4Helper::growReplace($replace, ['1.2.3.4']);
        self::assertSame([], (array) $replace->cidr4);
    }

    public function testApplyReplaceDropsKeyAndSubstitutesValues(): void {
        $replace = $this->replace(['172.217.0.0/16' => ['172.217.17.206/32', '172.217.18.0/24']]);
        $result = IP4Helper::applyReplace(['10.0.0.0/8', '172.217.0.0/16', '203.0.113.0/24'], $replace);
        // Key removed, values appended. Order: leftover cidrs first, then values.
        self::assertSame(['10.0.0.0/8', '203.0.113.0/24', '172.217.17.206/32', '172.217.18.0/24'], $result);
    }

    public function testApplyReplaceKeepsKeyValuesWhenKeyAbsentFromCidrs(): void {
        // Admin may use `replace` to augment the output even when the
        // cidr is not present in the source list.
        $replace = $this->replace(['1.2.3.0/24' => ['1.2.3.4/32']]);
        $result = IP4Helper::applyReplace(['10.0.0.0/8'], $replace);
        self::assertSame(['10.0.0.0/8', '1.2.3.4/32'], $result);
    }

    public function testApplyReplaceEmptyValueArrayDropsKeyEntirely(): void {
        $replace = $this->replace(['172.217.0.0/16' => []]);
        $result = IP4Helper::applyReplace(['10.0.0.0/8', '172.217.0.0/16'], $replace);
        self::assertSame(['10.0.0.0/8'], $result);
    }

    public function testApplyReplaceReturnsCidrsUnchangedWhenCidr4Missing(): void {
        $replace = (object) [];
        self::assertSame(
            ['10.0.0.0/8', '172.217.0.0/16'],
            IP4Helper::applyReplace(['10.0.0.0/8', '172.217.0.0/16'], $replace)
        );
    }

    public function testApplyReplaceReturnsCidrsUnchangedWhenCidr4Empty(): void {
        $replace = $this->replace([]);
        self::assertSame(['10.0.0.0/8'], IP4Helper::applyReplace(['10.0.0.0/8'], $replace));
    }

    // ------------------------------------------------------------------
    // aggregateSubnets — CIDR supernetting (IPv4)
    // ------------------------------------------------------------------

    public function testAggregateSubnetsEmptyReturnsEmpty(): void {
        self::assertSame([], IP4Helper::aggregateSubnets([]));
    }

    public function testAggregateSubnetsMergesFourConsecutiveSlash32IntoSlash30(): void {
        self::assertSame(
            ['1.0.0.0/30'],
            IP4Helper::aggregateSubnets(['1.0.0.0/32', '1.0.0.1/32', '1.0.0.2/32', '1.0.0.3/32'])
        );
    }

    public function testAggregateSubnets256ConsecutiveSlash32IntoSlash24(): void {
        $inputs = [];
        for ($i = 0; $i < 256; $i++) {
            $inputs[] = "10.0.0.$i/32";
        }
        self::assertSame(['10.0.0.0/24'], IP4Helper::aggregateSubnets($inputs));
    }

    public function testAggregateSubnetsMergesAdjacentRanges(): void {
        // 10.0.0.0/24 and 10.0.1.0/24 are adjacent → /23.
        self::assertSame(['10.0.0.0/23'], IP4Helper::aggregateSubnets(['10.0.0.0/24', '10.0.1.0/24']));
    }

    public function testAggregateSubnetsCollapsesOverlappingRanges(): void {
        // /24 absorbs a /32 inside it; no residual /32 in output.
        self::assertSame(['10.0.0.0/24'], IP4Helper::aggregateSubnets(['10.0.0.0/24', '10.0.0.5/32']));
    }

    public function testAggregateSubnetsKeepsDisjointRanges(): void {
        $result = IP4Helper::aggregateSubnets(['10.0.0.0/24', '192.0.2.0/24']);
        self::assertSame(['10.0.0.0/24', '192.0.2.0/24'], $result);
    }

    public function testAggregateSubnetsRespectsParentCapBelowFullCoverage(): void {
        // Four /32s filling a /30 can aggregate to /30, but the parent is /30.
        // Cap forces output strictly narrower than /30 → two /31 blocks.
        $result = IP4Helper::aggregateSubnets(
            ['1.0.0.0/32', '1.0.0.1/32', '1.0.0.2/32', '1.0.0.3/32'],
            '1.0.0.0/30'
        );
        self::assertSame(['1.0.0.0/31', '1.0.0.2/31'], $result);
    }

    public function testAggregateSubnetsNeverEmitsParentItself(): void {
        // Input literally equals parent → must be split into two halves.
        self::assertSame(
            ['1.0.0.0/9', '1.128.0.0/9'],
            IP4Helper::aggregateSubnets(['1.0.0.0/8'], '1.0.0.0/8')
        );
    }

    public function testAggregateSubnetsSkipsMalformedEntries(): void {
        self::assertSame(
            ['10.0.0.0/24'],
            IP4Helper::aggregateSubnets(['not-a-cidr', '', '10.0.0.0/24', '10.0.0.0/99', 'bogus/24'])
        );
    }

    public function testAggregateSubnetsIdempotent(): void {
        $first = IP4Helper::aggregateSubnets(['1.0.0.0/32', '1.0.0.1/32', '1.0.0.2/32', '1.0.0.3/32']);
        $second = IP4Helper::aggregateSubnets($first);
        self::assertSame($first, $second);
    }

    public function testGrowReplaceWithAggregateFusesContiguousIps(): void {
        // 4 consecutive IPs inside the parent /16 — without aggregate they'd
        // stay as four /32 entries; with aggregate they collapse to one /30.
        $replace = $this->replace(['2.16.0.0/13' => []]);
        IP4Helper::growReplace(
            $replace,
            ['2.16.0.0', '2.16.0.1', '2.16.0.2', '2.16.0.3'],
            true
        );
        self::assertSame(['2.16.0.0/30'], $replace->cidr4->{'2.16.0.0/13'});
    }

    public function testGrowReplaceWithAggregateNeverProducesParentKey(): void {
        // Fill the entire parent /30 with /32s. Aggregation would normally
        // collapse to /30 (== parent), but the cap splits it into two /31s.
        $replace = $this->replace(['1.0.0.0/30' => []]);
        IP4Helper::growReplace(
            $replace,
            ['1.0.0.0', '1.0.0.1', '1.0.0.2', '1.0.0.3'],
            true
        );
        self::assertSame(['1.0.0.0/31', '1.0.0.2/31'], $replace->cidr4->{'1.0.0.0/30'});
    }

    public function testGrowReplaceDefaultDoesNotAggregate(): void {
        // Default behaviour preserved: without $aggregate, minimize keeps
        // individual /32 entries because they don't contain each other.
        $replace = $this->replace(['2.16.0.0/13' => []]);
        IP4Helper::growReplace($replace, ['2.16.0.0', '2.16.0.1']);
        self::assertEqualsCanonicalizing(
            ['2.16.0.0/32', '2.16.0.1/32'],
            $replace->cidr4->{'2.16.0.0/13'}
        );
    }

    // ------------------------------------------------------------------
    // Density collapse — lossy /24-bucket expansion (IPv4)
    // ------------------------------------------------------------------

    public function testAggregateSubnetsDensityCollapsesScatteredSlash32(): void {
        // 10 sparse /32s inside a single /24 — none are adjacent, so lossless
        // aggregation would keep all 10. With densityThreshold=10 the whole
        // /24 is claimed.
        $cidrs = [
            '2.16.0.5/32', '2.16.0.10/32', '2.16.0.20/32', '2.16.0.40/32', '2.16.0.50/32',
            '2.16.0.60/32', '2.16.0.70/32', '2.16.0.80/32', '2.16.0.90/32', '2.16.0.100/32',
        ];
        self::assertSame(['2.16.0.0/24'], IP4Helper::aggregateSubnets($cidrs, '2.16.0.0/13', 10));
    }

    public function testAggregateSubnetsDensityBelowThresholdKeepsOriginalBlocks(): void {
        $cidrs = [
            '2.16.0.5/32', '2.16.0.10/32', '2.16.0.20/32', '2.16.0.40/32', '2.16.0.50/32',
            '2.16.0.60/32', '2.16.0.70/32', '2.16.0.80/32', '2.16.0.90/32', '2.16.0.100/32',
        ];
        // Coverage = 10, threshold = 11 → no expansion.
        self::assertSame($cidrs, IP4Helper::aggregateSubnets($cidrs, '2.16.0.0/13', 11));
    }

    public function testAggregateSubnetsDensityCountsCoveredAddressesNotBlocks(): void {
        // Four blocks: one /30 (4 addresses) + three /32s = 7 addresses.
        // Threshold 7 should trigger the collapse; threshold 8 should not.
        $cidrs = ['10.0.0.0/30', '10.0.0.100/32', '10.0.0.120/32', '10.0.0.150/32'];
        self::assertSame(['10.0.0.0/24'], IP4Helper::aggregateSubnets($cidrs, '10.0.0.0/16', 7));
        self::assertSame($cidrs, IP4Helper::aggregateSubnets($cidrs, '10.0.0.0/16', 8));
    }

    public function testAggregateSubnetsDensityMergesAdjacentExpandedBuckets(): void {
        // Two adjacent /24s each reach the threshold → both expand → they
        // touch at the boundary → re-merge fuses them into /23.
        $cidrs = array_merge(
            array_map(fn(int $i) => "1.0.0.$i/32", range(0, 9)),
            array_map(fn(int $i) => "1.0.1.$i/32", range(0, 9))
        );
        self::assertSame(['1.0.0.0/23'], IP4Helper::aggregateSubnets($cidrs, '1.0.0.0/8', 10));
    }

    public function testAggregateSubnetsDensitySkippedWhenParentPrefixAtLeast24(): void {
        // Parent is /24 itself — no room for /24 buckets inside. Density
        // logic is skipped; standard aggregation applies (with parent cap
        // forcing /25+).
        $result = IP4Helper::aggregateSubnets(
            ['1.0.0.0/32', '1.0.0.1/32', '1.0.0.2/32'],
            '1.0.0.0/24',
            2
        );
        self::assertSame(['1.0.0.0/31', '1.0.0.2/32'], $result);
    }

    public function testAggregateSubnetsDensityZeroIsIdentityToLossless(): void {
        $cidrs = ['1.0.0.0/32', '1.0.0.50/32', '1.0.0.100/32'];
        $lossless = IP4Helper::aggregateSubnets($cidrs, '1.0.0.0/8');
        $withZero = IP4Helper::aggregateSubnets($cidrs, '1.0.0.0/8', 0);
        self::assertSame($lossless, $withZero);
    }

    public function testGrowReplacePassesDensityThresholdThrough(): void {
        $replace = $this->replace(['2.16.0.0/13' => []]);
        // 20 scattered IPs in /24 at 2.16.0.0 — enough to trip threshold 15.
        $ips = array_map(fn(int $i) => '2.16.0.' . ($i * 10), range(0, 19));
        IP4Helper::growReplace($replace, $ips, true, 15);
        self::assertSame(['2.16.0.0/24'], $replace->cidr4->{'2.16.0.0/13'});
    }

    // ------------------------------------------------------------------
    // Density collapse — wide /16 tier
    // ------------------------------------------------------------------

    public function testAggregateSubnetsDensityWideCollapsesAcrossSlash24s(): void {
        // 40 /32s spread across 4 different /24s within one /16. Each /24
        // has only 10 covered addresses — under a narrow tier threshold of
        // 11 no /24 inflates. But the wide tier sees 40 addresses in the
        // /16 and claims the whole /16.
        $cidrs = [];
        for ($s = 0; $s < 4; $s++) {
            for ($i = 0; $i < 10; $i++) {
                $cidrs[] = "100.64.$s.$i/32";
            }
        }
        self::assertSame(['100.64.0.0/16'], IP4Helper::aggregateSubnets($cidrs, '100.64.0.0/10', 11, 40));
    }

    public function testAggregateSubnetsDensityTieredPyramidFeedsWideFromNarrow(): void {
        // Each /24 reaches narrow threshold (5) → inflated to full /24 (256
        // addresses). Four inflated /24s give 1024 addresses in the /16 →
        // wide threshold (1000) triggers → /16 is claimed.
        $cidrs = [];
        for ($s = 0; $s < 4; $s++) {
            for ($i = 0; $i < 10; $i++) {
                $cidrs[] = "100.64.$s.$i/32";
            }
        }
        self::assertSame(['100.64.0.0/16'], IP4Helper::aggregateSubnets($cidrs, '100.64.0.0/10', 5, 1000));
    }

    public function testAggregateSubnetsDensityWideSkippedWhenParentPrefixAtLeast16(): void {
        // Parent /16 leaves no room for /16 buckets — wide tier skipped.
        // Narrow tier still runs (parent=16 < 24).
        $result = IP4Helper::aggregateSubnets(
            ['100.64.0.0/32', '100.64.0.1/32', '100.64.0.2/32', '100.64.0.3/32'],
            '100.64.0.0/16',
            4,
            4
        );
        // Narrow threshold 4 + 4 coverage → /24 inflates. Wide is skipped.
        self::assertSame(['100.64.0.0/24'], $result);
    }

    public function testAggregateSubnetsDensityWideZeroDoesNotTrigger(): void {
        // wide=0 means disabled — only narrow runs (if set).
        $cidrs = array_map(fn(int $i) => "100.64.$i.0/32", range(0, 9));
        $result = IP4Helper::aggregateSubnets($cidrs, '100.64.0.0/10', 0, 0);
        self::assertSame($cidrs, $result);
    }

    public function testGrowReplacePassesBothDensityThresholdsThrough(): void {
        $replace = $this->replace(['100.64.0.0/10' => []]);
        $ips = [];
        for ($s = 0; $s < 4; $s++) {
            for ($i = 0; $i < 10; $i++) {
                $ips[] = "100.64.$s.$i";
            }
        }
        IP4Helper::growReplace($replace, $ips, true, 5, 1000);
        self::assertSame(['100.64.0.0/16'], $replace->cidr4->{'100.64.0.0/10'});
    }
}
