<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Middleware\McpServerMiddleware;
use Hn\McpServer\Tests\Functional\Traits\RestrictedEditorTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The complete OAuth flow a remote MCP client such as a hosted chat app or a
 * CLI with a loopback redirect goes through, dispatched through the
 * extension's middleware with real URLs:
 *
 * 401 + discovery -> dynamic client registration (JSON) -> authorize with
 * PKCE S256 (consent by a logged-in, non-admin backend user) -> token
 * exchange with an application/x-www-form-urlencoded body -> /mcp call that
 * runs with the permissions of that backend user.
 */
class OAuthConnectorFlowTest extends FunctionalTestCase
{
    use RestrictedEditorTrait;

    private const BASE_URL = 'https://example.com';
    private const HOSTED_CALLBACK = 'https://claude.ai/api/mcp/auth_callback';
    private const LOOPBACK_CALLBACK = 'http://localhost/callback';

    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    private mixed $previousRequest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importRestrictedEditorFixture();
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->previousRequest;
        parent::tearDown();
    }

    public function testUnauthenticatedMcpRequestPointsToResourceMetadata(): void
    {
        $response = $this->dispatchJson('POST', '/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertMatchesRegularExpression(
            '#^Bearer .*resource_metadata="https://example\.com/\.well-known/oauth-protected-resource[^"]*"#',
            $response->getHeaderLine('WWW-Authenticate')
        );
    }

    public function testDiscoveryMetadata(): void
    {
        $resource = $this->decodeJson($this->dispatch('GET', '/.well-known/oauth-protected-resource/mcp'), 200);
        $this->assertSame(self::BASE_URL . '/mcp', $resource['resource']);
        $this->assertSame(self::BASE_URL, $resource['authorization_servers'][0]);

        $server = $this->decodeJson($this->dispatch('GET', '/.well-known/oauth-authorization-server'), 200);
        $this->assertSame(self::BASE_URL, $server['issuer']);
        $this->assertSame(['S256'], $server['code_challenge_methods_supported']);
        $this->assertContains('none', $server['token_endpoint_auth_methods_supported']);
        $this->assertContains('authorization_code', $server['grant_types_supported']);
        $this->assertContains('code', $server['response_types_supported']);
        $this->assertSame(self::BASE_URL . '/mcp_oauth/register', $server['registration_endpoint']);
        $this->assertSame(self::BASE_URL . '/mcp_oauth/authorize', $server['authorization_endpoint']);
        $this->assertSame(self::BASE_URL . '/mcp_oauth/token', $server['token_endpoint']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function redirectUriProvider(): array
    {
        return [
            'hosted callback' => [self::HOSTED_CALLBACK, self::HOSTED_CALLBACK],
            'loopback callback on an ephemeral port' => [self::LOOPBACK_CALLBACK, 'http://localhost:3118/callback'],
        ];
    }

    #[DataProvider('redirectUriProvider')]
    public function testFullAuthorizationCodeFlowWithPkce(string $registeredRedirectUri, string $usedRedirectUri): void
    {
        $client = $this->registerClient([$registeredRedirectUri]);
        [$verifier, $challenge] = $this->createPkcePair();

        $code = $this->authorize(self::RESTRICTED_EDITOR_UID, $client['client_id'], $usedRedirectUri, $challenge, 'state-123');

        $tokenResponse = $this->dispatchForm('/mcp_oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $usedRedirectUri,
            'client_id' => $client['client_id'],
            'code_verifier' => $verifier,
        ]);
        $token = $this->decodeJson($tokenResponse, 200);
        $this->assertStringStartsWith('application/json', $tokenResponse->getHeaderLine('Content-Type'));
        $this->assertSame('Bearer', $token['token_type']);
        $this->assertNotEmpty($token['access_token']);
        $this->assertGreaterThan(0, $token['expires_in']);

        // The token acts as the backend user who gave consent: the tool runs in
        // that user's workspace. (Read isolation by DB mount is covered by
        // RestrictedEditorReadIsolationTest.)
        $this->unsetBackendUserContext();
        $response = $this->dispatchJson('POST', '/mcp', [
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/call',
            'params' => [
                'name' => 'GetPageTree',
                'arguments' => ['startPage' => 0, 'depth' => 3],
                '_meta' => self::modernMeta(),
            ],
        ], $token['access_token'], ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'GetPageTree']);
        $body = $this->decodeJson($response, 200);
        $this->assertSame(7, $body['id'] ?? null, json_encode($body));
        $this->assertFalse($body['result']['isError'] ?? true, json_encode($body));
        $tree = json_encode($body['result']['content'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Editorial workspace', $tree);
        $this->assertStringContainsString('Restricted Page', $tree);
    }

    public function testTokenEndpointRejectsWrongCodeVerifier(): void
    {
        $client = $this->registerClient([self::HOSTED_CALLBACK]);
        [, $challenge] = $this->createPkcePair();
        $code = $this->authorize(self::RESTRICTED_EDITOR_UID, $client['client_id'], self::HOSTED_CALLBACK, $challenge, 's');

        $response = $this->dispatchForm('/mcp_oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::HOSTED_CALLBACK,
            'client_id' => $client['client_id'],
            'code_verifier' => str_repeat('x', 64),
        ]);

        $this->assertSame('invalid_grant', $this->decodeJson($response, 400)['error']);
    }

    public function testAuthorizationCodeCanOnlyBeUsedOnce(): void
    {
        $client = $this->registerClient([self::HOSTED_CALLBACK]);
        [$verifier, $challenge] = $this->createPkcePair();
        $code = $this->authorize(self::RESTRICTED_EDITOR_UID, $client['client_id'], self::HOSTED_CALLBACK, $challenge, 's');
        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::HOSTED_CALLBACK,
            'client_id' => $client['client_id'],
            'code_verifier' => $verifier,
        ];

        $this->decodeJson($this->dispatchForm('/mcp_oauth/token', $form), 200);
        $this->assertSame('invalid_grant', $this->decodeJson($this->dispatchForm('/mcp_oauth/token', $form), 400)['error']);
    }

    public function testAuthorizeRejectsUnregisteredRedirectUri(): void
    {
        $client = $this->registerClient([self::HOSTED_CALLBACK]);
        [, $challenge] = $this->createPkcePair();
        $this->actAsBackendUser(self::RESTRICTED_EDITOR_UID);

        $response = $this->dispatch('GET', '/mcp_oauth/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => 'https://attacker.example/callback',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => 's',
        ]));

        $this->assertNotSame(302, $response->getStatusCode(), 'Must not redirect to an unregistered URI');
        $this->assertStringNotContainsString('attacker.example', $response->getHeaderLine('Location'));
    }

    public function testAuthorizeRequiresPkceForPublicClients(): void
    {
        $client = $this->registerClient([self::HOSTED_CALLBACK]);
        $this->actAsBackendUser(self::RESTRICTED_EDITOR_UID);
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => self::HOSTED_CALLBACK,
            'state' => 's',
        ]);

        $consent = $this->dispatch('GET', '/mcp_oauth/authorize?' . $query);
        $csrf = $this->extractCsrfToken((string)$consent->getBody());
        $response = $csrf === null ? $consent : $this->dispatchForm('/mcp_oauth/authorize?' . $query, [
            'approve' => '1',
            'csrf_token' => $csrf,
        ]);

        $this->assertNotSame(302, $response->getStatusCode(), 'No code may be issued without a PKCE challenge');
    }

    public function testRegistrationAcceptsBothCallbacksInOneClient(): void
    {
        $client = $this->registerClient([self::HOSTED_CALLBACK, self::LOOPBACK_CALLBACK]);

        $this->assertSame([self::HOSTED_CALLBACK, self::LOOPBACK_CALLBACK], $client['redirect_uris']);
        $this->assertSame('none', $client['token_endpoint_auth_method'] ?? 'none');
        $this->assertArrayNotHasKey('client_secret', $client, 'A public client must not receive a secret');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private static function modernMeta(): array
    {
        return [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/client' => ['name' => 'oauth-flow-test', 'version' => '1.0'],
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ];
    }

    /**
     * RFC 7591 registration as a public client, the way remote MCP clients do it.
     */
    private function registerClient(array $redirectUris): array
    {
        $response = $this->dispatchJson('POST', '/mcp_oauth/register', [
            'client_name' => 'Remote MCP client',
            'redirect_uris' => $redirectUris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ]);
        $client = $this->decodeJson($response, 201);
        $this->assertNotEmpty($client['client_id']);
        return $client;
    }

    /**
     * @return array{string, string} verifier and S256 challenge
     */
    private function createPkcePair(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return [$verifier, $challenge];
    }

    /**
     * Consent screen (GET) and approval (POST with the CSRF token from the
     * form) by a logged-in backend user. Returns the code from the redirect.
     */
    private function authorize(int $backendUserUid, string $clientId, string $redirectUri, string $challenge, string $state): string
    {
        $this->actAsBackendUser($backendUserUid);
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => $state,
            'scope' => 'mcp_access',
        ]);

        $consent = $this->dispatch('GET', '/mcp_oauth/authorize?' . $query);
        $html = (string)$consent->getBody();
        $this->assertSame(200, $consent->getStatusCode(), $html);
        $csrf = $this->extractCsrfToken($html);
        $this->assertNotNull($csrf, 'Consent form must carry a CSRF token');

        $approval = $this->dispatchForm('/mcp_oauth/authorize?' . $query, [
            'approve' => '1',
            'csrf_token' => $csrf,
            'state' => $state,
        ]);
        $this->assertSame(302, $approval->getStatusCode(), (string)$approval->getBody());
        $location = $approval->getHeaderLine('Location');
        $this->assertStringStartsWith($redirectUri . '?', $location);
        parse_str((string)parse_url($location, PHP_URL_QUERY), $params);
        $this->assertSame($state, $params['state'] ?? null);
        $this->assertNotEmpty($params['code'] ?? null);

        $this->unsetBackendUserContext();
        return (string)$params['code'];
    }

    private function extractCsrfToken(string $html): ?string
    {
        return preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m)
            || preg_match('/value="([^"]+)"\s+name="csrf_token"/', $html, $m)
            ? $m[1]
            : null;
    }

    /**
     * Token and MCP requests carry no backend session; the bearer token alone
     * must establish the user.
     */
    private function unsetBackendUserContext(): void
    {
        unset($GLOBALS['BE_USER']);
    }

    private function dispatchJson(string $method, string $path, array $payload, ?string $bearer = null, array $headers = []): ResponseInterface
    {
        $headers['Content-Type'] = 'application/json';
        $headers['Accept'] = 'application/json, text/event-stream';
        if ($bearer !== null) {
            $headers['Authorization'] = 'Bearer ' . $bearer;
        }
        return $this->dispatch($method, $path, json_encode($payload), $headers);
    }

    /**
     * application/x-www-form-urlencoded POST. The parsed body is what PHP puts
     * into $_POST for this content type and what TYPO3's ServerRequestFactory
     * hands on as parsed body.
     */
    private function dispatchForm(string $path, array $fields): ResponseInterface
    {
        $body = http_build_query($fields);
        parse_str($body, $parsed);
        return $this->dispatch('POST', $path, $body, ['Content-Type' => 'application/x-www-form-urlencoded'], $parsed);
    }

    private function dispatch(string $method, string $path, string $body = '', array $headers = [], ?array $parsedBody = null): ResponseInterface
    {
        $uri = new Uri(self::BASE_URL . $path);
        $serverParams = [
            'HTTPS' => 'on',
            'HTTP_HOST' => $uri->getHost(),
            'SERVER_NAME' => $uri->getHost(),
            'SERVER_PORT' => 443,
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : ''),
            'QUERY_STRING' => $uri->getQuery(),
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => Environment::getPublicPath() . '/index.php',
            'REMOTE_ADDR' => '127.0.0.1',
        ];
        $stream = new Stream('php://temp', 'rw');
        $stream->write($body);
        $stream->rewind();

        parse_str($uri->getQuery(), $queryParams);
        $request = (new ServerRequest($uri, $method, $stream, $headers, $serverParams))
            ->withQueryParams($queryParams);
        if ($parsedBody !== null) {
            $request = $request->withParsedBody($parsedBody);
        }
        // Explicit script path: under the testing framework the current script is
        // phpunit, which would make NormalizedParams compute a wrong site path.
        $request = $request->withAttribute('normalizedParams', new NormalizedParams(
            $serverParams,
            $GLOBALS['TYPO3_CONF_VARS']['SYS'],
            Environment::getPublicPath() . '/index.php',
            Environment::getPublicPath()
        ));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $fallback = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('php://temp', 404);
            }
        };

        return (new McpServerMiddleware(GeneralUtility::makeInstance(Context::class)))->process($request, $fallback);
    }

    private function decodeJson(ResponseInterface $response, int $expectedStatus): array
    {
        $raw = (string)$response->getBody();
        $this->assertSame($expectedStatus, $response->getStatusCode(), $raw);
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, $raw);
        return $decoded;
    }
}
