<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\OAuthMetadataEndpoint;
use Hn\McpServer\Http\OAuthResourceMetadataEndpoint;
use Hn\McpServer\Middleware\McpServerMiddleware;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Ensures the MCP endpoints generate correct URLs and route correctly when
 * TYPO3 is installed in a subdirectory / behind a path prefix.
 */
class SubdirectoryRoutingTest extends AbstractFunctionalTest
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

    public function testMetadataEndpointGeneratesSubdirectoryAwareUrls(): void
    {
        $request = $this->createRequest('/subfolder/index.php', '/subfolder/.well-known/oauth-protected-resource/mcp');

        $response = (new OAuthResourceMetadataEndpoint())($request);
        $metadata = json_decode((string)$response->getBody(), true);

        $this->assertSame('https://example.com/subfolder/mcp', $metadata['resource']);
        $this->assertSame('https://example.com/subfolder', $metadata['authorization_servers'][0]);
        $this->assertSame('https://example.com/subfolder/mcp_oauth/token', $metadata['revocation_endpoint']);
    }

    public function testOAuthMetadataEndpointGeneratesSubdirectoryAwareUrls(): void
    {
        $request = $this->createRequest('/subfolder/index.php', '/subfolder/mcp_oauth/metadata');

        $response = (new OAuthMetadataEndpoint())($request);
        $metadata = json_decode((string)$response->getBody(), true);

        $this->assertSame('https://example.com/subfolder', $metadata['issuer']);
        $this->assertSame('https://example.com/subfolder/mcp_oauth/authorize', $metadata['authorization_endpoint']);
        $this->assertSame('https://example.com/subfolder/mcp_oauth/token', $metadata['token_endpoint']);
        $this->assertSame('https://example.com/subfolder/mcp_oauth/register', $metadata['registration_endpoint']);
    }

    public function testMetadataEndpointRootInstallationUrls(): void
    {
        $request = $this->createRequest('/index.php', '/.well-known/oauth-protected-resource/mcp');

        $response = (new OAuthResourceMetadataEndpoint())($request);
        $metadata = json_decode((string)$response->getBody(), true);

        $this->assertSame('https://example.com/mcp', $metadata['resource']);
    }

    public function testMiddlewareRoutesSubdirectoryPath(): void
    {
        $request = $this->createRequest('/subfolder/index.php', '/subfolder/.well-known/oauth-protected-resource/mcp');
        $middleware = new McpServerMiddleware(GeneralUtility::makeInstance(Context::class));

        $response = $middleware->process($request, $this->sentinelHandler());

        $this->assertSame(200, $response->getStatusCode(), 'Subdirectory path must be routed, not fall through');
        $metadata = json_decode((string)$response->getBody(), true);
        $this->assertSame('https://example.com/subfolder/mcp', $metadata['resource']);
    }

    public function testMiddlewareFallsThroughForUnrelatedPath(): void
    {
        $request = $this->createRequest('/subfolder/index.php', '/subfolder/some/regular/page');
        $middleware = new McpServerMiddleware(GeneralUtility::makeInstance(Context::class));

        $response = $middleware->process($request, $this->sentinelHandler());

        $this->assertSame(418, $response->getStatusCode(), 'Non-MCP paths must fall through to the next handler');
    }

    private function createRequest(string $scriptName, string $requestUri): ServerRequestInterface
    {
        $serverParams = [
            'HTTP_HOST' => 'example.com',
            'HTTPS' => 'on',
            'SCRIPT_NAME' => $scriptName,
            'SCRIPT_FILENAME' => '/var/www/html' . $scriptName,
            'REQUEST_URI' => $requestUri,
        ];

        $request = new ServerRequest(
            new Uri('https://example.com' . $requestUri),
            'GET',
            'php://input',
            [],
            $serverParams
        );
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromServerParams($serverParams));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }

    private function sentinelHandler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withStatus(418);
            }
        };
    }
}
