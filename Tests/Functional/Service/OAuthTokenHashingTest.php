<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tests for OAuth token hashing at rest
 */
class OAuthTokenHashingTest extends AbstractFunctionalTest
{
    private OAuthService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = GeneralUtility::makeInstance(OAuthService::class);
    }

    public function testAccessTokenIsHashedInDatabase(): void
    {
        $plainToken = $this->service->createDirectAccessToken(1, 'test-client');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $plainToken);

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens');
        $row = $connection->createQueryBuilder()
            ->select('token')
            ->from('tx_mcpserver_access_tokens')
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        $this->assertNotFalse($row);
        $this->assertNotEquals($plainToken, $row['token'], 'Plain token must NOT be stored in database');
        $this->assertEquals(hash('sha256', $plainToken), $row['token'], 'Stored value must be SHA-256 hash of token');
    }

    public function testHashedTokenCanBeValidated(): void
    {
        $plainToken = $this->service->createDirectAccessToken(1, 'test-client');
        $result = $this->service->validateToken($plainToken);

        $this->assertNotNull($result, 'Valid token must be accepted');
        $this->assertEquals(1, $result['be_user_uid']);
    }

    public function testWrongTokenIsRejected(): void
    {
        $this->service->createDirectAccessToken(1, 'test-client');
        $result = $this->service->validateToken('0000000000000000000000000000000000000000000000000000000000000000');

        $this->assertNull($result, 'Wrong token must be rejected');
    }

    public function testExpiredTokenIsRejected(): void
    {
        $plainToken = $this->service->createDirectAccessToken(1, 'test-client');

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens');
        $connection->update('tx_mcpserver_access_tokens', ['expires' => time() - 1], ['be_user_uid' => 1]);

        $this->assertNull($this->service->validateToken($plainToken), 'Expired token must be rejected');
    }

    public function testTokenHashingWorksForCodeExchange(): void
    {
        $code = $this->service->createAuthorizationCode(1, 'test-client');
        $tokenData = $this->service->exchangeCodeForToken($code);

        $this->assertNotNull($tokenData);
        $result = $this->service->validateToken($tokenData['access_token']);
        $this->assertNotNull($result);

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens');
        $row = $connection->createQueryBuilder()
            ->select('token')
            ->from('tx_mcpserver_access_tokens')
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        $this->assertNotEquals($tokenData['access_token'], $row['token']);
    }

    public function testLegacyPlainTextTokenValidatesViaFallback(): void
    {
        $plainToken = bin2hex(random_bytes(32));
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens');
        $connection->insert('tx_mcpserver_access_tokens', [
            'pid' => 0, 'tstamp' => time(), 'crdate' => time(),
            'token' => $plainToken,
            'token_version' => 0,
            'be_user_uid' => 1, 'client_name' => 'legacy-client',
            'expires' => time() + 86400,
            'last_used' => time(), 'created_ip' => '', 'last_used_ip' => '',
        ]);

        $result = $this->service->validateToken($plainToken);
        $this->assertNotNull($result, 'Pre-migration plaintext tokens must validate via fallback');
        $this->assertEquals(1, $result['be_user_uid']);

        // Verify the token was auto-upgraded to hashed (token_version=1)
        $row = $connection->createQueryBuilder()
            ->select('token', 'token_version')
            ->from('tx_mcpserver_access_tokens')
            ->where('client_name = \'legacy-client\'')
            ->executeQuery()
            ->fetchAssociative();
        $this->assertEquals(1, (int)$row['token_version'], 'Token must be auto-upgraded to version 1');
        $this->assertEquals(hash('sha256', $plainToken), $row['token'], 'Token must be hashed after auto-upgrade');

        // Second validation should work via hash lookup (no longer needs fallback)
        $result2 = $this->service->validateToken($plainToken);
        $this->assertNotNull($result2, 'Token must still validate after auto-upgrade via hash lookup');
    }

    public function testLegacyPlainTextTokenInvalidAfterWizardMigration(): void
    {
        $plainToken = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $plainToken);
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens');
        // Simulate post-wizard state: token is hashed, version=1
        $connection->insert('tx_mcpserver_access_tokens', [
            'pid' => 0, 'tstamp' => time(), 'crdate' => time(),
            'token' => $hashedToken,
            'token_version' => 1,
            'be_user_uid' => 1, 'client_name' => 'legacy-client',
            'expires' => time() + 86400,
            'last_used' => time(), 'created_ip' => '', 'last_used_ip' => '',
        ]);

        // The plain token should validate (hash lookup: hash(plain) == stored hash)
        $result = $this->service->validateToken($plainToken);
        $this->assertNotNull($result, 'Migrated tokens must validate via hash lookup');

        // The raw hash should NOT validate (hash(hash) != stored hash)
        $this->assertNull($this->service->validateToken($hashedToken), 'Raw hash value must not validate');
    }

    public function testRevokedTokenIsRejected(): void
    {
        $plainToken = $this->service->createDirectAccessToken(1, 'test-client');
        $valid = $this->service->validateToken($plainToken);
        $this->assertNotNull($valid);

        $this->service->revokeToken($valid['token_uid'], 1);
        $this->assertNull($this->service->validateToken($plainToken), 'Revoked token must be rejected');
    }

    public function testAuthorizationCodeIsOneTimeUse(): void
    {
        $code = $this->service->createAuthorizationCode(1, 'test-client');
        $this->assertNotNull($this->service->exchangeCodeForToken($code));
        $this->assertNull($this->service->exchangeCodeForToken($code), 'Second exchange must fail');
    }

    public function testExpiredAuthorizationCodeIsRejected(): void
    {
        $code = $this->service->createAuthorizationCode(1, 'test-client');
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_oauth_codes');
        $connection->update('tx_mcpserver_oauth_codes', ['expires' => time() - 1], ['code' => $code]);

        $this->assertNull($this->service->exchangeCodeForToken($code));
    }
}
