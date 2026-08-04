<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\McpEndpoint;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Authorization and protocol-era tests for the /mcp HTTP endpoint.
 *
 * The endpoint authenticates every request with a static (pre-authorized)
 * bearer token. Since the 2026-07-28 MCP spec revision ("stateless core"),
 * a client can execute a tool call with a single HTTP request — no
 * initialize handshake, no session. Legacy clients (2024-11-05 … 2025-11-25)
 * still get the classic handshake with an Mcp-Session-Id. Both eras must
 * work against the same endpoint, gated by the same token check.
 */
class McpEndpointAuthTest extends AbstractFunctionalTest
{
    private static function modernMeta(): array
    {
        return [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/client' => ['name' => 'stateless-test', 'version' => '1.0'],
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ];
    }

    /**
     * SEP-2243 request-metadata headers of a 2026-07-28 client. The
     * MCP-Protocol-Version header is what routes the request onto the
     * stateless path; Mcp-Method/Mcp-Name mirror the body for gateways.
     */
    private static function modernHeaders(string $method, ?string $name = null): array
    {
        $headers = [
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => $method,
        ];
        if ($name !== null) {
            $headers['Mcp-Name'] = $name;
        }
        return $headers;
    }

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

    public function testStatelessToolCallWithStaticTokenNeedsNoHandshake(): void
    {
        $token = $this->createAccessToken();

        // A single HTTP request: authenticate with the static token and call
        // a tool — no initialize, no notifications/initialized, no session.
        $response = $this->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'GetPageTree',
                'arguments' => new \stdClass(),
                '_meta' => self::modernMeta(),
            ],
        ], $token, self::modernHeaders('tools/call', 'GetPageTree'));

        $body = $this->decodeJsonRpc($response, 1);
        $this->assertArrayHasKey('result', $body, json_encode($body));
        $this->assertFalse($body['result']['isError'] ?? true, json_encode($body));
        $this->assertNotEmpty($body['result']['content'] ?? []);

        // The stateless lifecycle must not leak session machinery.
        $this->assertFalse(
            $response->hasHeader('mcp-session-id'),
            'A 2026-07-28 stateless response must not carry an Mcp-Session-Id'
        );
    }

    public function testStatelessToolsListExposesTools(): void
    {
        $token = $this->createAccessToken();

        $response = $this->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['_meta' => self::modernMeta()],
        ], $token, self::modernHeaders('tools/list'));

        $body = $this->decodeJsonRpc($response, 1);
        $toolNames = array_column($body['result']['tools'] ?? [], 'name');
        $this->assertContains('GetPageTree', $toolNames);
        $this->assertContains('WriteTable', $toolNames);
    }

    public function testStatelessRequestWithoutMetaEnvelopeIsRejected(): void
    {
        $token = $this->createAccessToken();

        // The stateless era is opt-in per request: the _meta envelope carries
        // protocol version, client info and capabilities. A request that
        // announces 2026-07-28 but omits the required clientCapabilities is
        // rejected as invalid params — it does not fall back silently.
        $response = $this->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                    'io.modelcontextprotocol/client' => ['name' => 'incomplete', 'version' => '1.0'],
                ],
            ],
        ], $token, self::modernHeaders('tools/list'));

        $body = json_decode((string)$response->getBody(), true);
        $this->assertSame(-32602, $body['error']['code'] ?? null, json_encode($body));
    }

    public function testLegacyHandshakeAndSessionStillWork(): void
    {
        $token = $this->createAccessToken();

        // 1. Classic initialize handshake of a pre-2026 client.
        $response = $this->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'legacy-test', 'version' => '1.0'],
            ],
        ], $token);

        $body = $this->decodeJsonRpc($response, 1);
        $this->assertSame('2025-06-18', $body['result']['protocolVersion'] ?? null, json_encode($body));
        $this->assertTrue(
            $response->hasHeader('mcp-session-id'),
            'A legacy initialize response must carry an Mcp-Session-Id'
        );
        $sessionId = $response->getHeaderLine('mcp-session-id');

        // 2. Complete the handshake.
        $this->dispatch(
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            $token,
            ['Mcp-Session-Id' => $sessionId]
        );

        // 3. Session-bound request works and sees the same tools.
        $response = $this->dispatch(
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
            $token,
            ['Mcp-Session-Id' => $sessionId]
        );

        $body = $this->decodeJsonRpc($response, 2);
        $toolNames = array_column($body['result']['tools'] ?? [], 'name');
        $this->assertContains('GetPageTree', $toolNames);
    }

    public function testMissingTokenIsRejectedWithOauthMetadata(): void
    {
        $response = $this->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['_meta' => self::modernMeta()],
        ], null);

        $this->assertSame(401, $response->getStatusCode());
        // RFC 9728: the 401 must point OAuth-capable clients to the
        // protected-resource metadata so they can discover the auth server.
        $this->assertStringContainsString(
            'resource_metadata=',
            $response->getHeaderLine('WWW-Authenticate')
        );
    }

    public function testInvalidTokenIsRejected(): void
    {
        $response = $this->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['_meta' => self::modernMeta()],
        ], str_repeat('f', 64));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testExpiredTokenIsRejected(): void
    {
        $token = $this->createAccessToken();

        $this->getConnectionForTable('tx_mcpserver_access_tokens')->update(
            'tx_mcpserver_access_tokens',
            ['expires' => time() - 10],
            ['client_name' => 'endpoint-auth-test']
        );

        $response = $this->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['_meta' => self::modernMeta()],
        ], $token);

        $this->assertSame(401, $response->getStatusCode());
    }

    private function createAccessToken(): string
    {
        $oauthService = GeneralUtility::makeInstance(OAuthService::class);
        $tokenData = $oauthService->createToken(1, 'endpoint-auth-test');
        return $tokenData['access_token'];
    }

    /**
     * Dispatch a JSON-RPC message to the endpoint the way the middleware
     * would: as a PSR-7 request.
     */
    private function dispatch(array $jsonRpc, ?string $token, array $extraHeaders = []): ResponseInterface
    {
        $body = new Stream('php://temp', 'rw');
        $body->write(json_encode($jsonRpc));
        $body->rewind();

        $headers = array_merge([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $extraHeaders);
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $request = new ServerRequest(new Uri('https://example.com/mcp'), 'POST', $body, $headers);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return (new McpEndpoint())($request);
    }

    private function decodeJsonRpc(ResponseInterface $response, int $expectedId): array
    {
        $raw = (string)$response->getBody();
        $this->assertSame(200, $response->getStatusCode(), $raw);

        $body = json_decode($raw, true);
        $this->assertIsArray($body, $raw);
        $this->assertArrayNotHasKey('error', $body, $raw);
        $this->assertSame($expectedId, $body['id'] ?? null, $raw);

        return $body;
    }
}
