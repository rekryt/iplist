<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\AsyncTest;

final class CustomControllerTest extends AsyncTest {
    public function testMissingDataReturnsError(): void {
        self::assertStringContainsString(
            "'data' GET parameter is required",
            $this->body($this->get('/', ['format' => 'custom']))
        );
    }

    public function testMissingTemplateReturnsError(): void {
        self::assertStringContainsString(
            "'template' GET parameter is required",
            $this->body($this->get('/', ['format' => 'custom', 'data' => 'ip4']))
        );
    }

    public function testIp4TemplateSubstitutesGroupSiteAndData(): void {
        $body = $this->body(
            $this->get('/', ['format' => 'custom', 'data' => 'ip4', 'template' => '{group}|{site}|{data}'])
        );
        $lines = explode("\n", $body);
        self::assertContains('games|game-a|203.0.113.1', $lines);
        self::assertContains('casino|casino-a|203.0.113.100', $lines);
    }

    public function testCidr4TemplateIncludesMaskPlaceholders(): void {
        $body = $this->body(
            $this->get('/', [
                'format' => 'custom',
                'data' => 'cidr4',
                'template' => '{data}/{shortmask}/{mask}',
            ])
        );
        self::assertStringContainsString('203.0.113.0/24/24/255.255.255.0', $body);
    }

    public function testDomainsTemplate(): void {
        $body = $this->body(
            $this->get('/', [
                'format' => 'custom',
                'data' => 'domains',
                'template' => 'host:{data} site:{site}',
                'site' => 'game-a',
            ])
        );
        self::assertStringContainsString('host:game-a.com site:game-a', $body);
    }
}
