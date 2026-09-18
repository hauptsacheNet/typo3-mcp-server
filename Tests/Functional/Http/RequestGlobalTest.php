<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

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
 * The endpoints that run tools are answered by the middleware itself, so the
 * core RequestHandler never publishes $GLOBALS['TYPO3_REQUEST'] for them. Core
 * APIs behind the tools still read it - DataHandler's RTE transformation, for
 * example, validates href values through DefaultSanitizerBuilder, whose closure
 * throws without an active request, so a bodytext containing a t3:// link
 * cannot be saved.
 */
class RequestGlobalTest extends AbstractFunctionalTest
{
    private mixed $previousRequest;
    private bool $hadPreviousRequest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hadPreviousRequest = array_key_exists('TYPO3_REQUEST', $GLOBALS);
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        unset($GLOBALS['TYPO3_REQUEST']);
    }

    protected function tearDown(): void
    {
        // Restore an absent global as absent: assigning null would leave a key
        // that later tests can tell apart with array_key_exists().
        if ($this->hadPreviousRequest) {
            $GLOBALS['TYPO3_REQUEST'] = $this->previousRequest;
        } else {
            unset($GLOBALS['TYPO3_REQUEST']);
        }
        parent::tearDown();
    }

    public function testMcpEndpointPublishesTheRequest(): void
    {
        $request = $this->createRequest('/mcp');

        $this->middleware()->process($request, $this->sentinelHandler());

        $this->assertSame($request, $GLOBALS['TYPO3_REQUEST'] ?? null);
    }

    public function testUploadEndpointPublishesTheRequest(): void
    {
        $request = $this->createRequest('/mcp_upload');

        $this->middleware()->process($request, $this->sentinelHandler());

        $this->assertSame($request, $GLOBALS['TYPO3_REQUEST'] ?? null);
    }

    public function testUnrelatedPathLeavesTheRequestUntouched(): void
    {
        $request = $this->createRequest('/some/regular/page');

        $response = $this->middleware()->process($request, $this->sentinelHandler());

        $this->assertSame(418, $response->getStatusCode(), 'Non-MCP paths must fall through to the next handler');
        $this->assertNull($GLOBALS['TYPO3_REQUEST'] ?? null, 'Requests this middleware does not answer must stay untouched');
    }

    private function middleware(): McpServerMiddleware
    {
        return new McpServerMiddleware(GeneralUtility::makeInstance(Context::class));
    }

    private function createRequest(string $requestUri): ServerRequestInterface
    {
        $serverParams = [
            'HTTP_HOST' => 'example.com',
            'HTTPS' => 'on',
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => '/var/www/html/index.php',
            'REQUEST_URI' => $requestUri,
        ];

        $request = new ServerRequest(
            new Uri('https://example.com' . $requestUri),
            'GET',
            'php://input',
            [],
            $serverParams
        );

        return $request->withAttribute('normalizedParams', NormalizedParams::createFromServerParams($serverParams));
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
