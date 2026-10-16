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
        // RFC 7591 §3.2.1: client_secret_expires_at is REQUIRED when a secret is issued
        $this->assertSame(0, $result['client_secret_expires_at']);

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

    public function testWildcardSentinelsAreRejectedForDynamicClients(): void
    {
        foreach ([['*'], [OAuthService::REDIRECT_URI_LOOPBACK_SENTINEL]] as $attempt) {
            $result = $this->service->registerClient([
                'client_name' => 'Try wildcard',
                'redirect_uris' => $attempt,
            ]);

            $this->assertSame(['http://localhost'], $result['redirect_uris']);
            $client = $this->service->getClient($result['client_id']);
            $this->assertSame(['http://localhost'], $client['redirect_uris']);
            $this->assertFalse(
                $this->service->isRedirectUriAllowed($client, 'http://attacker.example.com/cb'),
                'Dynamic clients must never allow arbitrary redirect URIs even when they tried to register a sentinel'
            );
        }
    }

    public function testRegisterRejectsNonHttpRedirectUri(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html,x', '//evil.example.com', 'ftp://a/', 'http:///nohost'] as $bad) {
            try {
                $this->service->registerClient(['redirect_uris' => [$bad]]);
                $this->fail('Expected InvalidArgumentException for redirect_uri: ' . $bad);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Invalid redirect_uri', $e->getMessage());
            }
        }
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
        $this->assertContains(
            OAuthService::REDIRECT_URI_LOOPBACK_SENTINEL,
            $client['redirect_uris'],
            'Well-known client must be seeded with the loopback sentinel for backward compatibility'
        );
    }

    public function testWellKnownClientLoopbackSentinelOnlyAcceptsLoopback(): void
    {
        $client = $this->service->getClient(OAuthService::WELL_KNOWN_CLIENT_ID);

        // Loopback URIs (any port, any path) MUST be accepted for BC.
        $this->assertTrue($this->service->isRedirectUriAllowed($client, 'http://localhost'));
        $this->assertTrue($this->service->isRedirectUriAllowed($client, 'http://localhost:6274/oauth/callback'));
        $this->assertTrue($this->service->isRedirectUriAllowed($client, 'http://127.0.0.1:12345/cb'));
        $this->assertTrue($this->service->isRedirectUriAllowed($client, 'https://localhost:8443/cb'));

        // Non-loopback hosts MUST be rejected — the sentinel is NOT an open redirector.
        $this->assertFalse($this->service->isRedirectUriAllowed($client, 'http://attacker.example.com/cb'));
        $this->assertFalse($this->service->isRedirectUriAllowed($client, 'https://example.com'));
        $this->assertFalse($this->service->isRedirectUriAllowed($client, 'http://localhost.attacker.com/cb'));
    }

    public function testWellKnownClientIsRestoredIfSoftDeleted(): void
    {
        // Seed once
        $client = $this->service->getClient(OAuthService::WELL_KNOWN_CLIENT_ID);
        $this->assertNotNull($client);

        // Soft-delete the row
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_oauth_clients');
        $connection->update(
            'tx_mcpserver_oauth_clients',
            ['deleted' => 1],
            ['uid' => $client['uid']]
        );

        // A subsequent lookup must self-heal — not deadlock
        $restored = $this->service->getClient(OAuthService::WELL_KNOWN_CLIENT_ID);
        $this->assertNotNull($restored, 'Well-known client must be restored, not skipped, when soft-deleted');
        $this->assertSame($client['uid'], $restored['uid'], 'Should undelete the same row, not insert a duplicate');
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

    public function testManualTokenIsBoundToWellKnownClient(): void
    {
        $plainToken = $this->service->createDirectAccessToken(1, 'My Laptop');
        $wellKnown = $this->service->getClient(OAuthService::WELL_KNOWN_CLIENT_ID);

        $info = $this->service->validateToken($plainToken);
        $this->assertNotNull($info);
        $this->assertSame($wellKnown['uid'], $info['client_uid']);
    }

    public function testOAuthTokenIsBoundToIssuingClient(): void
    {
        $registered = $this->service->registerClient([
            'client_name' => 'Issuer',
            'redirect_uris' => ['http://localhost'],
        ]);
        $code = $this->service->createAuthorizationCode(
            1,
            'Issuer',
            '',
            '',
            'S256',
            $registered['client_id']
        );
        $tokenData = $this->service->exchangeCodeForToken($code, null, null, null, $registered['client_id']);
        $this->assertNotNull($tokenData);

        $issuer = $this->service->getClient($registered['client_id']);
        $info = $this->service->validateToken($tokenData['access_token']);
        $this->assertNotNull($info);
        $this->assertSame($issuer['uid'], $info['client_uid']);
    }

    public function testTokenIsRevokedWhenIssuingClientIsDeleted(): void
    {
        $registered = $this->service->registerClient([
            'client_name' => 'ToBeDeleted',
            'redirect_uris' => ['http://localhost'],
        ]);
        $code = $this->service->createAuthorizationCode(
            1,
            'ToBeDeleted',
            '',
            '',
            'S256',
            $registered['client_id']
        );
        $tokenData = $this->service->exchangeCodeForToken($code, null, null, null, $registered['client_id']);
        $this->assertNotNull($tokenData);
        $this->assertNotNull($this->service->validateToken($tokenData['access_token']));

        // Soft-delete the client; the token must now be rejected
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_oauth_clients');
        $connection->update(
            'tx_mcpserver_oauth_clients',
            ['deleted' => 1, 'tstamp' => time()],
            ['client_id' => $registered['client_id']]
        );

        $this->assertNull(
            $this->service->validateToken($tokenData['access_token']),
            'Tokens for a deleted client must no longer validate'
        );
    }

    public function testGetClientsByUidsReturnsMapKeyedByUid(): void
    {
        $a = $this->service->registerClient([
            'client_name' => 'A',
            'redirect_uris' => ['http://localhost'],
        ]);
        $b = $this->service->registerClient([
            'client_name' => 'B',
            'redirect_uris' => ['http://localhost'],
        ]);
        $clientA = $this->service->getClient($a['client_id']);
        $clientB = $this->service->getClient($b['client_id']);

        $map = $this->service->getClientsByUids([$clientA['uid'], $clientB['uid'], 999999]);

        $this->assertArrayHasKey($clientA['uid'], $map);
        $this->assertArrayHasKey($clientB['uid'], $map);
        $this->assertArrayNotHasKey(999999, $map);
        $this->assertSame('A', $map[$clientA['uid']]['client_name']);
        $this->assertSame('B', $map[$clientB['uid']]['client_name']);
    }

    public function testGetClientsByUidsExcludesDeletedClients(): void
    {
        $registered = $this->service->registerClient([
            'client_name' => 'Doomed',
            'redirect_uris' => ['http://localhost'],
        ]);
        $client = $this->service->getClient($registered['client_id']);
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_oauth_clients');
        $connection->update('tx_mcpserver_oauth_clients', ['deleted' => 1], ['uid' => $client['uid']]);

        $this->assertSame([], $this->service->getClientsByUids([$client['uid']]));
    }

    public function testGetClientsByUidsHandlesEmptyInput(): void
    {
        $this->assertSame([], $this->service->getClientsByUids([]));
        $this->assertSame([], $this->service->getClientsByUids([0, 0]));
    }

    public function testTokenEndpointAcceptsClientSecretBasic(): void
    {
        $registered = $this->service->registerClient([
            'client_name' => 'Basic Auth Client',
            'redirect_uris' => ['https://example.com/cb'],
            'token_endpoint_auth_method' => 'client_secret_basic',
        ]);
        $code = $this->service->createAuthorizationCode(
            1,
            'Basic Auth Client',
            '',
            '',
            'S256',
            $registered['client_id']
        );

        // RFC 6749 §2.3.1: credentials are form-urlencoded, then base64-encoded
        $basic = base64_encode(urlencode($registered['client_id']) . ':' . urlencode($registered['client_secret']));
        $request = (new \TYPO3\CMS\Core\Http\ServerRequest('https://example.com/mcp_oauth/token', 'POST'))
            ->withHeader('Authorization', 'Basic ' . $basic)
            ->withParsedBody([
                'grant_type' => 'authorization_code',
                'code' => $code,
            ]);

        $response = (new \Hn\McpServer\Http\OAuthTokenEndpoint())($request);
        $body = json_decode((string)$response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode(), (string)json_encode($body));
        $this->assertArrayHasKey('access_token', $body);
    }

    public function testTokenEndpointRejectsWrongBasicSecret(): void
    {
        $registered = $this->service->registerClient([
            'client_name' => 'Basic Auth Client',
            'redirect_uris' => ['https://example.com/cb'],
            'token_endpoint_auth_method' => 'client_secret_basic',
        ]);
        $code = $this->service->createAuthorizationCode(1, 'Basic Auth Client', '', '', 'S256', $registered['client_id']);

        $basic = base64_encode(urlencode($registered['client_id']) . ':' . urlencode('wrong-secret'));
        $request = (new \TYPO3\CMS\Core\Http\ServerRequest('https://example.com/mcp_oauth/token', 'POST'))
            ->withHeader('Authorization', 'Basic ' . $basic)
            ->withParsedBody([
                'grant_type' => 'authorization_code',
                'code' => $code,
            ]);

        $response = (new \Hn\McpServer\Http\OAuthTokenEndpoint())($request);
        $body = json_decode((string)$response->getBody(), true);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('invalid_client', $body['error']);
    }

    public function testCleanupRemovesStaleClientsButKeepsActiveAndWellKnown(): void
    {
        $stale = $this->service->registerClient([
            'client_name' => 'Stale',
            'redirect_uris' => ['http://localhost'],
        ]);
        $active = $this->service->registerClient([
            'client_name' => 'Active',
            'redirect_uris' => ['http://localhost'],
        ]);
        $this->service->getClient(OAuthService::WELL_KNOWN_CLIENT_ID); // seed well-known

        // Age all clients past the token lifetime
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_oauth_clients');
        $old = time() - 2592000 - 86400;
        foreach ([$stale['client_id'], $active['client_id'], OAuthService::WELL_KNOWN_CLIENT_ID] as $clientId) {
            $connection->update(
                'tx_mcpserver_oauth_clients',
                ['crdate' => $old, 'last_used' => 0],
                ['client_id' => $clientId]
            );
        }

        // Give the active client a live token
        $activeClient = $this->service->getClient($active['client_id']);
        $tokenConnection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens');
        $tokenConnection->insert('tx_mcpserver_access_tokens', [
            'pid' => 0, 'tstamp' => time(), 'crdate' => time(),
            'token' => hash('sha256', bin2hex(random_bytes(32))),
            'token_version' => 1,
            'be_user_uid' => 1,
            'client_uid' => $activeClient['uid'],
            'client_name' => 'Active',
            'expires' => time() + 86400,
            'last_used' => time(), 'created_ip' => '', 'last_used_ip' => '',
        ]);

        $this->service->cleanupExpired();

        $this->assertNull($this->service->getClient($stale['client_id']), 'Stale client without live tokens must be removed');
        $this->assertNotNull($this->service->getClient($active['client_id']), 'Client with a live token must be kept');
        $this->assertNotNull($this->service->getClient(OAuthService::WELL_KNOWN_CLIENT_ID), 'Well-known client must never be removed');
    }

    public function testTokenIssuanceUpdatesClientLastUsed(): void
    {
        $registered = $this->service->registerClient([
            'client_name' => 'Tracked',
            'redirect_uris' => ['http://localhost'],
        ]);
        $code = $this->service->createAuthorizationCode(1, 'Tracked', '', '', 'S256', $registered['client_id']);
        $this->assertNotNull($this->service->exchangeCodeForToken($code, null, null, null, $registered['client_id']));

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_oauth_clients');
        $lastUsed = (int)$connection->createQueryBuilder()
            ->select('last_used')
            ->from('tx_mcpserver_oauth_clients')
            ->where('client_id = ' . $connection->quote($registered['client_id']))
            ->executeQuery()
            ->fetchOne();

        $this->assertGreaterThan(0, $lastUsed, 'Issuing a token must record client activity for stale-client cleanup');
    }

    public function testAuthorizationCodeCannotBeRedeemedTwice(): void
    {
        // RFC 6749 §4.1.2: single-use. The atomicity fix deletes the code BEFORE
        // issuing the token, so the second exchange sees no code and returns null.
        $code = $this->service->createAuthorizationCode(1, 'test-client');

        $first = $this->service->exchangeCodeForToken($code);
        $this->assertNotNull($first, 'First redemption must succeed');

        $second = $this->service->exchangeCodeForToken($code);
        $this->assertNull($second, 'Second redemption of the same code must fail');
    }

    public function testLegacyTokenWithoutClientUidStillValidates(): void
    {
        // A token row inserted before the client_uid column existed (client_uid=0)
        // must remain valid for backward compatibility.
        $plainToken = bin2hex(random_bytes(32));
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens');
        $connection->insert('tx_mcpserver_access_tokens', [
            'pid' => 0, 'tstamp' => time(), 'crdate' => time(),
            'token' => hash('sha256', $plainToken),
            'token_version' => 1,
            'be_user_uid' => 1,
            'client_uid' => 0,
            'client_name' => 'pre-binding-token',
            'expires' => time() + 86400,
            'last_used' => time(), 'created_ip' => '', 'last_used_ip' => '',
        ]);

        $info = $this->service->validateToken($plainToken);
        $this->assertNotNull($info, 'Legacy token without client_uid must still validate');
        $this->assertSame(0, $info['client_uid']);
    }
}
