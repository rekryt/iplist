<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\AsyncTest;

final class BatControllerTest extends AsyncTest {
    public function testIp4ProducesRouteAddLines(): void {
        $lines = explode("\n", $this->body($this->get('/', ['format' => 'bat', 'data' => 'ip4'])));
        self::assertContains('route add 203.0.113.1 mask 255.255.255.255 0.0.0.0', $lines);
        self::assertContains('route add 192.0.2.1 mask 255.255.255.255 0.0.0.0', $lines);
    }

    public function testCidr4UsesShortMaskFormat(): void {
        $lines = explode("\n", $this->body($this->get('/', ['format' => 'bat', 'data' => 'cidr4'])));
        self::assertContains('route add 203.0.113.0 mask 255.255.255.0 0.0.0.0', $lines);
    }

    public function testDataMustBeIp4OrCidr4(): void {
        self::assertStringContainsString(
            "'data' GET parameter must be 'ip4' or 'cidr4'",
            $this->body($this->get('/', ['format' => 'bat', 'data' => 'domains']))
        );
    }

    public function testMissingDataReturnsError(): void {
        self::assertStringContainsString(
            "'data' GET parameter is required",
            $this->body($this->get('/', ['format' => 'bat']))
        );
    }

    public function testFilesaveAddsBatContentDisposition(): void {
        $response = $this->get('/', ['format' => 'bat', 'data' => 'ip4', 'filesave' => '1']);
        self::assertSame(200, $response->getStatus());
        self::assertStringContainsString('attachment', $response->getHeader('content-disposition') ?? '');
        self::assertStringContainsString('ip-list.bat', $response->getHeader('content-disposition') ?? '');
    }
}
