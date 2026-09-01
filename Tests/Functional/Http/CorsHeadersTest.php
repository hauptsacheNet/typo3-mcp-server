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

    /**
     * A browser MCP client preflights /mcp before its first POST. The preflight
     * carries no credentials, so the endpoint must answer OPTIONS with 200 and
     * CORS headers instead of falling through to the 401 auth check. See #115.
     */
    public function testMcpEndpointAnswersPreflightWithoutAuth(): void
    {
        $endpoint = new McpEndpoint();

        $request = new ServerRequest(
            new Uri('https://example.com/mcp'),
            'OPTIONS',
            'php://input',
            [
                'Origin' => 'https://claude.ai',
                'Access-Control-Request-Method' => 'POST',
                'Access-Control-Request-Headers' => 'authorization, content-type, mcp-protocol-version',
            ]
        );
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = $endpoint($request);

        $this->assertEquals(
            200,
            $response->getStatusCode(),
            'An unauthenticated OPTIONS preflight to /mcp must return 200, not 401'
        );
        $this->assertEquals(
            'https://claude.ai',
            $response->getHeaderLine('Access-Control-Allow-Origin'),
            'Preflight response must reflect the request Origin'
        );
    }

    /**
     * The Streamable HTTP transport lets clients send Accept, MCP-Protocol-Version,
     * Mcp-Session-Id and Last-Event-ID, use DELETE to terminate a session, and
     * read Mcp-Session-Id off the response. A browser blocks all of that unless
     * the CORS headers advertise it. See #115.
     */
    public function testMcpPreflightAdvertisesStreamableHttpCapabilities(): void
    {
        $endpoint = new McpEndpoint();

        $request = new ServerRequest(
            new Uri('https://example.com/mcp'),
            'OPTIONS',
            'php://input',
            ['Origin' => 'https://claude.ai']
        );
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = $endpoint($request);

        $allowMethods = $response->getHeaderLine('Access-Control-Allow-Methods');
        $this->assertStringContainsStringIgnoringCase('DELETE', $allowMethods, 'DELETE must be allowed for session termination');

        $allowHeaders = strtolower($response->getHeaderLine('Access-Control-Allow-Headers'));
        foreach (['accept', 'mcp-protocol-version', 'mcp-session-id', 'last-event-id'] as $header) {
            $this->assertStringContainsString($header, $allowHeaders, sprintf('CORS must allow the "%s" request header', $header));
        }

        $exposeHeaders = strtolower($response->getHeaderLine('Access-Control-Expose-Headers'));
        $this->assertStringContainsString('mcp-session-id', $exposeHeaders, 'Browser clients must be able to read Mcp-Session-Id');
    }
}
