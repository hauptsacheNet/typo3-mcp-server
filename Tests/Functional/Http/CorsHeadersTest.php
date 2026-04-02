<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\OAuthTokenEndpoint;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;

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
}
