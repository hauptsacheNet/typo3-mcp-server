<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tests for the configurable access token lifetime (extension settings
 * "tokenLifetimeDays", "tokenSlidingExpiration", "tokenMaxLifetimeDays").
 */
class OAuthTokenLifetimeTest extends AbstractFunctionalTest
{
    private const DAY = 86400;

    private OAuthService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = GeneralUtility::makeInstance(OAuthService::class);
    }

    private function configure(array $settings): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = array_merge(
            $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] ?? [],
            $settings
        );
    }

    private function latestToken(): array
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens')
            ->createQueryBuilder()
            ->select('*')
            ->from('tx_mcpserver_access_tokens')
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
    }

    private function updateLatestToken(array $values): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens')
            ->update('tx_mcpserver_access_tokens', $values, ['uid' => (int)$this->latestToken()['uid']]);
    }

    private function assertExpiresAfterIssue(int $lifetime): void
    {
        $token = $this->latestToken();
        $this->assertSame((int)$token['crdate'] + $lifetime, (int)$token['expires']);
    }

    private function issueTokenViaOAuthFlow(): array
    {
        $code = $this->service->createAuthorizationCode(1, 'test-client');
        $tokenData = $this->service->exchangeCodeForToken($code);
        $this->assertNotNull($tokenData);
        return $tokenData;
    }

    public function testDefaultLifetimeIsThirtyDays(): void
    {
        $tokenData = $this->issueTokenViaOAuthFlow();

        $this->assertSame(30 * self::DAY, $tokenData['expires_in']);
        $this->assertExpiresAfterIssue(30 * self::DAY);
    }

    public function testConfiguredLifetimeAppliesToAllWaysOfIssuingAToken(): void
    {
        $this->configure(['tokenLifetimeDays' => '7']);

        $tokenData = $this->issueTokenViaOAuthFlow();
        $this->assertSame(7 * self::DAY, $tokenData['expires_in']);
        $this->assertExpiresAfterIssue(7 * self::DAY);

        $this->service->createDirectAccessToken(1, 'backend-module');
        $this->assertExpiresAfterIssue(7 * self::DAY);

        $created = $this->service->createToken(1, 'cli');
        $this->assertSame(7 * self::DAY, $created['expires_in']);
    }

    public function testExplicitTtlOfCliTokenWinsOverConfiguredLifetime(): void
    {
        $this->configure(['tokenLifetimeDays' => '7']);

        $created = $this->service->createToken(1, 'cli', 2 * self::DAY);

        $this->assertSame(2 * self::DAY, $created['expires_in']);
    }

    public function testWithoutSlidingExpirationUseDoesNotExtendTheToken(): void
    {
        $tokenData = $this->issueTokenViaOAuthFlow();
        $expiresSoon = time() + 100;
        $this->updateLatestToken(['expires' => $expiresSoon]);

        $this->assertNotNull($this->service->validateToken($tokenData['access_token']));

        $this->assertSame($expiresSoon, (int)$this->latestToken()['expires']);
    }

    public function testSlidingExpirationExtendsTheTokenOnUse(): void
    {
        $this->configure(['tokenLifetimeDays' => '30', 'tokenSlidingExpiration' => '1']);
        $tokenData = $this->issueTokenViaOAuthFlow();
        $this->updateLatestToken(['expires' => time() + 100]);

        $this->assertNotNull($this->service->validateToken($tokenData['access_token']));

        $token = $this->latestToken();
        $this->assertSame((int)$token['last_used'] + 30 * self::DAY, (int)$token['expires']);
    }

    public function testSlidingExpirationStopsAtTheMaximumLifetime(): void
    {
        $this->configure(['tokenLifetimeDays' => '30', 'tokenSlidingExpiration' => '1', 'tokenMaxLifetimeDays' => '90']);
        $tokenData = $this->issueTokenViaOAuthFlow();
        $issuedAt = time() - 89 * self::DAY;
        $this->updateLatestToken(['crdate' => $issuedAt, 'expires' => time() + 100]);

        $this->assertNotNull($this->service->validateToken($tokenData['access_token']));

        $this->assertSame($issuedAt + 90 * self::DAY, (int)$this->latestToken()['expires']);
    }

    public function testMaximumLifetimeAlsoCapsANewToken(): void
    {
        $this->configure(['tokenLifetimeDays' => '30', 'tokenSlidingExpiration' => '1', 'tokenMaxLifetimeDays' => '7']);

        $tokenData = $this->issueTokenViaOAuthFlow();

        $this->assertSame(7 * self::DAY, $tokenData['expires_in']);
    }

    public function testMaximumLifetimeIsIgnoredWithoutSlidingExpiration(): void
    {
        $this->configure(['tokenLifetimeDays' => '30', 'tokenMaxLifetimeDays' => '7']);

        $tokenData = $this->issueTokenViaOAuthFlow();

        $this->assertSame(30 * self::DAY, $tokenData['expires_in']);
    }

    public function testSlidingExpirationNeverShortensAToken(): void
    {
        $this->configure(['tokenLifetimeDays' => '30', 'tokenSlidingExpiration' => '1']);
        $created = $this->service->createToken(1, 'cli', 365 * self::DAY);

        $this->assertNotNull($this->service->validateToken($created['access_token']));

        $this->assertSame($created['expires'], (int)$this->latestToken()['expires']);
    }

    public function testAnExpiredTokenIsNotRevivedBySlidingExpiration(): void
    {
        $this->configure(['tokenSlidingExpiration' => '1']);
        $tokenData = $this->issueTokenViaOAuthFlow();
        $this->updateLatestToken(['expires' => time() - 1]);

        $this->assertNull($this->service->validateToken($tokenData['access_token']));

        $this->assertLessThan(time(), (int)$this->latestToken()['expires']);
    }
}
