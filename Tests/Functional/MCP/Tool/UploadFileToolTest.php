<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use Hn\McpServer\MCP\Tool\File\UploadFileTool;
use Hn\McpServer\Service\SiteInformationService;
use Hn\McpServer\Tests\Functional\Fixtures\ThrowingOnlineMediaHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Tests for the UploadFile MCP tool.
 *
 * HTTP downloads are intercepted by a Guzzle handler middleware registered via
 * $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'], so no real network access
 * happens. Test URLs use public-range IP literals (203.0.113.0/24, TEST-NET-3)
 * so the SSRF host validation passes without DNS resolution.
 */
class UploadFileToolTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_filemounts.csv');

        // Create the default "fileadmin" storage (uid 1) with a real directory
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
     * Register a fake HTTP layer. Map of url-prefix => Response; anything
     * unmatched gets a 404.
     *
     * @param array<string, Response> $responses
     */
    protected function mockHttpResponses(array $responses): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = [
            'mcp_test_mock' => function (callable $handler) use ($responses) {
                return function ($request, array $options) use ($responses) {
                    $url = (string)$request->getUri();
                    foreach ($responses as $prefix => $response) {
                        if (str_starts_with($url, $prefix)) {
                            return new FulfilledPromise($response);
                        }
                    }
                    return new FulfilledPromise(new Response(404, [], 'not mocked: ' . $url));
                };
            },
        ];
    }

    /**
     * A real (valid) 1x1 transparent PNG so TYPO3's resource consistency check
     * (content must match the file extension) passes.
     */
    protected function pngBytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    protected function executeUpload(array $params): array
    {
        $result = GeneralUtility::makeInstance(UploadFileTool::class)->execute($params);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        return json_decode($result->content[0]->text, true);
    }

    protected function assertUploadError(array $params, string $messagePart): void
    {
        $result = GeneralUtility::makeInstance(UploadFileTool::class)->execute($params);
        $this->assertTrue($result->isError, 'Expected an error result, got: ' . json_encode($result->jsonSerialize()));
        $this->assertStringContainsStringIgnoringCase($messagePart, $result->content[0]->text);
    }

    // ---------------------------------------------------------------
    // content uploads
    // ---------------------------------------------------------------

    public function testContentUploadCreatesFile(): void
    {
        $text = "Some plain text content\nwith a second line.";
        $data = $this->executeUpload([
            'content' => $text,
            'fileName' => 'readme.txt',
            'targetFolder' => '/user_upload/',
        ]);

        $this->assertGreaterThan(0, $data['uid']);
        $this->assertEquals('readme.txt', $data['fileName']);
        $this->assertEquals('1:/user_upload/readme.txt', $data['identifier']);
        $this->assertArrayHasKey('nextStep', $data);

        // sys_file row exists and physical file has the content
        $file = GeneralUtility::makeInstance(ResourceFactory::class)->getFileObject($data['uid']);
        $this->assertEquals($text, $file->getContents());

        // metadata record was created by the indexer
        $count = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_metadata')
            ->count('uid', 'sys_file_metadata', ['file' => $data['uid']]);
        $this->assertEquals(1, $count, 'A sys_file_metadata record should exist for the uploaded file');
    }

    public function testContentUploadIntoNestedFolderCreatesFolders(): void
    {
        $data = $this->executeUpload([
            'content' => "a;b;c\n1;2;3",
            'fileName' => 'table.csv',
            'targetFolder' => '/user_upload/reports/2026/',
        ]);

        $this->assertEquals('1:/user_upload/reports/2026/table.csv', $data['identifier']);
        $this->assertFileExists(Environment::getPublicPath() . '/fileadmin/user_upload/reports/2026/table.csv');
    }

    public function testContentUploadRequiresFileName(): void
    {
        $this->assertUploadError(['content' => 'hello'], 'fileName');
    }

    public function testUrlAndContentTogetherAreRejected(): void
    {
        $this->assertUploadError(
            ['url' => 'http://203.0.113.10/a.jpg', 'content' => 'x'],
            'only one'
        );
    }

    public function testNoSourceReturnsPresignedUploadUrl(): void
    {
        $data = $this->executeUpload(['targetFolder' => '/user_upload/']);

        $this->assertEquals(
            'https://example.com/mcp_upload',
            $data['uploadUrl'],
            'The upload URL must be absolute, resolved from the site base, and must not carry the token'
        );
        $this->assertNotEmpty($data['uploadToken']);
        $this->assertEquals('1:/user_upload/', $data['targetFolder']);
        $this->assertArrayHasKey('validUntil', $data);
        $this->assertStringContainsString('curl', $data['instructions']);
        $this->assertStringContainsString('Authorization: Bearer', $data['instructions']);

        // A hashed single-use token was persisted
        $count = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_upload_tokens')
            ->count('uid', 'tx_mcpserver_upload_tokens', ['used' => 0]);
        $this->assertEquals(1, $count);
    }

    public function testPresignedUploadUrlIncludesSubdirectoryPrefix(): void
    {
        // TYPO3 installed at https://example.com/subdir/: the middleware routes
        // /subdir/mcp_upload, so the generated URL must carry the prefix.
        $serverParams = [
            'HTTP_HOST' => 'example.com',
            'HTTPS' => 'on',
            'SCRIPT_NAME' => '/subdir/index.php',
            'SCRIPT_FILENAME' => Environment::getPublicPath() . '/index.php',
            'REQUEST_URI' => '/subdir/mcp',
        ];
        $request = new ServerRequest(new Uri('https://example.com/subdir/mcp'), 'POST', null, [], $serverParams);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        $siteInformation = GeneralUtility::makeInstance(SiteInformationService::class);
        $siteInformation->setCurrentRequest($request);
        try {
            $data = $this->executeUpload(['targetFolder' => '/user_upload/']);
        } finally {
            $siteInformation->setCurrentRequest(null);
        }

        $this->assertEquals('https://example.com/subdir/mcp_upload', $data['uploadUrl']);
    }

    public function testSchemaOffersDefaultTargetFolder(): void
    {
        $schema = GeneralUtility::makeInstance(UploadFileTool::class)->getSchema();
        $this->assertEquals(
            '1:/user_upload/',
            $schema['inputSchema']['properties']['targetFolder']['default'] ?? null,
            'The schema should advertise the default upload folder'
        );
    }

    public function testPhpFileIsRejected(): void
    {
        $this->assertUploadError(
            ['content' => '<?php echo 1;', 'fileName' => 'evil.php', 'targetFolder' => '/user_upload/'],
            'extension'
        );
    }

    /**
     * TYPO3 is a PHP application, so a smuggled-in executable file is the worst
     * case. The core fileDenyPattern covers most of this, but it is a
     * configurable setting, so the upload path refuses these independently.
     */
    #[DataProvider('executableFileNameProvider')]
    public function testExecutableAndServerConfigFilesAreRejected(string $fileName, string $content): void
    {
        $this->assertUploadError(
            ['content' => $content, 'fileName' => $fileName, 'targetFolder' => '/user_upload/'],
            'not allowed'
        );

        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_file');
        $qb->getRestrictions()->removeAll();
        $this->assertEquals(
            0,
            (int)$qb->count('uid')->from('sys_file')->executeQuery()->fetchOne(),
            'No sys_file record may exist after a rejected upload of "' . $fileName . '"'
        );
        $this->assertFileDoesNotExist(
            Environment::getPublicPath() . '/fileadmin/user_upload/' . $fileName,
            'The rejected file "' . $fileName . '" must not be on disk'
        );
    }

    public function testRejectedFileNameDoesNotCreateTheTargetFolder(): void
    {
        // The name is checked before the storage is touched, so a rejected
        // upload leaves no empty folder behind.
        $this->assertUploadError(
            ['content' => '<?php echo 1;', 'fileName' => 'evil.php', 'targetFolder' => '/user_upload/brand-new/'],
            'not allowed'
        );

        $this->assertDirectoryDoesNotExist(Environment::getPublicPath() . '/fileadmin/user_upload/brand-new');
    }

    public function testFailedDownloadDoesNotLeaveAnEmptyFolderBehind(): void
    {
        $this->mockHttpResponses([]); // everything 404s

        $this->assertUploadError(
            ['url' => 'http://203.0.113.10/missing.jpg', 'targetFolder' => '/user_upload/download-fail/'],
            '404'
        );

        $this->assertDirectoryDoesNotExist(Environment::getPublicPath() . '/fileadmin/user_upload/download-fail');
    }

    public static function executableFileNameProvider(): array
    {
        $php = '<?php echo 1;';
        return [
            // Server-side execution
            'php' => ['evil.php', $php],
            'php uppercase' => ['EVIL.PHP', $php],
            'phtml' => ['evil.phtml', $php],
            'php5' => ['evil.php5', $php],
            'phar' => ['evil.phar', $php],
            'pht' => ['evil.pht', $php],
            'phps' => ['evil.phps', $php],
            'shtml' => ['evil.shtml', '<!--#exec cmd="ls"-->'],
            'cgi' => ['evil.cgi', "#!/bin/sh\nls"],
            'perl' => ['evil.pl', 'print "x";'],
            'shell' => ['evil.sh', "#!/bin/sh\nls"],
            // Double extensions: enough on servers mapping by any extension
            'php before jpg' => ['evil.php.jpg', $php],
            'jpg before php' => ['evil.jpg.php', $php],
            'php in the middle' => ['report.php.txt', $php],
            // Server/PHP reconfiguration
            'htaccess' => ['.htaccess', 'AddHandler application/x-httpd-php .jpg'],
            'user.ini' => ['.user.ini', "[PHP]\nauto_prepend_file = \"/tmp/x\"\n"],
            'user.ini uppercase' => ['.USER.INI', "[PHP]\nauto_prepend_file = \"/tmp/x\"\n"],
            'htpasswd' => ['.htpasswd', 'admin:hash'],
            'web.config' => ['web.config', '<configuration/>'],
        ];
    }

    public function testFileNameWithoutExtensionIsRejected(): void
    {
        $this->assertUploadError(
            ['content' => 'hello', 'fileName' => 'noextension', 'targetFolder' => '/user_upload/'],
            'extension'
        );
    }

    public function testBrowserExecutableFilesAreRejected(): void
    {
        // Served from fileadmin these would run script code in the site's origin
        // (stored XSS); the core fileDenyPattern only covers server-side execution.
        $this->assertUploadError(
            ['content' => '<script>alert(1)</script>', 'fileName' => 'page.html', 'targetFolder' => '/user_upload/'],
            'not allowed'
        );
        $this->assertUploadError(
            ['content' => 'alert(1)', 'fileName' => 'app.js', 'targetFolder' => '/user_upload/'],
            'not allowed'
        );
    }

    // ---------------------------------------------------------------
    // conflict handling: rename + dedupe
    // ---------------------------------------------------------------

    public function testDuplicateNameIsRenamedNotOverwritten(): void
    {
        $first = $this->executeUpload([
            'content' => 'first content',
            'fileName' => 'notes.txt',
            'targetFolder' => '/user_upload/',
        ]);
        $second = $this->executeUpload([
            'content' => 'different content',
            'fileName' => 'notes.txt',
            'targetFolder' => '/user_upload/',
        ]);

        $this->assertNotEquals($first['uid'], $second['uid']);
        $this->assertNotEquals('notes.txt', $second['fileName'], 'Second upload must not reuse the taken name');
        $this->assertEquals('notes.txt', $second['renamedFrom']);

        // The first file is untouched
        $file = GeneralUtility::makeInstance(ResourceFactory::class)->getFileObject($first['uid']);
        $this->assertEquals('first content', $file->getContents());
    }

    public function testIdenticalContentIsDeduplicated(): void
    {
        $first = $this->executeUpload([
            'content' => 'identical bytes',
            'fileName' => 'a.txt',
            'targetFolder' => '/user_upload/',
        ]);
        $second = $this->executeUpload([
            'content' => 'identical bytes',
            'fileName' => 'b.txt',
            'targetFolder' => '/user_upload/',
        ]);

        $this->assertEquals($first['uid'], $second['uid'], 'Identical content should return the existing file');
        $this->assertTrue($second['deduplicated']);

        $count = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file')
            ->count('uid', 'sys_file', ['storage' => 1]);
        $this->assertEquals(1, $count, 'No duplicate sys_file record should have been created');
    }

    // ---------------------------------------------------------------
    // url uploads
    // ---------------------------------------------------------------

    public function testUrlUploadDownloadsFile(): void
    {
        $bytes = $this->pngBytes();
        $this->mockHttpResponses([
            'http://203.0.113.10/images/photo.png' => new Response(200, ['Content-Type' => 'image/png'], $bytes),
        ]);

        $data = $this->executeUpload([
            'url' => 'http://203.0.113.10/images/photo.png',
            'targetFolder' => '/user_upload/',
        ]);

        $this->assertEquals('photo.png', $data['fileName']);
        $file = GeneralUtility::makeInstance(ResourceFactory::class)->getFileObject($data['uid']);
        $this->assertEquals($bytes, $file->getContents());
    }

    public function testUrlUploadFollowsRedirects(): void
    {
        $this->mockHttpResponses([
            'http://203.0.113.10/start' => new Response(302, ['Location' => '/moved/final.png']),
            'http://203.0.113.10/moved/final.png' => new Response(200, ['Content-Type' => 'image/png'], $this->pngBytes()),
        ]);

        $data = $this->executeUpload([
            'url' => 'http://203.0.113.10/start',
            'targetFolder' => '/user_upload/',
        ]);

        // File name is derived from the redirect target
        $this->assertEquals('final.png', $data['fileName']);
    }

    public function testRedirectToPrivateAddressIsRejected(): void
    {
        // Every redirect hop must pass the SSRF validation, not just the first URL.
        $this->mockHttpResponses([
            'http://203.0.113.10/innocent' => new Response(302, ['Location' => 'http://127.0.0.1/internal.png']),
        ]);

        $this->assertUploadError(
            ['url' => 'http://203.0.113.10/innocent', 'targetFolder' => '/user_upload/'],
            'private or reserved'
        );
    }

    public function testWebPageUrlIsRejectedWithAnActionableMessage(): void
    {
        // Pasting a normal website URL is a common mistake; the message must
        // point at the URL, not at file extensions or content mismatches.
        $this->mockHttpResponses([
            'http://203.0.113.10/some-article' => new Response(
                200,
                ['Content-Type' => 'text/html; charset=utf-8'],
                "<!DOCTYPE html>\n<html><head><title>An article</title></head><body><p>Text</p></body></html>"
            ),
        ]);

        $this->assertUploadError(
            ['url' => 'http://203.0.113.10/some-article', 'targetFolder' => '/user_upload/'],
            'web page'
        );
    }

    public function testWebPageCannotBeSmuggledInUnderAnImageFileName(): void
    {
        $this->mockHttpResponses([
            'http://203.0.113.10/page' => new Response(
                200,
                ['Content-Type' => 'text/html'],
                '<html><body>not an image</body></html>'
            ),
        ]);

        $this->assertUploadError(
            ['url' => 'http://203.0.113.10/page', 'fileName' => 'looks-like.jpg', 'targetFolder' => '/user_upload/'],
            'web page'
        );
    }

    public function testMislabeledImageIsStillAccepted(): void
    {
        // Servers that declare a real image as text/html must keep working:
        // the HTML detection looks at the content, not at the header.
        $this->mockHttpResponses([
            'http://203.0.113.10/photo.png' => new Response(200, ['Content-Type' => 'text/html'], $this->pngBytes()),
        ]);

        $data = $this->executeUpload([
            'url' => 'http://203.0.113.10/photo.png',
            'targetFolder' => '/user_upload/',
        ]);

        // The exact name may carry a _01 suffix: the test instance's fileadmin
        // survives between tests of this class while the database is reset.
        $this->assertStringEndsWith('.png', $data['fileName']);
        $this->assertEquals('image/png', $data['mimeType']);
    }

    public function testCgnatAddressIsRejected(): void
    {
        // 100.64.0.0/10 (CGNAT) passes PHP's filter flags but is cloud-internal
        // (e.g. Alibaba metadata endpoint) and must be blocked explicitly.
        $this->assertUploadError(
            ['url' => 'http://100.100.100.200/latest/meta-data', 'targetFolder' => '/user_upload/'],
            'private or reserved'
        );
    }

    public function testUrlUploadDerivesExtensionFromContentType(): void
    {
        $this->mockHttpResponses([
            'http://203.0.113.10/image?id=42' => new Response(200, ['Content-Type' => 'image/png'], $this->pngBytes()),
        ]);

        $data = $this->executeUpload([
            'url' => 'http://203.0.113.10/image?id=42',
            'targetFolder' => '/user_upload/',
        ]);

        $this->assertStringEndsWith('.png', $data['fileName']);
    }

    public function testUrlUploadPrefersContentDispositionFileName(): void
    {
        $this->mockHttpResponses([
            'http://203.0.113.10/download?id=7' => new Response(200, [
                'Content-Type' => 'image/png',
                'Content-Disposition' => 'attachment; filename="pretty-name.png"',
            ], $this->pngBytes()),
        ]);

        $data = $this->executeUpload([
            'url' => 'http://203.0.113.10/download?id=7',
            'targetFolder' => '/user_upload/',
        ]);

        $this->assertEquals('pretty-name.png', $data['fileName']);
    }

    public function testUrlUploadFailsOnHttpError(): void
    {
        $this->mockHttpResponses([]);
        $this->assertUploadError(
            ['url' => 'http://203.0.113.10/missing.jpg', 'targetFolder' => '/user_upload/'],
            '404'
        );
    }

    public function testPrivateAddressesAreRejected(): void
    {
        // No mock registered on purpose: validation must fail before any request
        foreach (['http://127.0.0.1/x.jpg', 'http://192.168.1.5/x.jpg', 'http://10.0.0.8/x.jpg', 'http://169.254.169.254/latest'] as $url) {
            $this->assertUploadError(['url' => $url, 'targetFolder' => '/user_upload/'], 'private or reserved');
        }
    }

    public function testNonHttpSchemesAreRejected(): void
    {
        $this->assertUploadError(['url' => 'ftp://203.0.113.10/x.jpg', 'targetFolder' => '/user_upload/'], 'http');
        $this->assertUploadError(['url' => 'file:///etc/passwd', 'targetFolder' => '/user_upload/'], 'http');
    }

    // ---------------------------------------------------------------
    // online media (YouTube/Vimeo)
    // ---------------------------------------------------------------

    public function testYoutubeUrlCreatesOnlineMediaAsset(): void
    {
        // oEmbed title lookup is unmocked (404) so the file falls back to the video id
        $this->mockHttpResponses([]);

        $data = $this->executeUpload([
            'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'targetFolder' => '/user_upload/',
        ]);

        $this->assertTrue($data['onlineMedia']);
        $this->assertStringEndsWith('.youtube', $data['fileName']);

        // The placeholder file contains just the video id
        $file = GeneralUtility::makeInstance(ResourceFactory::class)->getFileObject($data['uid']);
        $this->assertEquals('dQw4w9WgXcQ', trim($file->getContents()));
    }

    public function testContentRewrittenDuringUploadIsStillDeduplicated(): void
    {
        // The core SVG sanitizer rewrites SVG content while it is stored, so the
        // hash of the upload differs from the hash of the stored file. The dedupe
        // must catch this via the post-store hash check.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>';

        $first = $this->executeUpload([
            'content' => $svg,
            'fileName' => 'shape.svg',
            'targetFolder' => '/user_upload/',
        ]);
        $second = $this->executeUpload([
            'content' => $svg,
            'fileName' => 'shape.svg',
            'targetFolder' => '/user_upload/',
        ]);

        $storedSha1 = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file')
            ->select(['sha1'], 'sys_file', ['uid' => $first['uid']])
            ->fetchOne();
        $this->assertNotEquals(sha1($svg), $storedSha1, 'Precondition: the sanitizer must actually rewrite the SVG for this test to be meaningful');

        $this->assertEquals($first['uid'], $second['uid'], 'Identical (sanitizer-rewritten) content should return the existing file');
        $this->assertTrue($second['deduplicated'] ?? false);

        $count = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file')
            ->count('uid', 'sys_file', ['storage' => 1]);
        $this->assertEquals(1, $count, 'No duplicate sys_file record should have been created');
    }

    public function testYoutubeUrlIsDeduplicated(): void
    {
        $this->mockHttpResponses([]);

        $first = $this->executeUpload([
            'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'targetFolder' => '/user_upload/',
        ]);
        $second = $this->executeUpload([
            'url' => 'https://youtu.be/dQw4w9WgXcQ',
            'targetFolder' => '/user_upload/',
        ]);

        $this->assertEquals($first['uid'], $second['uid'], 'Same video should not create a second asset');
        $this->assertTrue($second['deduplicated']);
    }

    public function testThrowingThirdPartyOnlineMediaHelperDoesNotBreakUrlUploads(): void
    {
        // Real-world case: a project helper for a media database only supports
        // creation from a JSON payload and throws from transformUrlToFile()
        // instead of returning null. Every non-YouTube/Vimeo URL upload failed
        // with "An unexpected error occurred" because the registry loop
        // reached that helper.
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['onlineMediaHelpers']['broken'] = ThrowingOnlineMediaHelper::class;
        ThrowingOnlineMediaHelper::$calls = 0;
        $this->mockHttpResponses([
            'http://203.0.113.10/images/skipped-helper.png' => new Response(200, ['Content-Type' => 'image/png'], $this->pngBytes()),
        ]);

        $data = $this->executeUpload([
            'url' => 'http://203.0.113.10/images/skipped-helper.png',
            'targetFolder' => '/user_upload/',
        ]);

        $this->assertEquals('skipped-helper.png', $data['fileName']);
        $this->assertArrayNotHasKey('onlineMedia', $data);
        $this->assertSame(1, ThrowingOnlineMediaHelper::$calls, 'The broken helper must be consulted once and then skipped');
    }

    public function testOnlineMediaHelpersBehindAThrowingOneAreStillConsulted(): void
    {
        // The broken helper comes first, so YouTube is only recognized if the
        // lookup continues past the exception instead of giving up.
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['onlineMediaHelpers'] =
            ['broken' => ThrowingOnlineMediaHelper::class] + $GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['onlineMediaHelpers'];
        $this->mockHttpResponses([]);

        $data = $this->executeUpload([
            'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'targetFolder' => '/user_upload/',
        ]);

        $this->assertTrue($data['onlineMedia']);
        $this->assertStringEndsWith('.youtube', $data['fileName']);
    }

    // ---------------------------------------------------------------
    // default folder + permissions
    // ---------------------------------------------------------------

    public function testUploadWithoutTargetFolderUsesDefaultUploadFolder(): void
    {
        $data = $this->executeUpload([
            'content' => 'default folder upload',
            'fileName' => 'default.txt',
        ]);

        $this->assertGreaterThan(0, $data['uid']);
        $this->assertStringStartsWith('1:/', $data['identifier']);
    }

    public function testNonAdminCanUploadIntoMountedFolder(): void
    {
        // The mounted folder must exist for the file mount to be resolvable
        GeneralUtility::mkdir_deep(Environment::getPublicPath() . '/fileadmin/user_upload');

        $uid = $this->createNonAdminUserWithMounts('1'); // mount 1 = "1:/user_upload/"
        $this->authenticateUser($uid);

        $data = $this->executeUpload([
            'content' => 'editor upload',
            'fileName' => 'editor.txt',
            'targetFolder' => '/user_upload/',
        ]);
        $this->assertEquals('1:/user_upload/editor.txt', $data['identifier']);
    }

    public function testNonAdminCannotUploadOutsideMounts(): void
    {
        // Physically create /secret/ so the folder exists but is outside the mount
        GeneralUtility::mkdir_deep(Environment::getPublicPath() . '/fileadmin/secret');

        $uid = $this->createNonAdminUserWithMounts('1'); // only "1:/user_upload/"
        $this->authenticateUser($uid);

        $this->assertUploadError(
            ['content' => 'sneaky', 'fileName' => 'sneaky.txt', 'targetFolder' => '/secret/'],
            'permission'
        );
    }

    // ---------------------------------------------------------------
    // helpers for non-admin users (mirrors FileMountPermissionTest)
    // ---------------------------------------------------------------

    protected function createNonAdminUserWithMounts(string $fileMountUids): int
    {
        GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime')->flush();

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('be_users');
        $connection->insert('be_users', [
            'pid' => 0,
            'username' => 'editor_' . uniqid(),
            'password' => '$argon2i$v=19$m=65536,t=16,p=1$dGVzdHNhbHQ$testpasswordhash',
            'admin' => 0,
            'file_mountpoints' => $fileMountUids,
            'deleted' => 0,
            'disable' => 0,
            'tstamp' => time(),
            'crdate' => time(),
            'email' => 'test' . uniqid() . '@example.com',
        ]);

        return (int)$connection->lastInsertId();
    }

    protected function authenticateUser(int $uid): BackendUserAuthentication
    {
        $user = $this->setUpBackendUser($uid);
        $user->groupData['tables_select'] = 'sys_file,pages,tt_content';
        $user->groupData['tables_modify'] = 'sys_file,pages,tt_content';
        $user->groupData['filemounts'] = (string)($user->user['file_mountpoints'] ?? '');
        // FAL write operations check granular file permissions for non-admins
        $user->groupData['file_permissions'] = 'readFolder,writeFolder,addFolder,readFile,writeFile,addFile,copyFile,moveFile,renameFile';
        $user->user['admin'] = 0;
        $GLOBALS['BE_USER'] = $user;

        GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime')->flush();

        return $user;
    }
}
