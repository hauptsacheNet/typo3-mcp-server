<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\McpEndpoint;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Covers the sessionTimeout extension setting.
 *
 * Sessions only exist for clients on the pre-2026-07-28 lifecycle. For those
 * the timeout decides when a connection that is still in use dies: the server
 * answers the next request with 404 (spec 5.8.4) and the client has to run a
 * fresh initialize - if it handles that at all. The tests age the stored
 * session instead of waiting, which is what an editor pausing their work
 * does to it.
 */
class McpEndpointSessionTimeoutTest extends AbstractFunctionalTest
{
    private mixed $previousRequest;
    private mixed $previousExtensionConfiguration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $this->previousExtensionConfiguration = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] ?? null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->previousRequest;
        if ($this->previousExtensionConfiguration === null) {
            unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']);
        } else {
            $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = $this->previousExtensionConfiguration;
        }
        parent::tearDown();
    }

    public function testSessionOutlivesAnIdleHourWithTheDefaultTimeout(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['sessionTimeout']);

        $token = $this->createAccessToken();
        $sessionId = $this->initializeSession($token);

        // An hour of thinking, reading or meetings: expired under the former
        // hard-coded 1800 seconds, still well inside the 14400 second default.
        $this->ageSession($sessionId, 3600);

        $response = $this->dispatch(
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
            $token,
            ['Mcp-Session-Id' => $sessionId]
        );

        $this->assertToolsListSucceeded($response);
    }

    public function testSessionExpiresAfterTheConfiguredTimeout(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['sessionTimeout'] = '60';

        $token = $this->createAccessToken();
        $sessionId = $this->initializeSession($token);

        $this->ageSession($sessionId, 120);

        $response = $this->dispatch(
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
            $token,
            ['Mcp-Session-Id' => $sessionId]
        );

        $this->assertSame(404, $response->getStatusCode(), (string)$response->getBody());
    }

    public function testNonPositiveTimeoutFallsBackToTheDefault(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['sessionTimeout'] = '0';

        $token = $this->createAccessToken();
        $sessionId = $this->initializeSession($token);

        $this->ageSession($sessionId, 3600);

        $response = $this->dispatch(
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
            $token,
            ['Mcp-Session-Id' => $sessionId]
        );

        $this->assertToolsListSucceeded($response);
    }

    /**
     * A session that is still alive answers the call - and answers it
     * properly: a JSON-RPC error would come back as 200 as well.
     */
    private function assertToolsListSucceeded(ResponseInterface $response): void
    {
        $raw = (string)$response->getBody();
        $this->assertSame(200, $response->getStatusCode(), $raw);

        $body = json_decode($raw, true);
        $this->assertIsArray($body, $raw);
        $this->assertArrayNotHasKey('error', $body, $raw);
        $this->assertNotEmpty($body['result']['tools'] ?? [], $raw);
    }

    /**
     * Run the classic handshake of a session-based client and return the
     * session id the server handed out.
     */
    private function initializeSession(string $token): string
    {
        $response = $this->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'session-timeout-test', 'version' => '1.0'],
            ],
        ], $token);

        $this->assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $sessionId = $response->getHeaderLine('mcp-session-id');
        $this->assertNotSame('', $sessionId, 'initialize must hand out a session id');

        $this->dispatch(
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            $token,
            ['Mcp-Session-Id' => $sessionId]
        );

        return $sessionId;
    }

    /**
     * Backdate the stored session so it looks idle for the given time.
     */
    private function ageSession(string $sessionId, int $seconds): void
    {
        $path = Environment::getVarPath() . '/mcp_sessions/session-' . $sessionId . '.json';
        $this->assertFileExists($path);

        $data = json_decode((string)file_get_contents($path), true);
        $this->assertIsArray($data, 'Session file must contain a JSON object');
        $data['last_activity'] -= $seconds;
        file_put_contents($path, json_encode($data));
    }

    private function createAccessToken(): string
    {
        $oauthService = GeneralUtility::makeInstance(OAuthService::class);
        return $oauthService->createToken(1, 'session-timeout-test')['access_token'];
    }

    /**
     * Dispatch a JSON-RPC message to the endpoint the way the middleware
     * would: as a PSR-7 request.
     */
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
