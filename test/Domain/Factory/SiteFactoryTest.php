<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Factory;

use PHPUnit\Framework\TestCase;

final class SiteFactoryTest extends TestCase {
    public function testNormalizeReturnsEmptyForEmpty(): void {
        self::assertSame([], SiteFactory::normalize([]));
    }

    public function testNormalizeStripsCommentLines(): void {
        self::assertSame(
            ['foo.com', 'bar.com'],
            SiteFactory::normalize(['foo.com', '#comment', 'bar.com', '# another'])
        );
    }

    public function testNormalizeStripsEmptyStrings(): void {
        self::assertSame(['foo.com'], SiteFactory::normalize(['', 'foo.com', '']));
    }

    public function testNormalizeDedupsAndReindexes(): void {
        self::assertSame(
            ['a', 'b', 'c'],
            SiteFactory::normalize(['a', 'b', 'a', 'c', 'b', 'a'])
        );
    }

    public function testNormalizePreservesInsertionOrder(): void {
        self::assertSame(
            ['c', 'a', 'b'],
            SiteFactory::normalize(['c', 'a', 'b'])
        );
    }

    public function testNormalizeWithoutIpFlagKeepsPrivateLookingStrings(): void {
        // When the flag is false, the private-range filter does not apply.
        self::assertSame(
            ['10.0.0.1', '127.0.0.1', '192.168.1.1'],
            SiteFactory::normalize(['10.0.0.1', '127.0.0.1', '192.168.1.1'])
        );
    }

    public function testNormalizeWithIpFlagStripsPrivateV4Ranges(): void {
        $input = [
            '1.2.3.4',
            '10.0.0.1',
            '0.0.0.0',
            '127.0.0.1',
            '172.16.0.1',
            '192.168.1.1',
            '203.0.113.1',
        ];
        self::assertSame(
            ['1.2.3.4', '203.0.113.1'],
            SiteFactory::normalize($input, true)
        );
    }

    public function testNormalizeWithIpFlagStripsIpv6UlaPrefix(): void {
        self::assertSame(
            ['2001:db8::1'],
            SiteFactory::normalize(['fd00::1', '2001:db8::1', 'fdab:cd::2'], true)
        );
    }

    public function testNormalizeWithIpFlagMissesUpperHalfOfPrivateSlash12(): void {
        // Documents current behaviour: the filter uses str_starts_with('172.16.')
        // instead of covering the full RFC1918 172.16.0.0/12 (172.16.0.0 –
        // 172.31.255.255). 172.17–172.31 are NOT stripped. Pin this so any
        // tightening of the filter surfaces as a test failure.
        $input = ['172.15.0.1', '172.16.0.1', '172.17.0.1', '172.31.0.1', '172.32.0.1'];
        self::assertSame(
            ['172.15.0.1', '172.17.0.1', '172.31.0.1', '172.32.0.1'],
            SiteFactory::normalize($input, true)
        );
    }

    public function testNormalizeArraySortsBeforeNormalizing(): void {
        self::assertSame(
            ['a', 'b', 'c'],
            SiteFactory::normalizeArray(['c', 'a', 'b', 'a'])
        );
    }

    public function testNormalizeArrayAppliesIpFilterAfterSort(): void {
        self::assertSame(
            ['1.2.3.4', '203.0.113.1'],
            SiteFactory::normalizeArray(['203.0.113.1', '10.0.0.1', '1.2.3.4'], true)
        );
    }

    public function testTrimArrayTrimsEachElement(): void {
        self::assertSame(
            ['foo.com', 'bar.com', ''],
            SiteFactory::trimArray(["  foo.com  ", "\tbar.com\n", '   '])
        );
    }
}
