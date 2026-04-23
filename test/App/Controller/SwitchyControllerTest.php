<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\AsyncTest;

final class SwitchyControllerTest extends AsyncTest {
    public function testRuleListHeaderAndWildcardEntries(): void {
        $body = $this->body($this->get('/', ['format' => 'switchy', 'data' => 'domains']));

        self::assertStringContainsString('; Switchy - RuleList', $body);
        self::assertStringContainsString('#BEGIN', $body);
        self::assertStringContainsString('[Wildcard]', $body);
        self::assertStringContainsString('*game-a.com/*', $body);
        self::assertStringContainsString('*casino-a.com/*', $body);
        self::assertStringContainsString('#END', $body);
    }

    public function testNonDomainsDataReturnsError(): void {
        self::assertStringContainsString(
            "'data' GET parameter is must be 'domains'",
            $this->body($this->get('/', ['format' => 'switchy', 'data' => 'ip4']))
        );
    }
}
