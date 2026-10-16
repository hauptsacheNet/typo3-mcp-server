<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\FileUploadEndpoint;
use Hn\McpServer\MCP\Tool\File\UploadFileTool;
use Hn\McpServer\Service\FileUploadService;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Http\UploadedFile;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Tests for the pre-signed upload endpoint (/mcp_upload).
 *
 * Flow under test: the UploadFile MCP tool (or FileUploadService directly)
 * mints a single-use token; the client PUTs raw file bytes to the endpoint;
 * the endpoint stores the file as the token's backend user and returns the
 * created sys_file as JSON.
 */
class FileUploadEndpointTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');

        GeneralUtility::mkdir_deep(Environment::getPublicPath() . '/fileadmin');
        GeneralUtility::makeInstance(StorageRepository::class)
            ->createLocalStorage('fileadmin', 'fileadmin/', 'relative', '', true);

        // A site with a fully qualified base URL: without one (and without an
        // HTTP request context) pre-signed upload URLs cannot be built.
        $siteDir = $this->instancePath . '/typo3conf/sites/test-site';
        GeneralUtility::mkdir_deep($siteDir);
        GeneralUtility::writeFile($siteDir . '/config.yaml', "rootPageId: 1\nbase: 'https://example.com/'\n", true);

        $this->setUpBackendUser(1);
    }

    /**
     * Create an upload token for the admin user, targeting /user_upload/.
     */
    protected function createToken(string $fileName = ''): string
    {
        $service = GeneralUtility::makeInstance(FileUploadService::class);
        $folder = $service->resolveTargetFolder('/user_upload/');
        return $service->createUploadToken($folder, $fileName)['token'];
    }

    protected function dispatchUpload(string $token, string $body, array $extraQuery = [], string $method = 'PUT', array $headers = []): \Psr\Http\Message\ResponseInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write($body);
        $stream->rewind();

        $request = (new ServerRequest(
            new Uri('https://example.com/mcp_upload'),
            $method,
            $stream,
            $headers
        ))->withQueryParams(array_merge(['token' => $token], $extraQuery));

        return (new FileUploadEndpoint())($request);
    }

    protected function pngBytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    public function testUploadStoresFileAndReturnsJson(): void
    {
        $token = $this->createToken();
        $response = $this->dispatchUpload($token, $this->pngBytes(), ['fileName' => 'uploaded.png']);

        $this->assertEquals(201, $response->getStatusCode(), (string)$response->getBody());
        $data = json_decode((string)$response->getBody(), true);

        $this->assertEquals('uploaded.png', $data['fileName']);
        $this->assertEquals('1:/user_upload/uploaded.png', $data['identifier']);
        $this->assertGreaterThan(0, $data['uid']);

        $file = GeneralUtility::makeInstance(ResourceFactory::class)->getFileObject($data['uid']);
        $this->assertEquals($this->pngBytes(), $file->getContents());
    }

    public function testTokenIsSingleUse(): void
    {
        $token = $this->createToken();

        $first = $this->dispatchUpload($token, $this->pngBytes(), ['fileName' => 'once.png']);
        $this->assertEquals(201, $first->getStatusCode());

        $second = $this->dispatchUpload($token, $this->pngBytes(), ['fileName' => 'twice.png']);
        $this->assertEquals(401, $second->getStatusCode(), 'A used token must be rejected');
    }

    public function testInvalidTokenIsRejected(): void
    {
        $response = $this->dispatchUpload('not-a-real-token', $this->pngBytes(), ['fileName' => 'x.png']);
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testFileNameFromTokenIsUsed(): void
    {
        $token = $this->createToken('preset-name.png');
        $response = $this->dispatchUpload($token, $this->pngBytes());

        $this->assertEquals(201, $response->getStatusCode(), (string)$response->getBody());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('preset-name.png', $data['fileName']);
    }

    public function testFileNameFromContentDispositionHeader(): void
    {
        $token = $this->createToken();
        $response = $this->dispatchUpload(
            $token,
            $this->pngBytes(),
            [],
            'PUT',
            ['Content-Disposition' => 'attachment; filename="from-header.png"']
        );

        $this->assertEquals(201, $response->getStatusCode(), (string)$response->getBody());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('from-header.png', $data['fileName']);
    }

    public function testMissingFileNameIsRejected(): void
    {
        $token = $this->createToken();
        $response = $this->dispatchUpload($token, $this->pngBytes());
        $this->assertEquals(400, $response->getStatusCode());
        $this->assertStringContainsString('file name', json_decode((string)$response->getBody(), true)['error']);
    }

    public function testEmptyBodyIsRejected(): void
    {
        $token = $this->createToken();
        $response = $this->dispatchUpload($token, '', ['fileName' => 'empty.png']);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testTokenFileNameBeatsQueryParameter(): void
    {
        // The token authorizes exactly the file it was minted for; the request
        // must not be able to widen it to another name/type.
        $token = $this->createToken('bound-name.png');
        $response = $this->dispatchUpload($token, $this->pngBytes(), ['fileName' => 'sneaky-other.png']);

        $this->assertEquals(201, $response->getStatusCode(), (string)$response->getBody());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('bound-name.png', $data['fileName']);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $token = $this->createToken();
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_upload_tokens')
            ->update('tx_mcpserver_upload_tokens', ['expires' => time() - 10], []);

        $response = $this->dispatchUpload($token, $this->pngBytes(), ['fileName' => 'late.png']);
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testMultipartUploadIsAccepted(): void
    {
        $token = $this->createToken();

        $stream = new Stream('php://temp', 'rw');
        $stream->write($this->pngBytes());
        $stream->rewind();
        $uploadedFile = new UploadedFile($stream, strlen($this->pngBytes()), UPLOAD_ERR_OK, 'from-form.png');

        $request = (new ServerRequest(new Uri('https://example.com/mcp_upload'), 'POST'))
            ->withQueryParams(['token' => $token])
            ->withUploadedFiles(['file' => $uploadedFile]);

        $response = (new FileUploadEndpoint())($request);
        $this->assertEquals(201, $response->getStatusCode(), (string)$response->getBody());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('from-form.png', $data['fileName'], 'The multipart client file name should be used');
    }

    public function testFailedUploadConsumesTheToken(): void
    {
        // The token is consumed on the attempt, not on success: a leaked token
        // must be a single attempt, not a 15-minute upload permit with retries.
        $token = $this->createToken();

        $failed = $this->dispatchUpload($token, '', ['fileName' => 'empty.png']);
        $this->assertEquals(400, $failed->getStatusCode());

        $retry = $this->dispatchUpload($token, $this->pngBytes(), ['fileName' => 'retry.png']);
        $this->assertEquals(401, $retry->getStatusCode(), 'A token must not survive a failed upload attempt');
    }

    /**
     * Same hazard as #107 on the /mcp endpoint: the upload endpoint impersonates
     * a backend user without the regular authentication flow, so it has to
     * restore the stored uc itself. Otherwise a writeUC() during the upload
     * overwrites the user's backend preferences with a nearly empty array.
     */
    public function testStoredUserConfigurationSurvivesAnUpload(): void
    {
        $storedUc = ['titleLen' => 77, 'lang' => 'de', 'emailMeAtLogin' => 1];
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('be_users');
        $connection->update('be_users', ['uc' => serialize($storedUc)], ['uid' => 1]);

        $token = $this->createToken('with-uc.png');
        $response = $this->dispatchUpload($token, $this->pngBytes());
        $this->assertEquals(201, $response->getStatusCode(), (string)$response->getBody());

        $this->assertEquals(77, $GLOBALS['BE_USER']->uc['titleLen'] ?? null, 'The impersonated user must carry the stored uc');

        // Persisting now must not wipe what was stored
        $GLOBALS['BE_USER']->writeUC();
        $persisted = unserialize((string)$connection->select(['uc'], 'be_users', ['uid' => 1])->fetchOne(), ['allowed_classes' => false]);
        $this->assertEquals(77, $persisted['titleLen'] ?? null, 'writeUC() must not destroy the stored backend preferences');
        $this->assertEquals('de', $persisted['lang'] ?? null);
    }

    public function testDisabledBackendUserCannotUpload(): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('be_users')
            ->update('be_users', ['disable' => 1], ['uid' => 1]);

        $token = $this->createToken('blocked.png');
        $response = $this->dispatchUpload($token, $this->pngBytes());

        $this->assertEquals(400, $response->getStatusCode(), (string)$response->getBody());
        $this->assertStringContainsString('not available', json_decode((string)$response->getBody(), true)['error']);
    }

    public function testExecutableFileIsRejectedAtTheEndpointToo(): void
    {
        // The pre-signed endpoint is a second door into the same storage; the
        // executable-file guard must apply there as well.
        $token = $this->createToken();
        $response = $this->dispatchUpload($token, '<?php echo 1;', ['fileName' => 'evil.php']);

        $this->assertEquals(400, $response->getStatusCode(), (string)$response->getBody());
        $this->assertStringContainsString('not allowed', json_decode((string)$response->getBody(), true)['error']);
        $this->assertFileDoesNotExist(Environment::getPublicPath() . '/fileadmin/user_upload/evil.php');
    }

    public function testGetMethodIsRejected(): void
    {
        $response = $this->dispatchUpload($this->createToken(), '', [], 'GET');
        $this->assertEquals(405, $response->getStatusCode());
    }

    /**
     * Full round trip: the UploadFile tool mints the URL, the endpoint consumes it.
     */
    public function testEndToEndFlowFromTool(): void
    {
        $result = GeneralUtility::makeInstance(UploadFileTool::class)->execute([
            'targetFolder' => '/user_upload/',
            'fileName' => 'roundtrip.png',
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $toolData = json_decode($result->content[0]->text, true);

        $this->assertNotEmpty($toolData['uploadToken']);
        $this->assertStringNotContainsString('token=', $toolData['uploadUrl'], 'The token must not appear in the URL');

        // Token travels as Authorization header, like the tool instructions say
        $response = $this->dispatchUpload('', $this->pngBytes(), [], 'PUT', [
            'Authorization' => 'Bearer ' . $toolData['uploadToken'],
        ]);
        $this->assertEquals(201, $response->getStatusCode(), (string)$response->getBody());

        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('roundtrip.png', $data['fileName'], 'File name preset in the tool call must be used');
        $this->assertEquals('1:/user_upload/roundtrip.png', $data['identifier']);
    }
}
