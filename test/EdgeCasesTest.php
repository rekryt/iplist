<?php

declare(strict_types=1);

namespace OpenCCK;

/**
 * Edge cases that currently bite or that document an API contract.
 */
final class EdgeCasesTest extends AsyncTest {
    public function testUnknownFormatReturns404(): void {
        $response = $this->get('/', ['format' => 'xyzzy-not-a-format']);
        self::assertSame(404, $response->getStatus());
    }

    public function testMissingDataInMikrotikReturnsErrorBodyNot500(): void {
        // Contract: data-required formats respond with HTTP 200 + "# Error:" body,
        // not 400/500, so downstream scrapers don't need to special-case status codes.
        $response = $this->get('/', ['format' => 'mikrotik']);
        self::assertSame(200, $response->getStatus());
        self::assertStringContainsString("'data' GET parameter is required", $this->body($response));
    }

    public function testMissingDataInGeoipReturnsErrorBodyNot500(): void {
        $response = $this->get('/', ['format' => 'geoip']);
        self::assertSame(200, $response->getStatus());
        self::assertStringContainsString("'data' GET parameter is required", $this->body($response));
    }

    public function testMissingDataInTextReturnsErrorBodyNot500(): void {
        $response = $this->get('/', ['format' => 'text']);
        self::assertSame(200, $response->getStatus());
        self::assertStringContainsString("'data' GET parameter is required", $this->body($response));
    }

    public function testMissingDataInBatReturnsErrorBodyNot500(): void {
        $response = $this->get('/', ['format' => 'bat']);
        self::assertSame(200, $response->getStatus());
        self::assertStringContainsString("'data' GET parameter is required", $this->body($response));
    }

    public function testAllGroupsExcludedYieldsEmptyMikrotikBody(): void {
        // When every group is excluded, getGroups() is empty and the outer foreach
        // never runs. implode("\n", []) is "", so response body is empty with 200.
        $response = $this->get('/', [
            'format' => 'mikrotik',
            'data' => 'ip4',
            'exclude[group]' => ['games', 'tools', 'casino', 'replace'],
        ]);
        self::assertSame(200, $response->getStatus());
        self::assertSame('', $this->body($response));
    }

    /**
     * Regression guard for the `$items[count($items) - 1] . ';'` bug in
     * MikrotikController. The controller must skip empty list blocks
     * entirely instead of writing to index -1 — so an all-excluded group
     * returns HTTP 200 with no "add list=" lines and no stray ";".
     */
    public function testEmptyListBlockDoesNotEmitStraySemicolon(): void {
        $response = $this->get('/', [
            'format' => 'mikrotik',
            'data' => 'ip4',
            'group' => 'games',
            'exclude[ip4]' => ['203.0.113.1', '203.0.113.2', '203.0.113.3', '198.51.100.10', '198.51.100.11'],
        ]);

        self::assertSame(200, $response->getStatus());

        $body = $this->body($response);
        // No "add list=" lines should survive — every IP was excluded.
        self::assertStringNotContainsString('add list=games_ip4', $body);
        // And no stray ";" on a line by itself. Matches a line whose content
        // is only whitespace + ";" + optional whitespace.
        self::assertDoesNotMatchRegularExpression(
            '/(^|\n)\s*;\s*(\n|$)/',
            $body,
            'Stray ";" line indicates the empty-items edge case regressed (MikrotikController).'
        );
    }
}
