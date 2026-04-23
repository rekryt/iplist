<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Entity;

use PHPUnit\Framework\TestCase;

final class SiteTest extends TestCase {
    private function site(array $domains): Site {
        return new Site(
            name: 'test',
            group: 'test',
            domains: $domains,
            dns: [],
            timeout: 0,
            ip4: [],
            ip6: [],
            cidr4: [],
            cidr6: []
        );
    }

    public function testGetDomainsWithoutWildcardReturnsRaw(): void {
        $input = ['foo.com', 'www.foo.com', 'api.foo.com'];
        self::assertSame($input, $this->site($input)->getDomains());
    }

    public function testGetDomainsWildcardCollapsesSubdomainsToRegistrableForm(): void {
        $domains = $this->site(['foo.com', 'www.foo.com', 'api.foo.com'])->getDomains(true);
        self::assertSame(['foo.com'], $domains);
    }

    public function testGetDomainsWildcardDedupsAfterCollapse(): void {
        $domains = $this->site([
            'a.example.com',
            'b.example.com',
            'c.d.example.com',
            'example.com',
        ])->getDomains(true);
        self::assertSame(['example.com'], $domains);
    }

    public function testGetDomainsWildcardHonorsTwoLevelDomainZones(): void {
        // Last 2 parts "co.uk" are in TWO_LEVEL_DOMAIN_ZONES, so the registrable
        // domain takes the last 3 parts.
        $domains = $this->site([
            'foo.co.uk',
            'www.foo.co.uk',
            'cdn.www.foo.co.uk',
            'bar.co.uk',
        ])->getDomains(true);
        self::assertEqualsCanonicalizing(['foo.co.uk', 'bar.co.uk'], $domains);
    }

    public function testGetDomainsWildcardPreservesPlainTldCollapse(): void {
        // "com.ua" IS in TWO_LEVEL_DOMAIN_ZONES, but "example.org" is plain and
        // must collapse via last-2-parts.
        $domains = $this->site(['shop.example.org', 'example.org', 'store.example.com.ua'])->getDomains(true);
        self::assertEqualsCanonicalizing(['example.org', 'example.com.ua'], $domains);
    }

    public function testGetDomainsWildcardHandlesSingleLabelDomain(): void {
        // Defensive — single-label inputs should not explode. array_slice(-2) of
        // a single-element array returns that element.
        $domains = $this->site(['localhost'])->getDomains(true);
        self::assertSame(['localhost'], $domains);
    }

    public function testGetDomainsWildcardNormalizesOutput(): void {
        // Collapsing produces duplicates; normalizeArray sorts + dedups the result.
        $domains = $this->site([
            'b.example.com',
            'a.example.com',
            'example.com',
        ])->getDomains(true);
        self::assertSame(['example.com'], $domains);
    }
}
