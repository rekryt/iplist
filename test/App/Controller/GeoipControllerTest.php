<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\AsyncTest;

final class GeoipControllerTest extends AsyncTest {
    private function geoipBinary(): ?string {
        $candidates = [
            ($_ENV['GEOIP_PATH'] ?? '') ? rtrim($_ENV['GEOIP_PATH'], '/') . '/geoip' : null,
            dirname(__DIR__, 2) . '/geoip/geoip',
        ];
        foreach (array_filter($candidates) as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }
        return null;
    }

    public function testMissingDataReturnsError(): void {
        self::assertStringContainsString(
            "'data' GET parameter is required",
            $this->body($this->get('/', ['format' => 'geoip']))
        );
    }

    public function testInvalidDataReturnsError(): void {
        self::assertStringContainsString(
            "must be 'ip4', 'cidr4', 'ip6' or 'cidr6'",
            $this->body($this->get('/', ['format' => 'geoip', 'data' => 'domains']))
        );
    }

    public function testGeneratesDatFileWhenBinaryAvailable(): void {
        if ($this->geoipBinary() === null) {
            self::markTestSkipped('v2fly geoip binary not available — skipping integration test');
        }

        $response = $this->get('/', ['format' => 'geoip', 'data' => 'cidr4']);
        self::assertSame(200, $response->getStatus());
        self::assertStringContainsString('iplist.dat', $response->getHeader('content-disposition') ?? '');
        self::assertNotSame('', $this->body($response));
    }
}
