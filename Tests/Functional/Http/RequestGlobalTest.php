<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Middleware\McpServerMiddleware;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Information\Typo3Version;
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

    public function testRteLinkToPageCanBeWrittenThroughTheMcpEndpoint(): void
    {
        // TYPO3 13.4 checks the current host via getIndpEnv() and never reads
        // the request global here; the late dependency on
        // $GLOBALS['TYPO3_REQUEST'] (RuntimeException 1775675289) came with 14.
        if ((new Typo3Version())->getMajorVersion() < 14) {
            $this->markTestSkipped('DefaultSanitizerBuilder reads $GLOBALS[\'TYPO3_REQUEST\'] only since TYPO3 14.');
        }

        // The sanitizer only runs on RTE content with this flag enabled, which
        // is what makes the missing request global visible in the first place.
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['security.backend.htmlSanitizeRte'] = true;

        $token = GeneralUtility::makeInstance(OAuthService::class)
            ->createToken(1, 'request-global-test')['access_token'];
        $link = '<a href="t3://page?uid=1">x</a>';

        // A single stateless (2026-07-28) tool call through the middleware,
        // exactly like a real HTTP request reaches the endpoint.
        $request = $this->createRequest('/mcp', 'POST', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'WriteTable',
                'arguments' => [
                    'action' => 'create',
                    'table' => 'tt_content',
                    'pid' => 1,
                    'data' => [
                        // CType "text" enables the RTE for bodytext in the core TCA
                        'CType' => 'text',
                        'header' => 'RTE link',
                        'bodytext' => '<p>' . $link . '</p>',
                        'colPos' => 0,
                    ],
                ],
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                    'io.modelcontextprotocol/client' => ['name' => 'request-global-test', 'version' => '1.0'],
                    'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                ],
            ],
        ], [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => 'WriteTable',
        ]);

        $response = $this->middleware()->process($request, $this->sentinelHandler());

        $raw = (string)$response->getBody();
        $this->assertSame(200, $response->getStatusCode(), $raw);
        $body = json_decode($raw, true);
        $this->assertIsArray($body, $raw);
        $this->assertArrayHasKey('result', $body, $raw);
        $this->assertFalse($body['result']['isError'] ?? true, $raw);

        $uid = (int)(json_decode($body['result']['content'][0]['text'] ?? '', true)['uid'] ?? 0);
        $this->assertGreaterThan(0, $uid, $raw);

        // The new record lives in the workspace only; the link must be kept as is.
        $queryBuilder = $this->getConnectionForTable('tt_content')->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();
        $record = $queryBuilder->select('bodytext', 't3ver_wsid')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', $uid))
            ->executeQuery()
            ->fetchAssociative();
        $this->assertIsArray($record);
        $this->assertGreaterThan(0, (int)$record['t3ver_wsid'], 'The record must be written to a workspace, not live');
        $this->assertStringContainsString($link, (string)$record['bodytext']);
    }

    private function middleware(): McpServerMiddleware
    {
        return new McpServerMiddleware(GeneralUtility::makeInstance(Context::class));
    }

    private function createRequest(
        string $requestUri,
        string $method = 'GET',
        ?array $jsonBody = null,
        array $headers = []
    ): ServerRequestInterface {
        $serverParams = [
            'HTTP_HOST' => 'example.com',
            'HTTPS' => 'on',
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => '/var/www/html/index.php',
            'REQUEST_URI' => $requestUri,
        ];

        $body = 'php://input';
        if ($jsonBody !== null) {
            $body = new Stream('php://temp', 'rw');
            $body->write(json_encode($jsonBody));
            $body->rewind();
        }

        $request = new ServerRequest(
            new Uri('https://example.com' . $requestUri),
            $method,
            $body,
            $headers,
            $serverParams
        );

        // Both attributes are set before this middleware runs in a real request:
        // applicationType by the frontend Application, normalizedParams by the
        // normalized-params-attribute middleware.
        return $request
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams($serverParams));
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
