<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\AsyncTest;
use OpenCCK\Domain\Helper\IP4Helper;
use OpenCCK\Domain\Helper\IP6Helper;

/**
 * End-to-end coverage for the `replace` feature.
 *
 * The `mock-google` fixture (test/fixtures/config/replace/mock-google.json)
 * simulates a post-reload state: `cidr4` still contains the parent zone
 * `172.217.0.0/16`, and `replace.cidr4["172.217.0.0/16"]` already holds
 * escalated /32 entries — the shape that `Site::reload` + `IP4Helper::growReplace`
 * would produce on disk after one maintenance cycle.
 *
 * This lets us verify view-time semantics without spinning a real DNS
 * pipeline. Reload-time growth and idempotency are re-checked here by
 * calling `growReplace` directly against the already-loaded Site entity.
 */
final class ReplaceFlowTest extends AsyncTest {
    public function testTextCidr4DropsKeyZoneAndSubstitutesValues(): void {
        $lines = explode(
            "\n",
            $this->body($this->get('/', ['format' => 'text', 'data' => 'cidr4', 'site' => 'mock-google']))
        );

        // The parent /16 is replaced out.
        self::assertNotContains('172.217.0.0/16', $lines);
        // Its value-array entries are present.
        self::assertContains('172.217.17.206/32', $lines);
        self::assertContains('172.217.17.207/32', $lines);
        // No duplicates inside the output.
        self::assertSame(count($lines), count(array_unique($lines)));
    }

    public function testTextCidr6DropsKeyZoneAndSubstitutesValues(): void {
        $lines = explode(
            "\n",
            $this->body($this->get('/', ['format' => 'text', 'data' => 'cidr6', 'site' => 'mock-google']))
        );
        self::assertNotContains('2001:db8::/32', $lines);
        self::assertContains('2001:db8::1/128', $lines);
    }

    public function testJsonReturnsRawCidrAndReplaceBlock(): void {
        $data = json_decode($this->body($this->get('/', ['format' => 'json', 'site' => 'mock-google'])), true);
        self::assertArrayHasKey('mock-google', $data);
        $site = $data['mock-google'];

        // Raw cidr4/cidr6 still contain the "extra" parent zones — JsonController
        // must NOT apply the replacement.
        self::assertContains('172.217.0.0/16', $site['cidr4']);
        self::assertContains('2001:db8::/32', $site['cidr6']);

        // `replace` block surfaces verbatim so clients can introspect it.
        self::assertArrayHasKey('replace', $site);
        self::assertSame(['172.217.17.206/32', '172.217.17.207/32'], $site['replace']['cidr4']['172.217.0.0/16']);
        self::assertSame(['2001:db8::1/128'], $site['replace']['cidr6']['2001:db8::/32']);
    }

    public function testJsonDataReplaceFilterExposesJustTheReplaceMap(): void {
        // `?data=replace` is a side-effect of replace being a public field —
        // JsonController::getBody pulls $siteEntity->$data. Pin it as a feature.
        $data = json_decode(
            $this->body($this->get('/', ['format' => 'json', 'data' => 'replace', 'site' => 'mock-google'])),
            true
        );
        self::assertSame(['172.217.17.206/32', '172.217.17.207/32'], $data['mock-google']['cidr4']['172.217.0.0/16']);
    }

    public function testOtherSitesCidr4IsUnaffected(): void {
        // A request across all sites must still output the untouched zones
        // of other fixtures (`203.0.113.0/24`, etc.), and still drop
        // `172.217.0.0/16` because mock-google replaces it out.
        $lines = explode("\n", $this->body($this->get('/', ['format' => 'text', 'data' => 'cidr4'])));
        self::assertContains('203.0.113.0/24', $lines);
        self::assertContains('198.51.100.0/24', $lines);
        self::assertContains('192.0.2.0/24', $lines);
        self::assertNotContains('172.217.0.0/16', $lines);
        self::assertContains('172.217.17.206/32', $lines);
    }

    public function testGrowReplaceIsIdempotentOnLoadedSite(): void {
        // Reload-time invariant: running growReplace twice with the same ip4/ip6
        // must leave the replace map unchanged. Operate on a deep copy so the
        // shared Site entity used by other tests is not mutated.
        $site = $this->service()->sites['mock-google'];
        $replace = unserialize(serialize($site->replace));

        IP4Helper::growReplace($replace, $site->ip4);
        IP6Helper::growReplace($replace, $site->ip6);
        $after1 = unserialize(serialize($replace));

        IP4Helper::growReplace($replace, $site->ip4);
        IP6Helper::growReplace($replace, $site->ip6);

        self::assertEquals($after1, $replace);
    }

    public function testGrowReplacePersistsEscalatedIpsForZonesAdminListed(): void {
        // Cold-start scenario: admin writes replace.cidr4 with empty values,
        // Site::reload picks up existing ip4 and grows the list to /32 entries.
        $site = $this->service()->sites['mock-google'];
        $replace = (object) [
            'cidr4' => (object) ['172.217.0.0/16' => []],
            'cidr6' => (object) ['2001:db8::/32' => []],
        ];

        IP4Helper::growReplace($replace, $site->ip4);
        IP6Helper::growReplace($replace, $site->ip6);

        self::assertEqualsCanonicalizing(
            ['172.217.17.206/32', '172.217.17.207/32', '172.217.18.1/32'],
            $replace->cidr4->{'172.217.0.0/16'}
        );
        self::assertEqualsCanonicalizing(['2001:db8::1/128'], $replace->cidr6->{'2001:db8::/32'});
    }
}
