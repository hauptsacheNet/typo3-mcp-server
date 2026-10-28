<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\McpEndpoint;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * An unexpected exception must not reach the client with its message: the
 * endpoint is publicly reachable, and messages can carry internals such as
 * SQL, table names or paths. The details belong in the server log only.
 *
 * Kept apart from the auth tests because it breaks the schema on purpose.
 */
class McpEndpointErrorResponseTest extends AbstractFunctionalTest
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

    public function testUnexpectedErrorDoesNotExposeItsMessage(): void
    {
        $token = GeneralUtility::makeInstance(OAuthService::class)
            ->createToken(1, 'error-response-test')['access_token'];

        // The token lookup is the first query of every request: without the
        // table the database layer throws, and its message names the table
        // and the failing SQL.
        $this->getConnectionForTable('tx_mcpserver_access_tokens')
            ->executeStatement('DROP TABLE tx_mcpserver_access_tokens');

        $body = new Stream('php://temp', 'rw');
        $body->write(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/client' => ['name' => 'error-response-test', 'version' => '1.0'],
                'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            ]],
        ]));
        $body->rewind();
        $request = new ServerRequest(new Uri('https://example.com/mcp'), 'POST', $body, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/list',
        ]);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        // The catch-all logs through error_log(); keep that out of the test output.
        $previousErrorLog = ini_set('error_log', '/dev/null');
        try {
            $response = (new McpEndpoint())($request);
        } finally {
            ini_set('error_log', (string)$previousErrorLog);
        }

        $raw = (string)$response->getBody();
        $this->assertSame(500, $response->getStatusCode(), $raw);
        $this->assertStringNotContainsStringIgnoringCase('tx_mcpserver_access_tokens', $raw);
        $this->assertStringNotContainsStringIgnoringCase('select', $raw);
        $this->assertSame(
            [
                'error' => 'Internal Server Error',
                'message' => 'An unexpected error occurred, see the server error log for details.',
            ],
            json_decode($raw, true)
        );
    }
}
