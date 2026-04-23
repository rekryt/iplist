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
        self::assertSame(
            ['10.0.0.0/8'],
            IP4Helper::minimizeSubnets(['10.0.0.0/8', '10.0.0.0/24'])
        );
    }

    public function testMinimizeSubnetsHandlesUnorderedInput(): void {
        // The internal sortSubnets() must put the shorter prefix first so it
        // wins over the /24 regardless of input order.
        self::assertSame(
            ['10.0.0.0/8'],
            IP4Helper::minimizeSubnets(['10.0.0.0/24', '10.0.0.0/8', '10.1.2.3/32'])
        );
    }

    public function testMinimizeSubnetsKeepsDistinctRanges(): void {
        $result = IP4Helper::minimizeSubnets(['10.0.0.0/24', '11.0.0.0/24', '192.0.2.0/24']);
        self::assertEqualsCanonicalizing(['10.0.0.0/24', '11.0.0.0/24', '192.0.2.0/24'], $result);
    }

    public function testMinimizeSubnetsFiltersFalsyEntries(): void {
        self::assertSame(
            ['10.0.0.0/24'],
            IP4Helper::minimizeSubnets(['', '10.0.0.0/24', '0'])
        );
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
        self::assertSame(
            ['10.0.0.0/8'],
            IP4Helper::processCIDR(['10.0.0.5'], ['10.0.0.0/8'])
        );
    }
}
