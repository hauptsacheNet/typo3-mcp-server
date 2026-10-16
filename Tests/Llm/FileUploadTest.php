<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use Hn\McpServer\Tests\Llm\Client\LlmResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Test whether LLMs discover and use the UploadFile tool for the four ways
 * files enter TYPO3 through MCP, phrased the way an editor would ask:
 *
 *   1. "put this YouTube video on the page"  → url mode, online media asset
 *   2. "put this image from the web on the page" → url mode, real download
 *   3. "draw me a chart and put it on the page" → content mode, generated SVG
 *   4. "the photo is on my computer"          → pre-signed upload URL
 *
 * The interesting part is not whether UploadFile works (functional tests cover
 * that) but whether the model picks the right mode from the description alone,
 * and whether it then connects the uploaded file to a record - an upload nobody
 * references is invisible on the website.
 *
 * HTTP downloads are intercepted by a Guzzle handler that passes everything
 * else (notably the OpenRouter API calls of the test harness itself) through
 * to the real handler.
 *
 * @group llm
 */
class FileUploadTest extends LlmTestCase
{
    /**
     * Download URL used in the prompts. An IP literal from TEST-NET-3
     * (203.0.113.0/24, reserved for documentation) on purpose: UploadFile
     * validates the host against the SSRF rules BEFORE requesting it, and a
     * literal skips DNS entirely, so the test needs no name resolution at all.
     * The address counts as public, so validation passes and the mock answers.
     */
    protected const IMAGE_URL = 'http://203.0.113.10/press/office-building.jpg';

    /**
     * Tool errors the model ran into, as "ToolName: message". Surfaced in
     * failure messages: the friction on the way to a passing run is what tells
     * us which part of a tool description is unclear.
     *
     * @var string[]
     */
    protected array $observedToolErrors = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->observedToolErrors = [];
        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/backend_layout.csv');
        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/tt_content.csv');

        // A real fileadmin storage: uploads write actual files
        GeneralUtility::mkdir_deep(Environment::getPublicPath() . '/fileadmin');
        GeneralUtility::makeInstance(StorageRepository::class)
            ->createLocalStorage('fileadmin', 'fileadmin/', 'relative', '', true);

        // Needed so pre-signed upload URLs can be built absolute
        $siteDir = $this->instancePath . '/typo3conf/sites/test-site';
        GeneralUtility::mkdir_deep($siteDir);
        GeneralUtility::writeFile($siteDir . '/config.yaml', "rootPageId: 1\nbase: 'https://example.com/'\n", true);

        $this->mockFileDownloads();
    }

    /**
     * Intercept the download URL used in the prompts below and let everything
     * else (OpenRouter, YouTube metadata lookups) hit the real handler.
     */
    protected function mockFileDownloads(): void
    {
        // A real 2x2 JPEG: TYPO3 verifies that the content matches the
        // extension, so PNG bytes behind a .jpg name would be rejected.
        $mocked = [
            self::IMAGE_URL => new Response(
                200,
                ['Content-Type' => 'image/jpeg'],
                base64_decode($this->jpegBase64())
            ),
        ];

        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = [
            'mcp_llm_upload_mock' => function (callable $handler) use ($mocked) {
                return function ($request, array $options) use ($handler, $mocked) {
                    $url = (string)$request->getUri();
                    foreach ($mocked as $prefix => $response) {
                        if (str_starts_with($url, $prefix)) {
                            return new FulfilledPromise($response);
                        }
                    }
                    // Everything else keeps working, including the LLM API itself
                    return $handler($request, $options);
                };
            },
        ];
    }

    /**
     * Fail loudly when the mock cannot answer (DNS trouble, changed handler):
     * otherwise a broken test setup looks like a failing language model. Goes
     * through RequestFactory only, so no sys_file is created as a side effect.
     */
    protected function assertMockedDownloadReachable(): void
    {
        try {
            $response = GeneralUtility::makeInstance(RequestFactory::class)->request(self::IMAGE_URL);
        } catch (\Throwable $e) {
            self::fail('Test setup problem: the mocked image URL is unreachable (' . $e->getMessage() . ')');
        }
        self::assertEquals(200, $response->getStatusCode(), 'Test setup problem: the download mock did not answer');
    }

    protected function jpegBase64(): string
    {
        return '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5'
            . 'ID0gNjAK/9sAQwANCQoLCggNCwoLDg4NDxMgFRMSEhMnHB4XIC4pMTAuKS0sMzpKPjM2RjcsLUBXQUZMTlJTUjI+WmFaUGBKUVJP'
            . '/9sAQwEODg4TERMmFRUmTzUtNU9PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09P/8AAEQgA'
            . 'AgACAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUS'
            . 'ITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0'
            . 'dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX2'
            . '9/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXET'
            . 'IjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKD'
            . 'hIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwD'
            . 'AQACEQMRAD8AZRRRX1Z8yf/Z';
    }

    // ------------------------------------------------------------------
    // 1. YouTube video
    // ------------------------------------------------------------------

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "Put this YouTube video on the home page" → UploadFile(url) creating an online media asset, then WriteTable referencing it')]
    public function testLlmPlacesYoutubeVideoOnPage(string $modelKey): void
    {
        $this->setModel($modelKey);
        $prompt = 'Please put this video on the home page: https://www.youtube.com/watch?v=dQw4w9WgXcQ '
            . 'Give the element the header "Our Company Film".';

        $response = $this->executeUntilToolFound($this->callLlm($prompt), 'UploadFile', 8);

        $uploadCalls = $response->getToolCallsByName('UploadFile');
        $this->assertNotEmpty(
            $uploadCalls,
            'Expected the LLM to use UploadFile for the YouTube URL instead of writing a raw URL into a field. '
            . 'History: ' . implode(' → ', $this->getToolCallHistory()) . "\n" . $this->getFailureContext($response)
        );
        $this->assertStringContainsString(
            'dQw4w9WgXcQ',
            (string)($uploadCalls[0]['arguments']['url'] ?? ''),
            'The video URL should be passed as "url". ' . $this->getFailureContext($response)
        );

        $fileUid = $this->runUntilFileUploaded($response);
        $this->assertNotNull($fileUid, 'No sys_file record was created for the video. ' . $this->toolErrorSummary() . $this->getFailureContext($this->lastResponse));

        $fileRow = $this->fetchFile($fileUid);
        $this->assertEquals('youtube', $fileRow['extension'], 'The video should become a .youtube online media asset');

        // The upload alone is invisible on the website - the model has to reference it
        $contentUid = $this->runUntilContentReferencesFile($fileUid);
        $this->assertNotNull(
            $contentUid,
            'The uploaded video was never referenced from a content element, so it stays invisible on the site. '
            . 'History: ' . implode(' → ', $this->getToolCallHistory()) . $this->toolErrorSummary()
            . "\n" . $this->getFailureContext($this->lastResponse)
        );
    }

    // ------------------------------------------------------------------
    // 2. Image from a URL
    // ------------------------------------------------------------------

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "Here is an image URL, put it on the About page" → UploadFile(url) downloading it, then WriteTable referencing the new file')]
    public function testLlmUploadsImageFromUrlAndPlacesItOnPage(string $modelKey): void
    {
        $this->setModel($modelKey);
        $prompt = 'I have an image here: ' . self::IMAGE_URL . ' '
            . 'Can you put it on the About page? The header should be "Our Office".';

        $response = $this->executeUntilToolFound($this->callLlm($prompt), 'UploadFile', 8);

        $uploadCalls = $response->getToolCallsByName('UploadFile');
        $this->assertNotEmpty(
            $uploadCalls,
            'Expected UploadFile for the image URL. A raw URL in a tt_content field would not create a FAL file. '
            . 'History: ' . implode(' → ', $this->getToolCallHistory()) . "\n" . $this->getFailureContext($response)
        );
        $this->assertStringContainsString(
            'office-building.jpg',
            (string)($uploadCalls[0]['arguments']['url'] ?? ''),
            'The image URL should be passed as "url", not as "content". ' . $this->getFailureContext($response)
        );

        $this->assertMockedDownloadReachable();

        $fileUid = $this->runUntilFileUploaded($response);
        $this->assertNotNull($fileUid, 'The image was never stored as a sys_file. ' . $this->toolErrorSummary() . $this->getFailureContext($this->lastResponse));

        $fileRow = $this->fetchFile($fileUid);
        $this->assertEquals('image/jpeg', $fileRow['mime_type']);
        $this->assertFileExists(
            Environment::getPublicPath() . '/fileadmin' . $fileRow['identifier'],
            'The downloaded image should exist on disk'
        );

        $contentUid = $this->runUntilContentReferencesFile($fileUid);
        $this->assertNotNull(
            $contentUid,
            'The uploaded image was never referenced from a content element. '
            . 'History: ' . implode(' → ', $this->getToolCallHistory()) . $this->toolErrorSummary()
            . "\n" . $this->getFailureContext($this->lastResponse)
        );

        $contentRow = $this->fetchContent($contentUid);
        $this->assertEquals(2, (int)$contentRow['pid'], 'The element should be created on the About page (uid=2)');
    }

    // ------------------------------------------------------------------
    // 3. Generated SVG (content mode)
    // ------------------------------------------------------------------

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "Draw a bar chart from these numbers and put it on the page" → UploadFile(content) with an .svg file name, then WriteTable referencing it')]
    public function testLlmGeneratesSvgChartAndPlacesItOnPage(string $modelKey): void
    {
        $this->setModel($modelKey);
        $prompt = 'Create a simple bar chart as an SVG graphic from these revenue figures and put it on the home page: '
            . '2023: 1.2 million, 2024: 1.8 million, 2025: 2.4 million. '
            . 'The content element header should be "Revenue Development".';

        $response = $this->executeUntilToolFound($this->callLlm($prompt), 'UploadFile', 8);

        $uploadCalls = $response->getToolCallsByName('UploadFile');
        $this->assertNotEmpty(
            $uploadCalls,
            'Expected UploadFile with generated SVG content. '
            . 'History: ' . implode(' → ', $this->getToolCallHistory()) . "\n" . $this->getFailureContext($response)
        );

        $args = $uploadCalls[0]['arguments'];
        $this->assertNotEmpty(
            $args['content'] ?? '',
            'Generated graphics must go through "content", not "url". Arguments: ' . json_encode($args)
            . "\n" . $this->getFailureContext($response)
        );
        $this->assertStringContainsString('<svg', (string)$args['content'], 'The content should be SVG markup');
        $this->assertStringEndsWith(
            '.svg',
            strtolower((string)($args['fileName'] ?? '')),
            'The file name must carry the .svg extension, otherwise the content/extension check rejects it. '
            . $this->getFailureContext($response)
        );

        $fileUid = $this->runUntilFileUploaded($response);
        $this->assertNotNull($fileUid, 'The SVG was never stored as a sys_file. ' . $this->toolErrorSummary() . $this->getFailureContext($this->lastResponse));
        $this->assertEquals('svg', $this->fetchFile($fileUid)['extension']);

        $contentUid = $this->runUntilContentReferencesFile($fileUid);
        $this->assertNotNull(
            $contentUid,
            'The generated chart was never referenced from a content element. '
            . 'History: ' . implode(' → ', $this->getToolCallHistory()) . $this->toolErrorSummary()
            . "\n" . $this->getFailureContext($this->lastResponse)
        );
    }

    // ------------------------------------------------------------------
    // 4. Local file → pre-signed upload URL
    // ------------------------------------------------------------------

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "The photo is on my computer" → UploadFile without url/content, handing the pre-signed upload URL back to the user')]
    public function testLlmOffersPresignedUploadUrlForLocalFile(string $modelKey): void
    {
        $this->setModel($modelKey);
        $prompt = 'I have a photo of our team on my computer at /Users/anna/Desktop/team-2026.jpg '
            . 'and I want it in the TYPO3 file storage. How do we do that?';

        $response = $this->executeUntilToolFound($this->callLlm($prompt), 'UploadFile', 6);

        $uploadCalls = $response->getToolCallsByName('UploadFile');
        $this->assertNotEmpty(
            $uploadCalls,
            'Expected UploadFile to request an upload URL for the local file. '
            . 'History: ' . implode(' → ', $this->getToolCallHistory()) . "\n" . $this->getFailureContext($response)
        );

        $args = $uploadCalls[0]['arguments'];
        $this->assertEmpty(
            $args['url'] ?? '',
            'A local path is not a downloadable URL - "url" must stay empty so a pre-signed upload URL is issued. '
            . 'Arguments: ' . json_encode($args) . "\n" . $this->getFailureContext($response)
        );
        $this->assertEmpty(
            $args['content'] ?? '',
            'The model cannot know the binary content of a local file; "content" must stay empty. '
            . 'Arguments: ' . json_encode($args) . "\n" . $this->getFailureContext($response)
        );

        // Execute every call of this round so the conversation stays consistent
        // (some models pair UploadFile with an exploratory call).
        $results = [];
        $uploadResult = null;
        foreach ($response->getToolCalls() as $call) {
            $callResult = $this->executeToolCall($call);
            $results[] = $callResult;
            if ($call['name'] === 'UploadFile' && $uploadResult === null) {
                $uploadResult = $callResult;
            }
        }

        $this->assertFalse($uploadResult['isError'] ?? false, 'Requesting the upload URL failed: ' . $uploadResult['content']);

        $data = json_decode($uploadResult['content'], true);
        $this->assertNotEmpty($data['uploadUrl'] ?? '', 'Expected a pre-signed uploadUrl in the result');
        $this->assertNotEmpty($data['uploadToken'] ?? '', 'Expected a single-use uploadToken in the result');

        // The instructions must reach the user: the client harness has to run
        // the upload, so the model needs to pass the URL/token on.
        $final = $this->continueWithToolResult($response, $results);
        $text = $final->getContent();
        $this->assertTrue(
            str_contains($text, $data['uploadToken']) || str_contains($text, 'mcp_upload') || str_contains(strtolower($text), 'curl'),
            'The model should hand the upload URL/token or the curl command to the user, otherwise the flow dead-ends. '
            . 'Answer was: ' . $text . "\n" . $this->getFailureContext($final)
        );
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * Record every tool error so failures can show what the model struggled
     * with. Set MCP_LLM_DEBUG=1 to also see them on passing runs.
     */
    protected function executeToolCall(array $toolCall): array
    {
        $result = parent::executeToolCall($toolCall);
        if ($result['isError'] ?? false) {
            $message = ($toolCall['name'] ?? '?') . ': ' . substr(trim((string)$result['content']), 0, 300);
            $this->observedToolErrors[] = $message;
            if (getenv('MCP_LLM_DEBUG')) {
                fwrite(STDERR, "\n[tool error] " . $message . "\n");
            }
        }
        return $result;
    }

    /**
     * Tool errors collected so far, for appending to assertion messages.
     */
    protected function toolErrorSummary(): string
    {
        if ($this->observedToolErrors === []) {
            return '';
        }
        return "\nTool errors along the way:\n - " . implode("\n - ", $this->observedToolErrors);
    }

    /**
     * Keep executing tool calls until a sys_file row exists, and return its uid.
     * Models may explore (ListTables, GetTableSchema) before uploading.
     */
    protected function runUntilFileUploaded(LlmResponse $response, int $maxRounds = 6): ?int
    {
        $current = $response;
        for ($round = 0; $round < $maxRounds; $round++) {
            $uid = $this->findUploadedFile();
            if ($uid !== null) {
                $this->lastResponse = $current;
                return $uid;
            }
            if (!$current->hasToolCalls()) {
                break;
            }
            $current = $this->executeAndContinue($current);
        }
        $this->lastResponse = $current;
        return $this->findUploadedFile();
    }

    /**
     * Keep executing tool calls until some tt_content record references the
     * given file, and return that content uid.
     */
    protected function runUntilContentReferencesFile(int $fileUid, int $maxRounds = 8): ?int
    {
        $current = $this->lastResponse;
        for ($round = 0; $round < $maxRounds; $round++) {
            $contentUid = $this->findContentReferencingFile($fileUid);
            if ($contentUid !== null) {
                return $contentUid;
            }
            if ($current === null || !$current->hasToolCalls()) {
                break;
            }
            $current = $this->executeAndContinue($current);
            $this->lastResponse = $current;
        }
        return $this->findContentReferencingFile($fileUid);
    }

    /**
     * The newest sys_file that is not part of the seeded fixtures.
     */
    protected function findUploadedFile(): ?int
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_file');
        $qb->getRestrictions()->removeAll();
        $uid = $qb->select('uid')
            ->from('sys_file')
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $uid ? (int)$uid : null;
    }

    protected function fetchFile(int $uid): array
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_file');
        $qb->getRestrictions()->removeAll();
        return $qb->select('*')
            ->from('sys_file')
            ->where($qb->expr()->eq('uid', $uid))
            ->executeQuery()
            ->fetchAssociative() ?: [];
    }

    protected function fetchContent(int $uid): array
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $qb->getRestrictions()->removeAll();
        return $qb->select('*')
            ->from('tt_content')
            ->where($qb->expr()->eq('uid', $uid))
            ->executeQuery()
            ->fetchAssociative() ?: [];
    }

    /**
     * Find a tt_content record (live or workspace version) that references the
     * given file through any sys_file_reference field.
     */
    protected function findContentReferencingFile(int $fileUid): ?int
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_file_reference');
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('uid_foreign')
            ->from('sys_file_reference')
            ->where(
                $qb->expr()->eq('uid_local', $qb->createNamedParameter($fileUid, \Doctrine\DBAL\ParameterType::INTEGER)),
                $qb->expr()->eq('tablenames', $qb->createNamedParameter('tt_content')),
                $qb->expr()->eq('deleted', 0)
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $row ? (int)$row : null;
    }
}
