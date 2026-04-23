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
        self::assertSame(
            ['2001:db8::/32'],
            IP6Helper::minimizeSubnets(['2001:db8::/32', '2001:db8:1::/48'])
        );
    }

    public function testMinimizeSubnetsKeepsDistinctRanges(): void {
        // Regression for the 128-bit-on-64-bit-int bug: the previous
        // implementation computed every mask as 0 for prefix ≤ 64 and collapsed
        // any distinct ranges down to the first one. The fix delegates to
        // isInCidr, which does byte-wise string masking at full 128-bit width.
        $result = IP6Helper::minimizeSubnets([
            '2001:db8::/32',
            '2001:db9::/32',
            '2001:dba::/48',
        ]);
        self::assertEqualsCanonicalizing(
            ['2001:db8::/32', '2001:db9::/32', '2001:dba::/48'],
            $result
        );
    }

    public function testMinimizeSubnetsHandlesUnorderedInput(): void {
        // Broader subnet listed after a narrower one — the sort must put
        // /32 ahead of /48 so the /48 is recognized as subsumed.
        self::assertSame(
            ['2001:db8::/32'],
            IP6Helper::minimizeSubnets(['2001:db8:1::/48', '2001:db8::/32'])
        );
    }

    public function testSortSubnetsOrdersByAddressThenPrefix(): void {
        $result = IP6Helper::sortSubnets([
            '2001:db8:1::/48',
            '2001:db8::/32',
            '2001:db7::/32',
        ]);
        self::assertSame(
            ['2001:db7::/32', '2001:db8::/32', '2001:db8:1::/48'],
            $result
        );
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
        self::assertTrue(IP6Helper::isInRange(
            '2001:db8::5',
            ['2001:db7::/32', '2001:db8::/32']
        ));
        self::assertFalse(IP6Helper::isInRange('2001:db8::5', []));
    }

    public function testFormatShortIpMaskFullPrefix(): void {
        self::assertSame(
            'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff',
            IP6Helper::formatShortIpMask('128')
        );
    }

    public function testFormatShortIpMaskSlash64(): void {
        self::assertSame(
            'ffff:ffff:ffff:ffff:0:0:0:0',
            IP6Helper::formatShortIpMask('64')
        );
    }

    public function testFormatShortIpMaskEmptyDefaultsTo128(): void {
        self::assertSame(
            'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff',
            IP6Helper::formatShortIpMask('')
        );
    }

    public function testTrimCIDRsCapsPrefixToSubnetPrefixCap(): void {
        // Default SYS_IP6_SUBNET_PREFIX_CAP is 64. Any /N where N > 64 gets capped.
        self::assertSame(
            ['2001:db8::/32', '2001:db8::/64'],
            IP6Helper::trimCIDRs(['2001:db8::/32', '2001:db8::/128'])
        );
    }

    public function testTrimCIDRsKeepsOnlyEntriesWithSlash(): void {
        self::assertSame(
            ['2001:db8::/32'],
            IP6Helper::trimCIDRs(['2001:db8::/32', '2001:db8::', 'not-a-cidr'])
        );
    }

    public function testProcessCIDREmptyInputReturnsEmpty(): void {
        // Unlike IP4Helper, the IPv6 variant does not pass through minimizeSubnets
        // at the end — it simply returns $results unchanged.
        self::assertSame([], IP6Helper::processCIDR([]));
    }

    public function testProcessCIDRSkipsLoopbackWithoutShelling(): void {
        self::assertSame([], IP6Helper::processCIDR(['::1']));
    }

    public function testProcessCIDRSkipsIpAlreadyInExistingRange(): void {
        // 2001:db8::5 is inside the pre-seeded /32 → isInRange short-circuits
        // the async body before any shell call.
        self::assertSame(
            ['2001:db8::/32'],
            IP6Helper::processCIDR(['2001:db8::5'], ['2001:db8::/32'])
        );
    }
}
