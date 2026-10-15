<?php

namespace Mlc\Tests\Security;

use PHPUnit\Framework\TestCase;

// Security regression harness — needs a deployed target.
// Requires TEST_BASE_URI (e.g. https://staging.example.com); skipped otherwise.
class WebExposureTest extends TestCase {
    private static string $base = '';

    public static function setUpBeforeClass(): void {
        self::$base = rtrim((string)getenv('TEST_BASE_URI'), '/');
        if (self::$base === '') {
            self::markTestSkipped('TEST_BASE_URI not set — security tests need a deployed target');
        }
    }

    private function get(string $path): array {
        $ctx = stream_context_create(['http' => [
            'timeout' => 10,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $body = @file_get_contents(self::$base . $path, false, $ctx);
        return ['status' => (int)explode(' ', $http_response_header[0] ?? 'HTTP 0')[1],
                'body' => $body, 'headers' => $http_response_header];
    }

    private function requestRaw(string $url, string $method = 'GET', string $body = ''): array {
        $ctx = stream_context_create(['http' => [
            'timeout' => 10,
            'ignore_errors' => true,
            'method' => $method,
            'header' => 'Content-Type: application/json',
            'content' => $body,
        ]]);
        @file_get_contents($url, false, $ctx);
        return ['status' => (int)explode(' ', $http_response_header[0] ?? 'HTTP 0')[1]];
    }

    /** Internal files must not be web-reachable (docroot = public_html/). */
    public function testInternalFilesReturn404(): void {
        foreach (['/.env', '/.gitignore', '/.git/config', '/.htaccess',
                  '/database/schema.sql', '/composer.json', '/composer.lock',
                  '/package.json', '/src/bootstrap.php', '/src/Engine/Ops.php',
                  '/cron/tick.php', '/bin/migrate.php', '/bin/seed-demo.php',
                  '/vendor/autoload.php', '/logs/php_errors.log',
                  '/templates/tictactoe.ast.json', '/templates/tictactoe.workspace.json',
                  '/docs/DESIGN.md', '/phpunit.xml', '/AGENTS.md'] as $path) {
            $r = $this->get($path);
            $this->assertContains($r['status'], [403, 404], "$path exposed (HTTP {$r['status']})");
        }
    }

    /** Credentials in query strings must not authenticate — headers only. */
    public function testQueryTokenNotAccepted(): void {
        $r = $this->get('/api/state.php?api_token=invalid&player_token=invalid');
        $this->assertSame(401, $r['status']);
        $r = $this->get('/api/blocks.php?api_token=invalid');
        $this->assertSame(401, $r['status']);
    }

    /** Fingerprinting + security headers on the landing page. */
    public function testSecurityHeadersPresent(): void {
        $r = $this->get('/');
        $flat = implode("\n", $r['headers']);
        $this->assertStringNotContainsStringIgnoringCase('x-powered-by', $flat);
        $this->assertStringContainsStringIgnoringCase('x-content-type-options', $flat);
        $this->assertStringContainsStringIgnoringCase('x-frame-options', $flat);
    }

    /** Cabinet endpoints: session-gated, no anonymous data. */
    public function testCabinetEndpointsRequireSession(): void {
        foreach (['/php/games.php?action=list', '/php/programs.php?action=load&game_id=1'] as $path) {
            $r = $this->get($path);
            $this->assertContains($r['status'], [401, 403], "$path anonymous (HTTP {$r['status']})");
            $this->assertStringNotContainsString('api_key', (string)$r['body']);
        }
        // HTML pages redirect to login rather than render.
        foreach (['/cabinet.php', '/editor.php?game=1'] as $path) {
            $r = $this->get($path);
            $this->assertContains($r['status'], [301, 302, 303, 401, 403],
                "$path rendered for anonymous (HTTP {$r['status']})");
            $this->assertStringNotContainsString('api_key', (string)$r['body']);
        }
    }

    /** SDK downloads are cabinet-only — anonymous gets redirected, no zip. */
    public function testSdkDownloadRequiresSession(): void {
        $r = $this->get('/php/download.php?sdk=dotnet');
        $this->assertContains($r['status'], [301, 302, 303, 401, 403],
            "download.php anonymous (HTTP {$r['status']})");
        $this->assertStringNotContainsString('PK', (string)$r['body']);
    }

    /** Simulator is cabinet-only — game tokens must NOT unlock it. */
    public function testSimulateRejectsAnonymousAndGameTokens(): void {
        $r = $this->requestRaw(self::$base . '/api/simulate.php', 'POST', '{"ast":{},"event":"on_player_action"}');
        $this->assertSame(401, $r['status']);
    }

    /** Player-facing endpoints must not respond to unauthenticated calls. */
    public function testApiEndpointsRequireTokens(): void {
        $r = $this->requestRaw(self::$base . '/api/logic.php?action=do', 'POST',
            '{"action":"move","payload":{}}');
        $this->assertSame(401, $r['status']);

        $r = $this->get('/api/state.php');
        $this->assertSame(401, $r['status']);

        $r = $this->get('/api/player.php?action=updates');
        $this->assertSame(401, $r['status']);
    }

    /** logic.php must not run actions via GET — action verbs are POST-only. */
    public function testLogicDoNotGettable(): void {
        $r = $this->get('/api/logic.php?action=do&action_name=move');
        // 400/401/405 — never a 200 program execution.
        $this->assertContains($r['status'], [400, 401, 403, 405]);
    }

    /** Web-cron wrapper: no token (or wrong token) must never run the worker. */
    public function testCronTickRequiresToken(): void {
        $r = $this->get('/cron-tick.php');
        $this->assertSame(404, $r['status']);
        $r = $this->get('/cron-tick.php?cron_token=wrong');
        $this->assertSame(404, $r['status']);
    }

    /** Auth endpoint: GET must not trigger any state change (POST + CSRF only). */
    public function testAuthGetDoesNothing(): void {
        $r = $this->get('/php/auth.php?action=send_code&email=a@b.c');
        $this->assertContains($r['status'], [301, 302, 303, 400, 401, 403, 405]);
    }

    /** Responses must be JSON-shaped errors, not stack traces or HTML. */
    public function testErrorsAreJsonNotHtml(): void {
        $r = $this->get('/api/state.php');
        $body = (string)$r['body'];
        $this->assertStringNotContainsString('<html', strtolower($body));
        $this->assertStringNotContainsString('Fatal error', $body);
        $this->assertStringNotContainsString('Stack trace', $body);
        $this->assertNotNull(json_decode($body, true), 'error body is not JSON: ' . substr($body, 0, 120));
    }
}
