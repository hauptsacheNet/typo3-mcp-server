<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tests for OAuth Dynamic Client Registration (RFC 7591) and the related
 * client lookup / redirect_uri validation paths.
 */
class OAuthClientRegistrationTest extends AbstractFunctionalTest
{
    private OAuthService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = GeneralUtility::makeInstance(OAuthService::class);
    }

    public function testRegisterClientPersistsAndReturnsClientId(): void
    {
        $result = $this->service->registerClient([
            'client_name' => 'Inspector',
            'redirect_uris' => ['http://localhost:6274/oauth/callback'],
        ]);

        $this->assertArrayHasKey('client_id', $result);
        $this->assertStringStartsWith('mcp_', $result['client_id']);
        $this->assertSame('Inspector', $result['client_name']);
        $this->assertSame(['http://localhost:6274/oauth/callback'], $result['redirect_uris']);
        $this->assertArrayNotHasKey('client_secret', $result, 'Public clients must not receive a secret');

        $client = $this->service->getClient($result['client_id']);
        $this->assertNotNull($client);
        $this->assertSame('Inspector', $client['client_name']);
        $this->assertSame('none', $client['token_endpoint_auth_method']);
    }

    public function testRegisterClientWithSecretReturnsAndHashesSecret(): void
    {
        $result = $this->service->registerClient([
            'client_name' => 'Confidential',
            'redirect_uris' => ['https://example.com/cb'],
            'token_endpoint_auth_method' => 'client_secret_post',
        ]);

        $this->assertArrayHasKey('client_secret', $result);
        $this->assertNotEmpty($result['client_secret']);

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_oauth_clients');
        $row = $connection->createQueryBuilder()
            ->select('client_secret')
            ->from('tx_mcpserver_oauth_clients')
            ->where('client_id = ' . $connection->quote($result['client_id']))
            ->executeQuery()
            ->fetchAssociative();

        $this->assertNotEquals($result['client_secret'], $row['client_secret'], 'Plain secret must NOT be stored');
        $this->assertSame(hash('sha256', $result['client_secret']), $row['client_secret']);
    }

    public function testWildcardRedirectUriIsRejectedForDynamicClients(): void
    {
        $result = $this->service->registerClient([
            'client_name' => 'Try wildcard',
            'redirect_uris' => ['*'],
        ]);

        // The '*' is filtered out, so the default fallback is used
        $this->assertSame(['http://localhost'], $result['redirect_uris']);

        $client = $this->service->getClient($result['client_id']);
        $this->assertSame(['http://localhost'], $client['redirect_uris']);
        $this->assertFalse(
            $this->service->isRedirectUriAllowed($client, 'http://attacker.example.com/cb'),
            'Dynamic clients must never allow arbitrary redirect URIs even when they tried to register *'
        );
    }

    public function testInvalidRedirectUriThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->registerClient([
            'redirect_uris' => ['http:///bad'],
        ]);
    }

    public function testGetClientReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->service->getClient('does_not_exist'));
        $this->assertNull($this->service->getClient(''));
    }

    public function testWellKnownClientIsAutoSeededOnLookup(): void
    {
        $client = $this->service->getClient(OAuthService::WELL_KNOWN_CLIENT_ID);

        $this->assertNotNull($client);
        $this->assertSame(OAuthService::WELL_KNOWN_CLIENT_ID, $client['client_id']);
        $this->assertSame('none', $client['token_endpoint_auth_method']);
        $this->assertContains('*', $client['redirect_uris'], 'Well-known client must accept arbitrary redirect URIs for backward compatibility');
    }

    public function testEnsureWellKnownClientIsIdempotent(): void
    {
        $this->service->ensureWellKnownClient();
        $this->service->ensureWellKnownClient();

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_oauth_clients');
        $count = (int)$connection->createQueryBuilder()
            ->count('uid')
            ->from('tx_mcpserver_oauth_clients')
            ->where('client_id = ' . $connection->quote(OAuthService::WELL_KNOWN_CLIENT_ID))
            ->executeQuery()
            ->fetchOne();

        $this->assertSame(1, $count);
    }

    public function testRedirectUriExactMatchAllowed(): void
    {
        $registered = $this->service->registerClient([
            'redirect_uris' => ['https://example.com/cb'],
        ]);
        $client = $this->service->getClient($registered['client_id']);

        $this->assertTrue($this->service->isRedirectUriAllowed($client, 'https://example.com/cb'));
        $this->assertFalse($this->service->isRedirectUriAllowed($client, 'https://example.com/cb2'));
        $this->assertFalse($this->service->isRedirectUriAllowed($client, 'https://other.example.com/cb'));
    }

    public function testRedirectUriLoopbackPortWildcardingAllowed(): void
    {
        $registered = $this->service->registerClient([
            'redirect_uris' => ['http://localhost/oauth/callback'],
        ]);
        $client = $this->service->getClient($registered['client_id']);

        // Same path, different port — should match per RFC 8252 §7.3
        $this->assertTrue($this->service->isRedirectUriAllowed($client, 'http://localhost:6274/oauth/callback'));
        $this->assertTrue($this->service->isRedirectUriAllowed($client, 'http://localhost:1234/oauth/callback'));
        // Different path must NOT match
        $this->assertFalse($this->service->isRedirectUriAllowed($client, 'http://localhost:6274/other'));
        // Non-loopback host must NOT match
        $this->assertFalse($this->service->isRedirectUriAllowed($client, 'http://example.com:6274/oauth/callback'));
    }

    public function testRedirectUriEmptyIsRejected(): void
    {
        $registered = $this->service->registerClient([
            'redirect_uris' => ['http://localhost'],
        ]);
        $client = $this->service->getClient($registered['client_id']);

        $this->assertFalse($this->service->isRedirectUriAllowed($client, ''));
    }

    public function testVerifyClientSecretPublicClient(): void
    {
        $registered = $this->service->registerClient([
            'redirect_uris' => ['http://localhost'],
        ]);
        $client = $this->service->getClient($registered['client_id']);

        // Public clients pass without a secret (PKCE handles authentication)
        $this->assertTrue($this->service->verifyClientSecret($client, null));
        $this->assertTrue($this->service->verifyClientSecret($client, ''));
        $this->assertTrue($this->service->verifyClientSecret($client, 'anything'));
    }

    public function testVerifyClientSecretConfidentialClient(): void
    {
        $registered = $this->service->registerClient([
            'redirect_uris' => ['https://example.com/cb'],
            'token_endpoint_auth_method' => 'client_secret_post',
        ]);
        $client = $this->service->getClient($registered['client_id']);

        $this->assertTrue($this->service->verifyClientSecret($client, $registered['client_secret']));
        $this->assertFalse($this->service->verifyClientSecret($client, 'wrong'));
        $this->assertFalse($this->service->verifyClientSecret($client, ''));
        $this->assertFalse($this->service->verifyClientSecret($client, null));
    }

    public function testCodeExchangeRequiresMatchingRedirectUri(): void
    {
        $code = $this->service->createAuthorizationCode(
            1,
            'test-client',
            'http://localhost:6274/oauth/callback'
        );

        // Without redirect_uri the exchange must fail (auth code was bound to one)
        $this->assertNull($this->service->exchangeCodeForToken($code));

        // With wrong redirect_uri the exchange must fail
        $code2 = $this->service->createAuthorizationCode(
            1,
            'test-client',
            'http://localhost:6274/oauth/callback'
        );
        $this->assertNull($this->service->exchangeCodeForToken($code2, null, null, 'http://attacker.example.com'));

        // With matching redirect_uri it succeeds
        $code3 = $this->service->createAuthorizationCode(
            1,
            'test-client',
            'http://localhost:6274/oauth/callback'
        );
        $result = $this->service->exchangeCodeForToken($code3, null, null, 'http://localhost:6274/oauth/callback');
        $this->assertNotNull($result);
        $this->assertArrayHasKey('access_token', $result);
    }

    public function testCodeExchangeWithoutBoundRedirectUriIsLenient(): void
    {
        // If the auth code wasn't bound to a redirect_uri (bare flow / pasted code),
        // the token endpoint doesn't need to enforce one.
        $code = $this->service->createAuthorizationCode(1, 'test-client');
        $result = $this->service->exchangeCodeForToken($code);
        $this->assertNotNull($result);
    }

    public function testCodeIsBoundToIssuingClient(): void
    {
        // RFC 6749 §10.5: a code issued to client A must not be redeemable by client B,
        // even if both are valid registered clients with no secret (PKCE not used here).
        $clientA = $this->service->registerClient([
            'client_name' => 'Client A',
            'redirect_uris' => ['http://localhost'],
        ]);
        $clientB = $this->service->registerClient([
            'client_name' => 'Client B',
            'redirect_uris' => ['http://localhost'],
        ]);

        $code = $this->service->createAuthorizationCode(
            1,
            'Client A',
            '',
            '',
            'S256',
            $clientA['client_id']
        );

        // Wrong client id must fail
        $this->assertNull(
            $this->service->exchangeCodeForToken($code, null, null, null, $clientB['client_id']),
            'Code issued to client A must not be redeemable by client B'
        );
        // Missing client id must fail when one was bound
        $this->assertNull(
            $this->service->exchangeCodeForToken($code, null, null, null, null),
            'Bound code must require the client_id at exchange'
        );
        // Correct client id succeeds (and the code is then consumed)
        $result = $this->service->exchangeCodeForToken($code, null, null, null, $clientA['client_id']);
        $this->assertNotNull($result);
        $this->assertArrayHasKey('access_token', $result);
    }

    public function testLoopbackMatcherRejectsDifferingQueryString(): void
    {
        $registered = $this->service->registerClient([
            'redirect_uris' => ['http://localhost/cb?x=1'],
        ]);
        $client = $this->service->getClient($registered['client_id']);

        // Same query is fine (with port wildcarding)
        $this->assertTrue($this->service->isRedirectUriAllowed($client, 'http://localhost:6274/cb?x=1'));
        // Different query value must NOT match
        $this->assertFalse($this->service->isRedirectUriAllowed($client, 'http://localhost:6274/cb?x=attacker'));
        // Missing query when one is registered must NOT match
        $this->assertFalse($this->service->isRedirectUriAllowed($client, 'http://localhost:6274/cb'));
    }

    public function testLoopbackMatcherRejectsDifferingFragment(): void
    {
        $registered = $this->service->registerClient([
            'redirect_uris' => ['http://localhost/cb#a'],
        ]);
        $client = $this->service->getClient($registered['client_id']);

        $this->assertTrue($this->service->isRedirectUriAllowed($client, 'http://localhost:6274/cb#a'));
        $this->assertFalse($this->service->isRedirectUriAllowed($client, 'http://localhost:6274/cb#b'));
    }
}
