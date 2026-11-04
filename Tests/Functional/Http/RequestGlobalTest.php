<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Middleware\McpServerMiddleware;
use Hn\McpServer\Service\FileUploadService;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Resource\StorageRepository;
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
    protected array $testExtensionsToLoad = [
        'mcp_server',
        __DIR__ . '/../Fixtures/Extensions/test_request_context',
    ];

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

    public function testMcpEndpointPublishesTheRequestAsBackendRequest(): void
    {
        $this->middleware()->process($this->createRequest('/mcp'), $this->sentinelHandler());

        $this->assertPublishedBackendRequest('/mcp');
    }

    public function testUploadEndpointPublishesTheRequestAsBackendRequest(): void
    {
        $this->middleware()->process($this->createRequest('/mcp_upload'), $this->sentinelHandler());

        $this->assertPublishedBackendRequest('/mcp_upload');
    }

    private function assertPublishedBackendRequest(string $path): void
    {
        $published = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $this->assertInstanceOf(ServerRequestInterface::class, $published);
        $this->assertSame($path, $published->getUri()->getPath());
        $this->assertTrue(ApplicationType::fromRequest($published)->isBackend());
        $this->assertInstanceOf(NormalizedParams::class, $published->getAttribute('normalizedParams'));
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

        $link = '<a href="t3://page?uid=1">x</a>';

        $result = $this->callToolThroughMiddleware('WriteTable', [
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
        ]);
        $this->assertFalse($result['isError'] ?? true, json_encode($result));

        $uid = (int)(json_decode($result['content'][0]['text'] ?? '', true)['uid'] ?? 0);
        $this->assertGreaterThan(0, $uid, json_encode($result));

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

    public function testToolUsingAnExtbaseRepositoryRunsThroughTheMcpEndpoint(): void
    {
        // A frontend-typed request routes Extbase to the frontend configuration
        // manager, which throws without the frontend.typoscript attribute.
        $result = $this->callToolThroughMiddleware('ExtbaseProbe', []);
        $this->assertFalse($result['isError'] ?? true, json_encode($result));

        $this->assertGreaterThan(0, (int)($result['content'][0]['text'] ?? 0));
    }

    public function testUploadFileWithATranslatingListenerRunsThroughTheMcpEndpoint(): void
    {
        $this->createFileStorage();

        // The fixture listener calls LocalizationUtility::translate() without a
        // request. On a frontend-typed request, Locales dereferences the missing
        // site attribute.
        $result = $this->callToolThroughMiddleware('UploadFile', [
            'content' => 'hello',
            'fileName' => 'listener.txt',
            'targetFolder' => '/user_upload/',
        ]);
        $this->assertFalse($result['isError'] ?? true, json_encode($result));

        $this->assertStringContainsString('listener.txt', $result['content'][0]['text'] ?? '');
    }

    public function testNonAdminUploadIntoOwnFileMountSucceedsThroughTheMcpEndpoint(): void
    {
        $this->createFileStorage();

        // On a backend-typed request the core StoragePermissionsAspect applies
        // the mounts before FileUploadService::applyUserPermissionsToStorage().
        $result = $this->callToolThroughMiddleware('UploadFile', [
            'content' => 'hello',
            'fileName' => 'editor.txt',
            'targetFolder' => '/user_upload/',
        ], $this->createEditorWithUploadMount());
        $this->assertFalse($result['isError'] ?? true, json_encode($result));

        $this->assertFileExists(Environment::getPublicPath() . '/fileadmin/user_upload/editor.txt');
    }

    public function testNonAdminUploadOutsideOwnFileMountIsRejectedThroughTheMcpEndpoint(): void
    {
        $this->createFileStorage();
        GeneralUtility::mkdir_deep(Environment::getPublicPath() . '/fileadmin/secret');

        $result = $this->callToolThroughMiddleware('UploadFile', [
            'content' => 'hello',
            'fileName' => 'editor.txt',
            'targetFolder' => '/secret/',
        ], $this->createEditorWithUploadMount());

        $this->assertTrue($result['isError'] ?? false, json_encode($result));
        $this->assertFileDoesNotExist(Environment::getPublicPath() . '/fileadmin/secret/editor.txt');
    }

    public function testPreSignedUploadWithATranslatingListenerRunsThroughTheUploadEndpoint(): void
    {
        $this->createFileStorage();
        $this->setUpBackendUser(1);
        $service = GeneralUtility::makeInstance(FileUploadService::class);
        $token = $service->createUploadToken($service->resolveTargetFolder('/user_upload/'), 'presigned.txt')['token'];
        // The PUT arrives without a session, the endpoint impersonates the token's user itself.
        unset($GLOBALS['BE_USER']);

        $request = $this->createRequest('/mcp_upload?token=' . rawurlencode($token), 'PUT', body: 'hello')
            ->withQueryParams(['token' => $token]);

        $response = $this->middleware()->process($request, $this->sentinelHandler());

        $this->assertSame(201, $response->getStatusCode(), (string)$response->getBody());
    }

    private function createEditorWithUploadMount(): int
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_filemounts.csv');

        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('be_users');
        $connection->insert('be_users', [
            'pid' => 0,
            'username' => 'editor',
            'password' => '$argon2i$v=19$m=65536,t=16,p=1$dGVzdHNhbHQ$testpasswordhash',
            'admin' => 0,
            'file_mountpoints' => '1',
            'file_permissions' => 'readFolder,writeFolder,addFolder,readFile,writeFile,addFile',
        ]);

        return (int)$connection->lastInsertId();
    }

    private function createFileStorage(): void
    {
        GeneralUtility::mkdir_deep(Environment::getPublicPath() . '/fileadmin/user_upload');
        GeneralUtility::makeInstance(StorageRepository::class)
            ->createLocalStorage('fileadmin', 'fileadmin/', 'relative', '', true);
    }

    /**
     * Sends a single stateless (2026-07-28) tool call through the middleware,
     * exactly like a real HTTP request reaches the endpoint, and returns the
     * tool result.
     */
    private function callToolThroughMiddleware(string $name, array $arguments, int $userId = 1): array
    {
        $token = GeneralUtility::makeInstance(OAuthService::class)
            ->createToken($userId, 'request-global-test')['access_token'];

        $request = $this->createRequest('/mcp', 'POST', json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => $arguments === [] ? new \stdClass() : $arguments,
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                    'io.modelcontextprotocol/client' => ['name' => 'request-global-test', 'version' => '1.0'],
                    'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                ],
            ],
        ]), [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => $name,
        ]);

        $response = $this->middleware()->process($request, $this->sentinelHandler());

        $raw = (string)$response->getBody();
        $this->assertSame(200, $response->getStatusCode(), $raw);
        $body = json_decode($raw, true);
        $this->assertIsArray($body, $raw);
        $this->assertArrayHasKey('result', $body, $raw);

        return $body['result'];
    }

    private function middleware(): McpServerMiddleware
    {
        return new McpServerMiddleware(GeneralUtility::makeInstance(Context::class));
    }

    private function createRequest(
        string $requestUri,
        string $method = 'GET',
        ?string $body = null,
        array $headers = []
    ): ServerRequestInterface {
        $serverParams = [
            'HTTP_HOST' => 'example.com',
            'HTTPS' => 'on',
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => '/var/www/html/index.php',
            'REQUEST_URI' => $requestUri,
        ];

        $stream = 'php://input';
        if ($body !== null) {
            $stream = new Stream('php://temp', 'rw');
            $stream->write($body);
            $stream->rewind();
        }

        $request = new ServerRequest(
            new Uri('https://example.com' . $requestUri),
            $method,
            $stream,
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
