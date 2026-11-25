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
        // Every test registers clients from the same address; the registration
        // rate limit is counted across tests in the cache
        GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class)->getCache('ratelimiter')->flush();
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

    public function testClientWithRefreshGrantRenewsItsAccessSilently(): void
    {
        $client = $this->registerClient([self::HOSTED_CALLBACK]);
        $token = $this->obtainToken($client);

        $this->assertNotEmpty($token['refresh_token'] ?? null, 'A client that registered the refresh_token grant gets a refresh token');
        $this->assertSame(3600, $token['expires_in']);

        $refreshed = $this->decodeJson($this->dispatchForm('/mcp_oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $token['refresh_token'],
            'client_id' => $client['client_id'],
        ]), 200);

        $this->assertNotSame($token['access_token'], $refreshed['access_token']);
        $this->assertNotSame($token['refresh_token'], $refreshed['refresh_token'], 'The refresh token rotates');
        $this->assertSame(3600, $refreshed['expires_in']);

        // The new access token works, the replaced one no longer does
        $this->assertSame(200, $this->callTool($refreshed['access_token'])->getStatusCode());
        $old = $this->callTool($token['access_token']);
        $this->assertSame(401, $old->getStatusCode());
        $this->assertStringContainsString('error="invalid_token"', $old->getHeaderLine('WWW-Authenticate'));
    }

    public function testClientWithoutRefreshGrantGetsATokenForAWorkingDay(): void
    {
        $client = $this->registerClient([self::HOSTED_CALLBACK], ['authorization_code']);
        $token = $this->obtainToken($client);

        $this->assertArrayNotHasKey('refresh_token', $token);
        $this->assertSame(12 * 3600, $token['expires_in']);
    }

    public function testLifetimesFollowTheExtensionConfiguration(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['oauthAccessTokenMinutes'] = '5';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['oauthTokenHoursWithoutRefresh'] = '8h';

        $withRefresh = $this->obtainToken($this->registerClient([self::HOSTED_CALLBACK]));
        $withoutRefresh = $this->obtainToken($this->registerClient([self::HOSTED_CALLBACK], ['authorization_code']));

        $this->assertSame(300, $withRefresh['expires_in']);
        $this->assertSame(12 * 3600, $withoutRefresh['expires_in'], 'An invalid value falls back to the default');
        $this->assertSame(
            ['oauthTokenHoursWithoutRefresh'],
            GeneralUtility::makeInstance(\Hn\McpServer\Service\OAuthService::class)->getInvalidLifetimeSettings(),
            'The invalid value is reported for the backend module'
        );
    }

    public function testRefreshTokenIsBoundToItsClient(): void
    {
        $token = $this->obtainToken($this->registerClient([self::HOSTED_CALLBACK]));
        $otherClient = $this->registerClient([self::HOSTED_CALLBACK]);

        $response = $this->dispatchForm('/mcp_oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $token['refresh_token'],
            'client_id' => $otherClient['client_id'],
        ]);

        $this->assertSame('invalid_grant', $this->decodeJson($response, 400)['error']);
    }

    public function testReplacedRefreshTokenIsRefusedWithinGracePeriodAndRevokesTheGrantLater(): void
    {
        $client = $this->registerClient([self::HOSTED_CALLBACK]);
        $token = $this->obtainToken($client);
        $refresh = fn(string $refreshToken) => $this->dispatchForm('/mcp_oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $client['client_id'],
        ]);

        $current = $this->decodeJson($refresh($token['refresh_token']), 200);

        // A retry right after the rotation (lost response, parallel refresh) is only refused
        $this->assertSame('invalid_grant', $this->decodeJson($refresh($token['refresh_token']), 400)['error']);
        $this->assertSame(200, $this->callTool($current['access_token'])->getStatusCode());

        // Later the old token can only be in someone else's hands: the grant ends
        $this->getConnectionPool()->getConnectionForTable('tx_mcpserver_refresh_token_history')
            ->update('tx_mcpserver_refresh_token_history', ['replaced' => time() - 120], ['grant_uid' => $this->grantUid()]);
        $this->assertSame('invalid_grant', $this->decodeJson($refresh($token['refresh_token']), 400)['error']);
        $this->assertSame(401, $this->callTool($current['access_token'])->getStatusCode());
        $this->assertSame('invalid_grant', $this->decodeJson($refresh($current['refresh_token']), 400)['error']);
    }

    /**
     * Whoever holds a stolen refresh token may refresh twice before the
     * legitimate client does: its token, two rotations old, must still be
     * recognised as replaced and end the grant.
     */
    public function testRefreshTokenReplacedSeveralRotationsAgoRevokesTheGrant(): void
    {
        $client = $this->registerClient([self::HOSTED_CALLBACK]);
        $token = $this->obtainToken($client);
        $refresh = fn(string $refreshToken) => $this->dispatchForm('/mcp_oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $client['client_id'],
        ]);

        $second = $this->decodeJson($refresh($token['refresh_token']), 200);
        $third = $this->decodeJson($refresh($second['refresh_token']), 200);
        $this->getConnectionPool()->getConnectionForTable('tx_mcpserver_refresh_token_history')
            ->update('tx_mcpserver_refresh_token_history', ['replaced' => time() - 120], ['grant_uid' => $this->grantUid()]);

        $this->assertSame('invalid_grant', $this->decodeJson($refresh($token['refresh_token']), 400)['error']);
        $this->assertSame(401, $this->callTool($third['access_token'])->getStatusCode());
        $this->assertSame('invalid_grant', $this->decodeJson($refresh($third['refresh_token']), 400)['error']);
    }

    /**
     * Lowering the refresh lifetime must not make the cleanup forget replaced
     * tokens that would still have been valid under the lifetime they got.
     */
    public function testCleanupKeepsReplacedRefreshTokensAfterTheLifetimeWasLowered(): void
    {
        $client = $this->registerClient([self::HOSTED_CALLBACK]);
        $token = $this->obtainToken($client);
        $refresh = fn(string $refreshToken) => $this->dispatchForm('/mcp_oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $client['client_id'],
        ]);
        $current = $this->decodeJson($refresh($token['refresh_token']), 200);

        // Rotated two days ago, then the lifetime is lowered to one day
        $this->getConnectionPool()->getConnectionForTable('tx_mcpserver_refresh_token_history')
            ->update('tx_mcpserver_refresh_token_history', ['replaced' => time() - 2 * 86400], ['grant_uid' => $this->grantUid()]);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['oauthRefreshTokenDays'] = '1';
        GeneralUtility::makeInstance(\Hn\McpServer\Service\OAuthService::class)->cleanupExpired();

        $this->assertSame('invalid_grant', $this->decodeJson($refresh($token['refresh_token']), 400)['error']);
        $this->assertSame('invalid_grant', $this->decodeJson($refresh($current['refresh_token']), 400)['error'], 'The replay still ends the grant');
    }

    /**
     * A client whose access token expired but whose refresh token is still
     * valid is in use: the cleanup must not remove it.
     */
    public function testCleanupKeepsClientWithLiveRefreshToken(): void
    {
        $client = $this->registerClient([self::HOSTED_CALLBACK]);
        $token = $this->obtainToken($client);
        $longAgo = time() - 400 * 86400;
        $this->getConnectionPool()->getConnectionForTable('tx_mcpserver_oauth_clients')
            ->update('tx_mcpserver_oauth_clients', ['crdate' => $longAgo, 'last_used' => $longAgo], ['client_id' => $client['client_id']]);
        $this->getConnectionPool()->getConnectionForTable('tx_mcpserver_access_tokens')
            ->update('tx_mcpserver_access_tokens', ['expires' => time() - 1], ['uid' => $this->grantUid()]);

        GeneralUtility::makeInstance(\Hn\McpServer\Service\OAuthService::class)->cleanupExpired();

        $this->decodeJson($this->dispatchForm('/mcp_oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $token['refresh_token'],
            'client_id' => $client['client_id'],
        ]), 200);
    }

    /**
     * With a maximum session length the user has to authorize again after it
     * (e.g. to pass the SSO login), however actively the client refreshes.
     */
    public function testRefreshIsRefusedAfterTheMaximumSessionLength(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['oauthMaxSessionHours'] = '1';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['oauthAccessTokenMinutes'] = '90';
        $client = $this->registerClient([self::HOSTED_CALLBACK]);
        $token = $this->obtainToken($client);
        $this->assertSame(3600, $token['expires_in'], 'No token outlives the session');

        $refresh = fn(string $refreshToken) => $this->dispatchForm('/mcp_oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $client['client_id'],
        ]);
        $this->getConnectionPool()->getConnectionForTable('tx_mcpserver_access_tokens')
            ->update('tx_mcpserver_access_tokens', ['crdate' => time() - 3000], ['uid' => $this->grantUid()]);
        $refreshed = $this->decodeJson($refresh($token['refresh_token']), 200);
        $this->assertLessThanOrEqual(600, $refreshed['expires_in'], 'A refresh near the end only runs up to it');

        $this->getConnectionPool()->getConnectionForTable('tx_mcpserver_access_tokens')
            ->update('tx_mcpserver_access_tokens', ['crdate' => time() - 3601], ['uid' => $this->grantUid()]);
        $this->assertSame('invalid_grant', $this->decodeJson($refresh($refreshed['refresh_token']), 400)['error']);
    }

    public function testRefreshIsRefusedForADisabledBackendUser(): void
    {
        $client = $this->registerClient([self::HOSTED_CALLBACK]);
        $token = $this->obtainToken($client);

        $this->getConnectionPool()->getConnectionForTable('be_users')
            ->update('be_users', ['disable' => 1], ['uid' => self::RESTRICTED_EDITOR_UID]);

        $response = $this->dispatchForm('/mcp_oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $token['refresh_token'],
            'client_id' => $client['client_id'],
        ]);
        $this->assertSame('invalid_grant', $this->decodeJson($response, 400)['error']);
    }

    public function testExpiredRefreshTokenIsRefused(): void
    {
        $client = $this->registerClient([self::HOSTED_CALLBACK]);
        $token = $this->obtainToken($client);

        $this->getConnectionPool()->getConnectionForTable('tx_mcpserver_access_tokens')
            ->update('tx_mcpserver_access_tokens', ['refresh_expires' => time() - 1], ['deleted' => 0]);

        $response = $this->dispatchForm('/mcp_oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $token['refresh_token'],
            'client_id' => $client['client_id'],
        ]);
        $this->assertSame('invalid_grant', $this->decodeJson($response, 400)['error']);
    }

    public function testTokenResponsesAreNotCached(): void
    {
        $client = $this->registerClient([self::HOSTED_CALLBACK]);
        [$verifier, $challenge] = $this->createPkcePair();
        $code = $this->authorize(self::RESTRICTED_EDITOR_UID, $client['client_id'], self::HOSTED_CALLBACK, $challenge, 's');
        $response = $this->dispatchForm('/mcp_oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::HOSTED_CALLBACK,
            'client_id' => $client['client_id'],
            'code_verifier' => $verifier,
        ]);

        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    /**
     * The one grant the test created.
     */
    private function grantUid(): int
    {
        return (int)$this->getConnectionPool()->getConnectionForTable('tx_mcpserver_access_tokens')
            ->select(['uid'], 'tx_mcpserver_access_tokens', ['deleted' => 0])
            ->fetchOne();
    }

    /**
     * Authorization code flow up to the token response.
     */
    private function obtainToken(array $client): array
    {
        [$verifier, $challenge] = $this->createPkcePair();
        $code = $this->authorize(self::RESTRICTED_EDITOR_UID, $client['client_id'], self::HOSTED_CALLBACK, $challenge, 's');
        return $this->decodeJson($this->dispatchForm('/mcp_oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::HOSTED_CALLBACK,
            'client_id' => $client['client_id'],
            'code_verifier' => $verifier,
        ]), 200);
    }

    private function callTool(string $accessToken): ResponseInterface
    {
        $this->unsetBackendUserContext();
        return $this->dispatchJson('POST', '/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'GetPageTree', 'arguments' => ['startPage' => 0, 'depth' => 1], '_meta' => self::modernMeta()],
        ], $accessToken, ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'GetPageTree']);
    }

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
    private function registerClient(array $redirectUris, array $grantTypes = ['authorization_code', 'refresh_token']): array
    {
        $response = $this->dispatchJson('POST', '/mcp_oauth/register', [
            'client_name' => 'Remote MCP client',
            'redirect_uris' => $redirectUris,
            'grant_types' => $grantTypes,
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
