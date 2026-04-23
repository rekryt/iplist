<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\AsyncTest;

final class JsonControllerTest extends AsyncTest {
    public function testAllSitesFullRecords(): void {
        $response = $this->get('/', ['format' => 'json']);
        self::assertSame(200, $response->getStatus());
        self::assertStringContainsString('application/json', $response->getHeader('content-type') ?? '');

        $data = json_decode($this->body($response), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('game-a', $data);
        self::assertArrayHasKey('game-b', $data);
        self::assertArrayHasKey('tool-a', $data);
        self::assertArrayHasKey('casino-a', $data);

        self::assertSame('game-a', $data['game-a']['name']);
        self::assertSame('games', $data['game-a']['group']);
        self::assertContains('203.0.113.1', $data['game-a']['ip4']);
    }

    public function testIp4Map(): void {
        $response = $this->get('/', ['format' => 'json', 'data' => 'ip4']);
        $data = json_decode($this->body($response), true);

        self::assertIsArray($data);
        self::assertEqualsCanonicalizing(
            ['203.0.113.1', '203.0.113.2', '203.0.113.3'],
            $data['game-a']
        );
    }

    public function testDomainsMap(): void {
        $data = json_decode($this->body($this->get('/', ['format' => 'json', 'data' => 'domains'])), true);
        self::assertContains('game-a.com', $data['game-a']);
        self::assertContains('www.game-b.com', $data['game-b']);
    }

    public function testSiteFilter(): void {
        $data = json_decode(
            $this->body($this->get('/', ['format' => 'json', 'site' => 'game-a'])),
            true
        );
        self::assertArrayHasKey('game-a', $data);
        self::assertArrayNotHasKey('game-b', $data);
        self::assertArrayNotHasKey('casino-a', $data);
    }

    public function testSiteFilterWithData(): void {
        $data = json_decode(
            $this->body($this->get('/', ['format' => 'json', 'data' => 'ip6', 'site' => 'game-b'])),
            true
        );
        self::assertEqualsCanonicalizing(['2001:db8:1::1'], $data['game-b']);
        self::assertCount(1, $data);
    }
}
