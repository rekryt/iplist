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
}
