<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\AsyncTest;

final class FaviconControllerTest extends AsyncTest {
    public function testUnknownSiteReturns404(): void {
        // A site that is neither in IconsStorage nor in IPListService → controller returns 404.
        // The controller only talks to the network for sites that exist in IPListService,
        // so this path stays offline and is safe for tests.
        $response = $this->get('/favicon', ['site' => 'this-site-does-not-exist.invalid']);
        self::assertSame(404, $response->getStatus());
    }
}
