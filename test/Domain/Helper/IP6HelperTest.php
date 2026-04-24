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
}
