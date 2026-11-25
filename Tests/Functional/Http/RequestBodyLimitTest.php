<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\McpEndpoint;
use Hn\McpServer\Http\OAuthRegisterEndpoint;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The endpoints that decode a JSON body must not buffer more than a fixed
 * number of bytes: decoded JSON takes many times its transfer size, and the
 * registration endpoint is reachable without authentication.
 */
class RequestBodyLimitTest extends AbstractFunctionalTest
{
    private const REGISTER_LIMIT = 131072;
    private const MCP_LIMIT = 4194304;

    private mixed $previousRequest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        // The registration limit is counted per IP address across requests
        GeneralUtility::makeInstance(CacheManager::class)->getCache('ratelimiter')->flush();
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->previousRequest;
        parent::tearDown();
    }

    public function testRegistrationWithinTheLimitStillWorks(): void
    {
        $response = $this->register(json_encode([
            'client_name' => 'Inspector',
            'redirect_uris' => ['http://localhost:6274/oauth/callback'],
        ]));

        $this->assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        $this->assertSame(1, $this->countClients());
    }

    public function testRegistrationRejectsOversizedBodyWithoutContentLength(): void
    {
        // Chunked transfer encoding: no Content-Length, so the limit has to
        // hold while reading.
        $response = $this->register($this->paddedRegistration(self::REGISTER_LIMIT + 1));

        $this->assertSame(413, $response->getStatusCode(), (string)$response->getBody());
        $this->assertSame('invalid_request', json_decode((string)$response->getBody(), true)['error'] ?? null);
        $this->assertSame(0, $this->countClients());
    }

    public function testRegistrationRejectsOversizedContentLengthWithoutReadingTheBody(): void
    {
        $body = $this->createStream('{}');
        $request = new ServerRequest(
            new Uri('https://example.com/mcp_oauth/register'),
            'POST',
            $body,
            ['Content-Type' => 'application/json', 'Content-Length' => (string)(self::REGISTER_LIMIT + 1)]
        );

        $response = (new OAuthRegisterEndpoint())($request);

        $this->assertSame(413, $response->getStatusCode(), (string)$response->getBody());
        $this->assertSame(0, $body->tell(), 'The body must not be read when its declared length is over the limit');
        $this->assertSame(0, $this->countClients());
    }

    public function testRegistrationRejectsOversizedBodyBehindTooSmallContentLength(): void
    {
        $response = $this->register(
            $this->paddedRegistration(self::REGISTER_LIMIT + 1),
            ['Content-Length' => '2']
        );

        $this->assertSame(413, $response->getStatusCode(), (string)$response->getBody());
    }

    public function testRegistrationAcceptsBodyOfExactlyTheLimit(): void
    {
        $response = $this->register($this->paddedRegistration(self::REGISTER_LIMIT));

        $this->assertSame(201, $response->getStatusCode(), (string)$response->getBody());
    }

    public static function unstorableMetadataProvider(): array
    {
        return [
            'client_name too long' => [['client_name' => str_repeat('a', 256)]],
            'client_name not a string' => [['client_name' => ['a']]],
            'scope too long' => [['scope' => str_repeat('a', 256)]],
            'scope not a string' => [['scope' => ['a']]],
            'grant_types too long' => [['grant_types' => [str_repeat('a', 256)]]],
            'grant_types not strings' => [['grant_types' => [['authorization_code']]]],
            'too many redirect_uris' => [['redirect_uris' => array_map(
                static fn(int $i): string => 'https://example.com/cb' . $i,
                range(1, 21)
            )]],
            'redirect_uri too long' => [['redirect_uris' => ['https://example.com/' . str_repeat('a', 2048)]]],
            // Each one within the limit, but the JSON-escaped slashes exceed the text column
            'redirect_uris too long once encoded' => [['redirect_uris' => array_map(
                static fn(int $i): string => 'https://example.com/' . $i . str_repeat('/', 2020),
                range(1, 20)
            )]],
        ];
    }

    #[DataProvider('unstorableMetadataProvider')]
    public function testRegistrationRejectsMetadataTheClientTableCannotHold(array $metadata): void
    {
        $response = $this->register(json_encode($metadata + [
            'redirect_uris' => ['https://example.com/cb'],
        ]));

        $raw = (string)$response->getBody();
        $this->assertSame(400, $response->getStatusCode(), $raw);
        $this->assertSame('invalid_client_metadata', json_decode($raw, true)['error'] ?? null, $raw);
        $this->assertSame(0, $this->countClients());
    }

    public function testRegistrationIsRateLimitedPerIpAddress(): void
    {
        $valid = json_encode(['redirect_uris' => ['https://example.com/cb']]);

        for ($i = 1; $i <= 20; $i++) {
            $response = $this->register($valid, [], '203.0.113.7');
            $this->assertSame(201, $response->getStatusCode(), 'Registration ' . $i . ': ' . $response->getBody());
        }

        $response = $this->register($valid, [], '203.0.113.7');
        $this->assertSame(429, $response->getStatusCode(), (string)$response->getBody());
        $this->assertGreaterThan(0, (int)$response->getHeaderLine('Retry-After'));
        $this->assertSame(20, $this->countClients());

        // Rejected requests count as well, also when their body is invalid or too large
        $response = $this->register($this->paddedRegistration(self::REGISTER_LIMIT + 1), [], '203.0.113.7');
        $this->assertSame(429, $response->getStatusCode());

        // Another address is not affected
        $response = $this->register($valid, [], '203.0.113.8');
        $this->assertSame(201, $response->getStatusCode(), (string)$response->getBody());
    }

    public function testMcpEndpointRejectsOversizedBody(): void
    {
        $token = GeneralUtility::makeInstance(OAuthService::class)
            ->createToken(1, 'body-limit-test')['access_token'];

        $response = $this->mcp(
            '{"jsonrpc":"2.0","id":1,"method":"ping","pad":"' . str_repeat('a', self::MCP_LIMIT) . '"}',
            $token
        );

        $this->assertSame(413, $response->getStatusCode(), mb_substr((string)$response->getBody(), 0, 500));
    }

    public function testMcpEndpointChecksTheTokenBeforeTheBody(): void
    {
        $body = $this->createStream(str_repeat('a', 1024));
        $request = new ServerRequest(
            new Uri('https://example.com/mcp'),
            'POST',
            $body,
            ['Content-Type' => 'application/json', 'Authorization' => 'Bearer invalid']
        );
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = (new McpEndpoint())($request);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(0, $body->tell(), 'An unauthenticated request must not have its body read');
    }

    /**
     * A valid registration of exactly $bytes bytes.
     */
    private function paddedRegistration(int $bytes): string
    {
        $prefix = '{"redirect_uris":["https://example.com/cb"],"pad":"';
        $suffix = '"}';

        return $prefix . str_repeat('a', $bytes - strlen($prefix) - strlen($suffix)) . $suffix;
    }

    private function register(string $body, array $headers = [], string $remoteAddress = '198.51.100.1'): ResponseInterface
    {
        $request = new ServerRequest(
            new Uri('https://example.com/mcp_oauth/register'),
            'POST',
            $this->createStream($body),
            $headers + ['Content-Type' => 'application/json'],
            ['REMOTE_ADDR' => $remoteAddress]
        );

        return (new OAuthRegisterEndpoint())($request);
    }

    private function mcp(string $body, string $token): ResponseInterface
    {
        $request = new ServerRequest(
            new Uri('https://example.com/mcp'),
            'POST',
            $this->createStream($body),
            ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'Authorization' => 'Bearer ' . $token]
        );
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return (new McpEndpoint())($request);
    }

    private function createStream(string $content): Stream
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write($content);
        $stream->rewind();

        return $stream;
    }

    private function countClients(): int
    {
        return (int)GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_oauth_clients')
            ->count('*', 'tx_mcpserver_oauth_clients', []);
    }
}
