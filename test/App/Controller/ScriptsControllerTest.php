<?php

declare(strict_types=1);

namespace OpenCCK\App\Controller;

use OpenCCK\AsyncTest;

/**
 * ScriptsController is only reachable via the explicit ?format=scripts override
 * (the router's catch-all /{name:.+} dispatches to MainController by default).
 * When activated, it reads from PATH_ROOT/scripts/ — not public/scripts/.
 *
 * The "production" shell script flow — GET /scripts/homeproxy/update_resources.sh —
 * actually goes through MainController which serves from PATH_ROOT/public/. That
 * path is covered by MainControllerTest::testStaticFileIsServedThroughMainController.
 */
final class ScriptsControllerTest extends AsyncTest {
    public function testScriptsFormatReadsFromScriptsDir(): void {
        // URL path shape expected by ScriptsController: first two slash-segments are
        // stripped, so "/any/probe.sh" resolves to PATH_ROOT/scripts/probe.sh.
        $response = $this->get('/a/probe.sh', ['format' => 'scripts']);
        self::assertSame(200, $response->getStatus());
        self::assertStringContainsString('scripts controller fixture', $this->body($response));
    }

    public function testMissingFileReturns404(): void {
        $response = $this->get('/a/no-such-script.sh', ['format' => 'scripts']);
        self::assertSame(404, $response->getStatus());
    }
}
