<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\McpEndpoint;
use Hn\McpServer\Http\OAuthTokenEndpoint;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tests for CORS header security
 */
class CorsHeadersTest extends AbstractFunctionalTest
{
    private mixed $previousRequest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->previousRequest;
        parent::tearDown();
    }

    public function testCorsReflectsRequestOriginNotWildcard(): void
    {
        $endpoint = new OAuthTokenEndpoint();

        $request = new ServerRequest(
            new Uri('https://example.com/mcp_oauth/token'),
            'OPTIONS',
            'php://input',
            ['Origin' => 'https://my-mcp-client.example.com']
        );
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = $endpoint($request);

        $origin = $response->getHeaderLine('Access-Control-Allow-Origin');
        $this->assertNotEquals('*', $origin, 'CORS must NOT use wildcard origin');
        $this->assertEquals('https://my-mcp-client.example.com', $origin);
    }

    public function testCorsWithoutOriginHeaderSkipsHeaders(): void
    {
        $endpoint = new OAuthTokenEndpoint();

        $request = new ServerRequest(
            new Uri('https://example.com/mcp_oauth/token'),
            'OPTIONS'
        );
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = $endpoint($request);

        $this->assertFalse(
            $response->hasHeader('Access-Control-Allow-Origin'),
            'No CORS headers should be set for non-CORS requests'
        );
    }

    /**
     * A browser preflight never carries the Authorization header, so /mcp must
     * answer OPTIONS before authentication. Requiring a token here would fail
     * every browser-based MCP client with a 401 before its first real request.
     * See issue #115.
     */
    public function testMcpPreflightSucceedsWithoutAuthentication(): void
    {
        $endpoint = new McpEndpoint();

        $request = new ServerRequest(
            new Uri('https://example.com/mcp'),
            'OPTIONS',
            'php://input',
            [
                'Origin' => 'https://my-mcp-client.example.com',
                'Access-Control-Request-Method' => 'POST',
                'Access-Control-Request-Headers' => 'authorization, content-type, mcp-protocol-version',
            ]
        );
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = $endpoint($request);

        $this->assertEquals(200, $response->getStatusCode(), 'Preflight must not require authentication');
        $this->assertEquals(
            'https://my-mcp-client.example.com',
            $response->getHeaderLine('Access-Control-Allow-Origin')
        );

        // The preflight names exactly the headers the client wants to send;
        // reflecting them covers dynamic headers (Mcp-Param-*) that no static
        // allowlist could.
        $this->assertEquals(
            'authorization, content-type, mcp-protocol-version',
            $response->getHeaderLine('Access-Control-Allow-Headers')
        );
    }

    /**
     * Reflecting the Origin makes the response origin-dependent, so shared
     * caches must key on it - for CORS and non-CORS requests alike, or a
     * cached response for one origin would be served to another.
     */
    public function testCorsResponsesVaryOnOrigin(): void
    {
        $endpoint = new OAuthTokenEndpoint();

        foreach ([['Origin' => 'https://my-mcp-client.example.com'], []] as $headers) {
            $request = new ServerRequest(
                new Uri('https://example.com/mcp_oauth/token'),
                'OPTIONS',
                'php://input',
                $headers
            );
            $GLOBALS['TYPO3_REQUEST'] = $request;

            $response = $endpoint($request);

            $this->assertStringContainsString(
                'Origin',
                $response->getHeaderLine('Vary'),
                'Vary: Origin must be set regardless of whether the request is CORS'
            );
        }
    }

    /**
     * The MCP Streamable HTTP transport requires custom request headers that
     * are not CORS-safelisted, uses DELETE for session termination, and hands
     * the session id to the client as a response header. All three must be
     * declared in the CORS headers or browser clients cannot connect. Without
     * Access-Control-Request-Headers on the request, the fallback allowlist
     * must cover the transport's static headers. See issue #115.
     */
    public function testCorsHeadersCoverMcpTransportRequirements(): void
    {
        $endpoint = new McpEndpoint();

        $request = new ServerRequest(
            new Uri('https://example.com/mcp'),
            'OPTIONS',
            'php://input',
            ['Origin' => 'https://my-mcp-client.example.com']
        );
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = $endpoint($request);

        $allowHeaders = strtolower($response->getHeaderLine('Access-Control-Allow-Headers'));
        foreach (['authorization', 'content-type', 'mcp-protocol-version', 'mcp-session-id', 'mcp-method', 'mcp-name', 'last-event-id'] as $header) {
            $this->assertStringContainsString($header, $allowHeaders, "Allow-Headers must include $header");
        }

        $this->assertStringContainsString(
            'DELETE',
            $response->getHeaderLine('Access-Control-Allow-Methods'),
            'DELETE is used by MCP clients to terminate sessions'
        );

        $this->assertStringContainsString(
            'mcp-session-id',
            strtolower($response->getHeaderLine('Access-Control-Expose-Headers')),
            'Browser clients must be able to read the session id from the initialize response'
        );

        $this->assertFalse(
            $response->hasHeader('Access-Control-Allow-Credentials'),
            'Reflecting arbitrary origins with credentials is a forbidden CORS combination; auth is Bearer-token based'
        );
    }

    /**
     * The authenticated /mcp data response must carry CORS headers, just like
     * the error responses in the same class already do. Without this, browser-
     * and Electron-based MCP clients have the successful response blocked by
     * the browser before the application ever sees it. See issue #105.
     */
    public function testMcpSuccessResponseIncludesCorsHeaders(): void
    {
        $oauthService = GeneralUtility::makeInstance(OAuthService::class);
        $tokenData = $oauthService->createToken(1, 'cors-test-client');

        $endpoint = new McpEndpoint();

        $request = new ServerRequest(
            new Uri('https://example.com/mcp'),
            'POST',
            'php://input',
            [
                'Origin' => 'https://claude.ai',
                'Authorization' => 'Bearer ' . $tokenData['access_token'],
                'Content-Type' => 'application/json',
            ]
        );
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = $endpoint($request);

        // Regardless of the JSON-RPC payload the MCP runner produces, the HTTP
        // response for an authenticated, CORS request must reflect the Origin.
        $this->assertTrue(
            $response->hasHeader('Access-Control-Allow-Origin'),
            'Authenticated /mcp response must include CORS headers'
        );
        $this->assertEquals(
            'https://claude.ai',
            $response->getHeaderLine('Access-Control-Allow-Origin'),
            'CORS origin must reflect the request Origin, not a wildcard'
        );
    }
}
