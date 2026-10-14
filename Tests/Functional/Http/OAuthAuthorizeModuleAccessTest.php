<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\OAuthAuthorizeEndpoint;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The OAuth authorize endpoint runs through the extension's own PSR-15 middleware
 * and never passes TYPO3's BackendModuleValidator, which gates the backend module
 * routes. These tests pin down that the endpoint still honors access to the
 * user_mcp_server module: a backend user who cannot open the module (and therefore
 * cannot mint a Bearer token via the module UI) must not be able to authorize an
 * MCP client through OAuth either.
 */
class OAuthAuthorizeModuleAccessTest extends AbstractFunctionalTest
{
    private OAuthAuthorizeEndpoint $endpoint;

    protected function setUp(): void
    {
        parent::setUp();
        // Make sure the well-known public client exists so the allow-path requests
        // get past client validation and reach the consent form.
        GeneralUtility::makeInstance(OAuthService::class)->ensureWellKnownClient();
        $this->endpoint = GeneralUtility::makeInstance(OAuthAuthorizeEndpoint::class);
    }

    public function testAuthorizeIsDeniedForNonAdminWithoutModuleAccess(): void
    {
        $this->createNonAdminUser(99);
        $this->setupDefaultBackendUser(99);

        $response = $this->dispatch();

        self::assertSame(403, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertSame('access_denied', $body['error'] ?? null);
    }

    public function testAuthorizeIsAllowedForAdmin(): void
    {
        // The default backend user from AbstractFunctionalTest is the admin (uid 1),
        // who always has module access.
        $response = $this->dispatch();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Authorize MCP Access', (string)$response->getBody());
    }

    public function testAuthorizeIsAllowedForNonAdminWithModuleAccess(): void
    {
        $this->createNonAdminUser(99);
        $this->grantModuleAccessViaGroup(99, 'user_mcp_server');
        $this->setupDefaultBackendUser(99);

        $response = $this->dispatch();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Authorize MCP Access', (string)$response->getBody());
    }

    /**
     * Build a GET request to the authorize endpoint for the well-known public
     * client. PKCE is required for public clients, so a code_challenge is supplied
     * to let the allow-path reach the consent form rather than an early rejection.
     */
    private function dispatch(): ResponseInterface
    {
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $request = (new ServerRequest(new Uri('https://example.com/typo3/mcp_oauth/authorize'), 'GET'))
            ->withQueryParams([
                'client_id' => OAuthService::WELL_KNOWN_CLIENT_ID,
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
                'state' => 'test-state',
            ]);

        return $this->endpoint->__invoke($request);
    }

    private function decodeBody(ResponseInterface $response): array
    {
        $body = (string)$response->getBody();
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded, 'Error response must be JSON: ' . $body);
        return $decoded;
    }

    private function createNonAdminUser(int $uid): void
    {
        $connection = $this->connectionPool->getConnectionForTable('be_users');
        if ($connection->count('*', 'be_users', ['uid' => $uid]) > 0) {
            return;
        }
        $connection->insert('be_users', [
            'uid' => $uid,
            'pid' => 0,
            'username' => 'editor_' . $uid,
            'password' => '$argon2i$v=19$m=65536,t=16,p=1$dGVzdHNhbHQ$testpasswordhash',
            'admin' => 0,
            'disable' => 0,
            'deleted' => 0,
            'tstamp' => time(),
            'crdate' => time(),
        ]);
    }

    /**
     * Grant a non-admin user access to a backend module by putting it into a
     * backend group's allowed modules (groupMods) and assigning the user to it.
     */
    private function grantModuleAccessViaGroup(int $userUid, string $moduleIdentifier): void
    {
        $groupConnection = $this->connectionPool->getConnectionForTable('be_groups');
        $groupUid = 900 + $userUid;
        $groupConnection->insert('be_groups', [
            'uid' => $groupUid,
            'pid' => 0,
            'title' => 'MCP editors',
            'groupMods' => $moduleIdentifier,
            'deleted' => 0,
        ]);

        $this->connectionPool->getConnectionForTable('be_users')->update(
            'be_users',
            ['usergroup' => (string)$groupUid],
            ['uid' => $userUid]
        );
    }
}
