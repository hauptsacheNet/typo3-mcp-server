<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\McpEndpoint;
use Hn\McpServer\MCP\McpApps;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * MCP Apps over the real endpoint: the change review view as a ui:// resource, linked
 * from WriteTable, and the app-only ReviewChange tool that exists only for hosts that
 * declared the extension.
 */
class McpAppsEndpointTest extends AbstractFunctionalTest
{
    private const UI_CAPABILITIES = [
        'extensions' => [
            'io.modelcontextprotocol/ui' => ['mimeTypes' => ['text/html;profile=mcp-app']],
        ],
    ];

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

    public function testServerDeclaresTheAppsExtension(): void
    {
        $token = $this->createAccessToken();
        $response = $this->initialize($token, self::UI_CAPABILITIES);

        $body = json_decode((string)$response->getBody(), true);
        $this->assertSame(
            ['mimeTypes' => [McpApps::MIME_TYPE]],
            $body['result']['capabilities']['extensions']['io.modelcontextprotocol/ui'] ?? null,
            (string)$response->getBody()
        );
        $this->assertArrayHasKey('resources', $body['result']['capabilities']);
    }

    public function testWriteTableLinksTheChangeReviewView(): void
    {
        $token = $this->createAccessToken();
        $sessionId = $this->initializeSession($token, self::UI_CAPABILITIES);

        $tools = $this->listTools($token, $sessionId);

        $this->assertSame(McpApps::CHANGE_REVIEW_URI, $tools['WriteTable']['_meta']['ui']['resourceUri'] ?? null);
        $this->assertArrayNotHasKey('visibility', $tools['WriteTable']['_meta']['ui'], 'WriteTable must stay visible to the model');
        $this->assertSame(['app'], $tools['ReviewChange']['_meta']['ui']['visibility'] ?? null);
    }

    public function testAppOnlyToolIsHiddenFromClientsWithoutApps(): void
    {
        $token = $this->createAccessToken();
        $sessionId = $this->initializeSession($token, []);

        $tools = $this->listTools($token, $sessionId);
        $this->assertArrayHasKey('WriteTable', $tools);
        $this->assertArrayNotHasKey('ReviewChange', $tools, 'A host without MCP Apps would hand the tool to the model');

        // Calling it by name does not work either.
        $response = $this->dispatch([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'ReviewChange', 'arguments' => ['action' => 'publish', 'table' => 'tt_content', 'uid' => 100]],
        ], $token, ['Mcp-Session-Id' => $sessionId]);
        $body = json_decode((string)$response->getBody(), true);
        $this->assertTrue(
            isset($body['error']) || ($body['result']['isError'] ?? false) === true,
            (string)$response->getBody()
        );
        $this->assertStringContainsString('Tool not found', (string)$response->getBody());
    }

    public function testChangeReviewViewIsListedAndReadable(): void
    {
        $token = $this->createAccessToken();
        $sessionId = $this->initializeSession($token, self::UI_CAPABILITIES);

        $response = $this->dispatch(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/list'], $token, ['Mcp-Session-Id' => $sessionId]);
        $body = json_decode((string)$response->getBody(), true);
        $uris = array_column($body['result']['resources'] ?? [], 'mimeType', 'uri');
        $this->assertSame(McpApps::MIME_TYPE, $uris[McpApps::CHANGE_REVIEW_URI] ?? null, (string)$response->getBody());

        $response = $this->dispatch([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'resources/read',
            'params' => ['uri' => McpApps::CHANGE_REVIEW_URI],
        ], $token, ['Mcp-Session-Id' => $sessionId]);
        $body = json_decode((string)$response->getBody(), true);
        $contents = $body['result']['contents'][0] ?? [];
        $this->assertSame(McpApps::CHANGE_REVIEW_URI, $contents['uri'] ?? null, (string)$response->getBody());
        $this->assertSame(McpApps::MIME_TYPE, $contents['mimeType'] ?? null);
        $this->assertStringStartsWith('<!DOCTYPE html>', $contents['text'] ?? '');
        $this->assertStringContainsString("'ReviewChange'", $contents['text']);
    }

    public function testUnknownResourceIsAnError(): void
    {
        $token = $this->createAccessToken();
        $sessionId = $this->initializeSession($token, self::UI_CAPABILITIES);

        $response = $this->dispatch([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'resources/read',
            'params' => ['uri' => 'ui://mcp_server/unknown.html'],
        ], $token, ['Mcp-Session-Id' => $sessionId]);

        $body = json_decode((string)$response->getBody(), true);
        $this->assertArrayHasKey('error', $body, (string)$response->getBody());
    }

    public function testReviewChangeWorksForClientsWithApps(): void
    {
        $token = $this->createAccessToken();
        $sessionId = $this->initializeSession($token, self::UI_CAPABILITIES);

        $response = $this->dispatch([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'WriteTable', 'arguments' => ['action' => 'update', 'table' => 'tt_content', 'uid' => 100, 'data' => ['header' => 'Reviewed header']]],
        ], $token, ['Mcp-Session-Id' => $sessionId]);
        $body = json_decode((string)$response->getBody(), true);
        $this->assertFalse($body['result']['isError'] ?? true, (string)$response->getBody());

        $response = $this->dispatch([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'ReviewChange', 'arguments' => ['action' => 'show', 'table' => 'tt_content', 'uid' => 100]],
        ], $token, ['Mcp-Session-Id' => $sessionId]);
        $body = json_decode((string)$response->getBody(), true);
        $this->assertFalse($body['result']['isError'] ?? true, (string)$response->getBody());
        $review = $body['result']['structuredContent'];
        $this->assertTrue($review['pending']);
        $this->assertSame('changed', $review['record']['state']);
        $this->assertContains('Reviewed header', array_column($review['record']['fields'], 'after'));
    }

    /**
     * @return array<string, array<string, mixed>> tools by name
     */
    private function listTools(string $token, string $sessionId): array
    {
        $response = $this->dispatch(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $token, ['Mcp-Session-Id' => $sessionId]);
        $body = json_decode((string)$response->getBody(), true);
        $this->assertNotEmpty($body['result']['tools'] ?? [], (string)$response->getBody());

        return array_column($body['result']['tools'], null, 'name');
    }

    private function initialize(string $token, array $capabilities): ResponseInterface
    {
        $response = $this->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => $capabilities === [] ? new \stdClass() : $capabilities,
                'clientInfo' => ['name' => 'mcp-apps-test', 'version' => '1.0'],
            ],
        ], $token);
        $this->assertSame(200, $response->getStatusCode(), (string)$response->getBody());

        return $response;
    }

    private function initializeSession(string $token, array $capabilities): string
    {
        $response = $this->initialize($token, $capabilities);
        $sessionId = $response->getHeaderLine('mcp-session-id');
        $this->assertNotSame('', $sessionId, 'initialize must hand out a session id');

        $this->dispatch(
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            $token,
            ['Mcp-Session-Id' => $sessionId]
        );

        return $sessionId;
    }

    private function createAccessToken(): string
    {
        return GeneralUtility::makeInstance(OAuthService::class)->createToken(1, 'mcp-apps-test')['access_token'];
    }

    private function dispatch(array $jsonRpc, string $token, array $extraHeaders = []): ResponseInterface
    {
        $body = new Stream('php://temp', 'rw');
        $body->write(json_encode($jsonRpc));
        $body->rewind();

        $request = new ServerRequest(
            new Uri('https://example.com/mcp'),
            'POST',
            $body,
            array_merge([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ], $extraHeaders)
        );
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return (new McpEndpoint())($request);
    }
}
