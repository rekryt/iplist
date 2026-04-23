<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\AsyncTest;

final class MainControllerTest extends AsyncTest {
    public function testIndexServesPublicIndexHtml(): void {
        $response = $this->get('/');
        self::assertSame(200, $response->getStatus());
        $body = $this->body($response);
        self::assertStringContainsString('fixture', $body);
    }

    public function testStaticFileIsServedThroughMainController(): void {
        // /{name:.+} also routes to MainController which reads from PATH_ROOT/public/
        $response = $this->get('/scripts/update_resources.sh');
        self::assertSame(200, $response->getStatus());
        self::assertStringContainsString('fixture script', $this->body($response));
    }

    public function testMissingFileReturns404(): void {
        $response = $this->get('/does-not-exist.html');
        self::assertSame(404, $response->getStatus());
    }
}
