<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Helper;

use OpenCCK\AsyncTest;

final class IP6HelperTest extends AsyncTest {
    public function testMinimizeSubnetsEmpty(): void {
        self::assertSame([], IP6Helper::minimizeSubnets([]));
    }

    public function testMinimizeSubnetsSinglePassesThrough(): void {
        self::assertSame(['2001:db8::/32'], IP6Helper::minimizeSubnets(['2001:db8::/32']));
    }

    public function testMinimizeSubnetsDropsSubsumedSubnets(): void {
        self::assertSame(['2001:db8::/32'], IP6Helper::minimizeSubnets(['2001:db8::/32', '2001:db8:1::/48']));
    }

    public function testMinimizeSubnetsKeepsDistinctRanges(): void {
        // Regression for the 128-bit-on-64-bit-int bug: the previous
        // implementation computed every mask as 0 for prefix ≤ 64 and collapsed
        // any distinct ranges down to the first one. The fix delegates to
        // isInCidr, which does byte-wise string masking at full 128-bit width.
        $result = IP6Helper::minimizeSubnets(['2001:db8::/32', '2001:db9::/32', '2001:dba::/48']);
        self::assertEqualsCanonicalizing(['2001:db8::/32', '2001:db9::/32', '2001:dba::/48'], $result);
    }

    public function testMinimizeSubnetsHandlesUnorderedInput(): void {
        // Broader subnet listed after a narrower one — the sort must put
        // /32 ahead of /48 so the /48 is recognized as subsumed.
        self::assertSame(['2001:db8::/32'], IP6Helper::minimizeSubnets(['2001:db8:1::/48', '2001:db8::/32']));
    }

    public function testMinimizeSubnetsAtOrBelowSlash64(): void {
        // Regression pin for the 128-bit-int-overflow fix: prefixes ≤ /64
        // previously yielded a zero mask and collapsed every subnet after the
        // first. Distinct /32 and /48 ranges must survive, and a /64 inside
        // a /48 must be absorbed.
        $result = IP6Helper::minimizeSubnets([
            '2001:db8::/32',
            '2001:db9::/48',
            '2001:db9:0:1::/64', // inside 2001:db9::/48 → dropped
            '2001:dba::/64',
        ]);
        self::assertEqualsCanonicalizing(['2001:db8::/32', '2001:db9::/48', '2001:dba::/64'], $result);
    }

    public function testMinimizeSubnetsAbsorbsSlash128Inside64(): void {
        // /128 host entry inside a /64 must be dropped.
        self::assertSame(['2001:db8::/64'], IP6Helper::minimizeSubnets(['2001:db8::/64', '2001:db8::1/128']));
    }

    public function testSortSubnetsOrdersByAddressThenPrefix(): void {
        $result = IP6Helper::sortSubnets(['2001:db8:1::/48', '2001:db8::/32', '2001:db7::/32']);
        self::assertSame(['2001:db7::/32', '2001:db8::/32', '2001:db8:1::/48'], $result);
    }

    public function testIsInCidrMatchesContainedAddress(): void {
        self::assertTrue(IP6Helper::isInCidr('2001:db8::1', '2001:db8::/32'));
        self::assertTrue(IP6Helper::isInCidr('2001:db8:ffff::1', '2001:db8::/32'));
    }

    public function testIsInCidrRejectsOutOfRangeAddress(): void {
        self::assertFalse(IP6Helper::isInCidr('2001:db9::1', '2001:db8::/32'));
        self::assertFalse(IP6Helper::isInCidr('2001:db8:1::1', '2001:db8::/48'));
    }

    public function testIsInRangeChecksAnyCidr(): void {
        self::assertTrue(IP6Helper::isInRange('2001:db8::5', ['2001:db7::/32', '2001:db8::/32']));
        self::assertFalse(IP6Helper::isInRange('2001:db8::5', []));
    }

    public function testFormatShortIpMaskFullPrefix(): void {
        self::assertSame('ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff', IP6Helper::formatShortIpMask('128'));
    }

    public function testFormatShortIpMaskSlash64(): void {
        self::assertSame('ffff:ffff:ffff:ffff:0:0:0:0', IP6Helper::formatShortIpMask('64'));
    }

    public function testFormatShortIpMaskEmptyDefaultsTo128(): void {
        self::assertSame('ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff', IP6Helper::formatShortIpMask(''));
    }

    public function testTrimCIDRsCapsPrefixToSubnetPrefixCap(): void {
        // Default SYS_IP6_SUBNET_PREFIX_CAP is 64. Any /N where N > 64 gets capped.
        self::assertSame(['2001:db8::/32', '2001:db8::/64'], IP6Helper::trimCIDRs(['2001:db8::/32', '2001:db8::/128']));
    }

    public function testTrimCIDRsKeepsOnlyEntriesWithSlash(): void {
        self::assertSame(['2001:db8::/32'], IP6Helper::trimCIDRs(['2001:db8::/32', '2001:db8::', 'not-a-cidr']));
    }

    public function testProcessCIDREmptyInputReturnsEmpty(): void {
        // Same contract as IP4Helper::processCIDR — empty input short-circuits
        // before any shell call and passes through minimizeSubnets (no-op on []).
        self::assertSame([], IP6Helper::processCIDR([]));
    }

    public function testProcessCIDRSkipsLoopbackWithoutShelling(): void {
        self::assertSame([], IP6Helper::processCIDR(['::1']));
    }

    public function testProcessCIDRSkipsIpAlreadyInExistingRange(): void {
        // 2001:db8::5 is inside the pre-seeded /32 → isInRange short-circuits
        // the async body before any shell call.
        self::assertSame(['2001:db8::/32'], IP6Helper::processCIDR(['2001:db8::5'], ['2001:db8::/32']));
    }

    // ------------------------------------------------------------------
    // growReplace / applyReplace — the CIDR-replacement feature (IPv6)
    // ------------------------------------------------------------------

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

    public function testGrowReplaceAppendsContainedIpsAsSlash128(): void {
        $replace = $this->replace([], ['2001:db8::/32' => []]);
        IP6Helper::growReplace($replace, ['2001:db8::1', '2001:db8:ff::5', '2001:db9::1']);

        self::assertEqualsCanonicalizing(['2001:db8::1/128', '2001:db8:ff::5/128'], $replace->cidr6->{'2001:db8::/32'});
    }

    public function testGrowReplaceIsIdempotent(): void {
        $replace = $this->replace([], ['2001:db8::/32' => []]);
        IP6Helper::growReplace($replace, ['2001:db8::1', '2001:db8::2']);
        $after1 = $replace->cidr6->{'2001:db8::/32'};
        IP6Helper::growReplace($replace, ['2001:db8::1', '2001:db8::2']);
        self::assertSame($after1, $replace->cidr6->{'2001:db8::/32'});
    }

    public function testGrowReplaceAbsorbsHostEntriesIntoAdminProvidedSubnet(): void {
        // /48 provided by admin absorbs /128 host entries inside it.
        $replace = $this->replace([], ['2001:db8::/32' => ['2001:db8:1::/48']]);
        IP6Helper::growReplace($replace, ['2001:db8:1::5', '2001:db8:2::9']);

        self::assertEqualsCanonicalizing(['2001:db8:1::/48', '2001:db8:2::9/128'], $replace->cidr6->{'2001:db8::/32'});
    }

    public function testGrowReplaceNoOpWhenCidr6MissingOrWrongType(): void {
        $replace = (object) [];
        IP6Helper::growReplace($replace, ['2001:db8::1']);
        self::assertFalse(isset($replace->cidr6));

        $replace = (object) ['cidr6' => []];
        IP6Helper::growReplace($replace, ['2001:db8::1']);
        self::assertSame([], $replace->cidr6);
    }

    public function testApplyReplaceDropsKeyAndSubstitutesValues(): void {
        $replace = $this->replace([], ['2001:db8::/32' => ['2001:db8:1::/48']]);
        $result = IP6Helper::applyReplace(['fe80::/10', '2001:db8::/32'], $replace);
        self::assertSame(['fe80::/10', '2001:db8:1::/48'], $result);
    }

    public function testApplyReplaceEmptyValueArrayDropsKeyEntirely(): void {
        $replace = $this->replace([], ['2001:db8::/32' => []]);
        $result = IP6Helper::applyReplace(['2001:db8::/32', '2001:db9::/32'], $replace);
        self::assertSame(['2001:db9::/32'], $result);
    }

    public function testApplyReplaceReturnsCidrsUnchangedWhenCidr6Missing(): void {
        $replace = (object) [];
        self::assertSame(['2001:db8::/32'], IP6Helper::applyReplace(['2001:db8::/32'], $replace));
    }

    // ------------------------------------------------------------------
    // aggregateSubnets — CIDR supernetting (IPv6)
    // ------------------------------------------------------------------

    public function testAggregateSubnetsEmptyReturnsEmpty(): void {
        self::assertSame([], IP6Helper::aggregateSubnets([]));
    }

    public function testAggregateSubnetsMergesFourConsecutiveSlash128IntoSlash126(): void {
        self::assertSame(
            ['2001:db8::/126'],
            IP6Helper::aggregateSubnets([
                '2001:db8::/128',
                '2001:db8::1/128',
                '2001:db8::2/128',
                '2001:db8::3/128',
            ])
        );
    }

    public function testAggregateSubnetsMergesAdjacentRanges(): void {
        // /64 + adjacent /64 → /63.
        self::assertSame(
            ['2001:db8::/63'],
            IP6Helper::aggregateSubnets(['2001:db8::/64', '2001:db8:0:1::/64'])
        );
    }

    public function testAggregateSubnetsCollapsesOverlappingRanges(): void {
        self::assertSame(
            ['2001:db8::/48'],
            IP6Helper::aggregateSubnets(['2001:db8::/48', '2001:db8::dead:beef/128'])
        );
    }

    public function testAggregateSubnetsKeepsDisjointRanges(): void {
        $result = IP6Helper::aggregateSubnets(['2001:db8::/48', '2001:db9::/48']);
        self::assertSame(['2001:db8::/48', '2001:db9::/48'], $result);
    }

    public function testAggregateSubnetsRespectsParentCapBelowFullCoverage(): void {
        // Four /128 hosts fill /126 entirely; the parent is /126 → cap forces
        // strictly narrower /127 blocks.
        $result = IP6Helper::aggregateSubnets(
            ['2001:db8::/128', '2001:db8::1/128', '2001:db8::2/128', '2001:db8::3/128'],
            '2001:db8::/126'
        );
        self::assertSame(['2001:db8::/127', '2001:db8::2/127'], $result);
    }

    public function testAggregateSubnetsNeverEmitsParentItself(): void {
        self::assertSame(
            ['2001:db8::/33', '2001:db8:8000::/33'],
            IP6Helper::aggregateSubnets(['2001:db8::/32'], '2001:db8::/32')
        );
    }

    public function testAggregateSubnetsSkipsMalformedEntries(): void {
        self::assertSame(
            ['2001:db8::/48'],
            IP6Helper::aggregateSubnets(['not-a-cidr', '', '2001:db8::/48', '2001:db8::/200'])
        );
    }

    public function testAggregateSubnetsIdempotent(): void {
        $first = IP6Helper::aggregateSubnets([
            '2001:db8::/128',
            '2001:db8::1/128',
            '2001:db8::2/128',
            '2001:db8::3/128',
        ]);
        $second = IP6Helper::aggregateSubnets($first);
        self::assertSame($first, $second);
    }

    public function testGrowReplaceWithAggregateFusesContiguousIps(): void {
        $replace = $this->replace([], ['2001:db8::/32' => []]);
        IP6Helper::growReplace(
            $replace,
            ['2001:db8::', '2001:db8::1', '2001:db8::2', '2001:db8::3'],
            true
        );
        self::assertSame(['2001:db8::/126'], $replace->cidr6->{'2001:db8::/32'});
    }

    public function testGrowReplaceWithAggregateNeverProducesParentKey(): void {
        $replace = $this->replace([], ['2001:db8::/126' => []]);
        IP6Helper::growReplace(
            $replace,
            ['2001:db8::', '2001:db8::1', '2001:db8::2', '2001:db8::3'],
            true
        );
        self::assertSame(['2001:db8::/127', '2001:db8::2/127'], $replace->cidr6->{'2001:db8::/126'});
    }

    public function testGrowReplaceDefaultDoesNotAggregate(): void {
        $replace = $this->replace([], ['2001:db8::/32' => []]);
        IP6Helper::growReplace($replace, ['2001:db8::', '2001:db8::1']);
        self::assertEqualsCanonicalizing(
            ['2001:db8::/128', '2001:db8::1/128'],
            $replace->cidr6->{'2001:db8::/32'}
        );
    }

    // ------------------------------------------------------------------
    // Density collapse — lossy /64-bucket expansion (IPv6)
    // ------------------------------------------------------------------

    public function testAggregateSubnetsDensityCollapsesScatteredSlash128(): void {
        // 5 scattered /128s in a single /64 — lossless would keep them all
        // (non-adjacent). With threshold 5, the whole /64 is claimed.
        $cidrs = [
            '2001:db8::/128',
            '2001:db8::1/128',
            '2001:db8::5/128',
            '2001:db8::a/128',
            '2001:db8::b/128',
        ];
        self::assertSame(['2001:db8::/64'], IP6Helper::aggregateSubnets($cidrs, '2001:db8::/32', 5));
    }

    public function testAggregateSubnetsDensityBelowThresholdKeepsLosslessResult(): void {
        // Threshold 6 not reached — lossless aggregation runs its course:
        // ::/128 + ::1/128 → ::/127; ::a/128 + ::b/128 → ::a/127; ::5/128 alone.
        $cidrs = [
            '2001:db8::/128',
            '2001:db8::1/128',
            '2001:db8::5/128',
            '2001:db8::a/128',
            '2001:db8::b/128',
        ];
        self::assertSame(
            ['2001:db8::/127', '2001:db8::5/128', '2001:db8::a/127'],
            IP6Helper::aggregateSubnets($cidrs, '2001:db8::/32', 6)
        );
    }

    public function testAggregateSubnetsDensityCountsCoveredAddressesNotBlocks(): void {
        // One /126 (4 addresses) + one /128 = 5 covered addresses in the /64.
        $cidrs = ['2001:db8::/126', '2001:db8::ff/128'];
        // Threshold 5 → claim the whole /64. Threshold 6 → keep originals.
        self::assertSame(['2001:db8::/64'], IP6Helper::aggregateSubnets($cidrs, '2001:db8::/32', 5));
        self::assertSame($cidrs, IP6Helper::aggregateSubnets($cidrs, '2001:db8::/32', 6));
    }

    public function testAggregateSubnetsDensitySkippedWhenParentPrefixAtLeast64(): void {
        // Parent /64 — no room for /64 buckets inside. Density skipped;
        // standard aggregation + cap applies (forces /65+).
        $result = IP6Helper::aggregateSubnets(
            ['2001:db8::/128', '2001:db8::1/128', '2001:db8::2/128'],
            '2001:db8::/64',
            2
        );
        self::assertSame(['2001:db8::/127', '2001:db8::2/128'], $result);
    }

    public function testAggregateSubnetsDensityZeroIsIdentityToLossless(): void {
        $cidrs = ['2001:db8::1/128', '2001:db8::50/128', '2001:db8::100/128'];
        $lossless = IP6Helper::aggregateSubnets($cidrs, '2001:db8::/32');
        $withZero = IP6Helper::aggregateSubnets($cidrs, '2001:db8::/32', 0);
        self::assertSame($lossless, $withZero);
    }

    public function testGrowReplacePassesDensityThresholdThrough(): void {
        $replace = $this->replace([], ['2001:db8::/32' => []]);
        $ips = array_map(fn(int $i) => '2001:db8::' . dechex($i * 16), range(0, 9));
        IP6Helper::growReplace($replace, $ips, true, 10);
        self::assertSame(['2001:db8::/64'], $replace->cidr6->{'2001:db8::/32'});
    }

    // ------------------------------------------------------------------
    // Density collapse — wide /32 tier
    // ------------------------------------------------------------------

    public function testAggregateSubnetsDensityWideCollapsesAcrossSlash64s(): void {
        // Four /64 blocks in different /64 buckets within one /32. Each /64
        // is bucket-sized and passes through the narrow tier. At /32 level,
        // each /64 contributes 2^64 addresses (saturated to PHP_INT_MAX) →
        // threshold ≥ 1 is reached trivially.
        $cidrs = array_map(fn(int $s) => sprintf('2001:db8:%x::/64', $s), range(0, 3));
        self::assertSame(['2001:db8::/32'], IP6Helper::aggregateSubnets($cidrs, '2001:db8::/16', 0, 1));
    }

    public function testAggregateSubnetsDensityTieredPyramidFeedsWideFromNarrow(): void {
        // Each /64 has one scattered /128 → narrow threshold 1 inflates to
        // full /64. Four inflated /64s within one /32 then feed the wide
        // tier (threshold 1).
        $cidrs = [];
        for ($s = 0; $s < 4; $s++) {
            $cidrs[] = sprintf('2001:db8:%x::1/128', $s);
        }
        self::assertSame(['2001:db8::/32'], IP6Helper::aggregateSubnets($cidrs, '2001:db8::/16', 1, 1));
    }

    public function testAggregateSubnetsDensityWideSkippedWhenParentPrefixAtLeast32(): void {
        // Parent /32 — no room for /32 buckets. Wide tier skipped; narrow
        // still runs (parent=32 < 64).
        $result = IP6Helper::aggregateSubnets(
            ['2001:db8::/128', '2001:db8::1/128', '2001:db8::2/128'],
            '2001:db8::/32',
            2,
            2
        );
        self::assertSame(['2001:db8::/64'], $result);
    }

    public function testAggregateSubnetsDensityWideZeroDoesNotTrigger(): void {
        // Two non-adjacent /64s across different /32 buckets. With both
        // tiers disabled (0, 0), lossless aggregation leaves them intact.
        $cidrs = ['2001:db8::/64', '2001:db9::/64'];
        $result = IP6Helper::aggregateSubnets($cidrs, '2001:0::/8', 0, 0);
        self::assertSame($cidrs, $result);
    }

    public function testGrowReplacePassesBothDensityThresholdsThrough(): void {
        $replace = $this->replace([], ['2001:db8::/16' => []]);
        $ips = array_map(fn(int $s) => sprintf('2001:db8:%x::1', $s), range(0, 3));
        IP6Helper::growReplace($replace, $ips, true, 1, 1);
        self::assertSame(['2001:db8::/32'], $replace->cidr6->{'2001:db8::/16'});
    }
}
