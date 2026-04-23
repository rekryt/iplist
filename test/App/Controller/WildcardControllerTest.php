<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\AsyncTest;

final class WildcardControllerTest extends AsyncTest {
    public function testDomainsAreCollapsedToRegistrableForm(): void {
        $body = $this->body($this->get('/', ['format' => 'wildcard', 'data' => 'domains']));
        // "www.game-a.com" and "api.game-a.com" both collapse to "game-a.com"
        self::assertStringContainsString('game-a.com', $body);
        self::assertStringNotContainsString('www.game-a.com', $body);
        self::assertStringNotContainsString('api.game-a.com', $body);
        // "cdn.game-a.co.uk" collapses using TWO_LEVEL_DOMAIN_ZONES (co.uk) → "game-a.co.uk"
        self::assertStringContainsString('game-a.co.uk', $body);
    }

    public function testNonDomainsDataReturnsError(): void {
        self::assertStringContainsString(
            "'data' GET parameter must be 'domains'",
            $this->body($this->get('/', ['format' => 'wildcard', 'data' => 'ip4']))
        );
    }
}
