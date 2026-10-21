<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LogLevel;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Log\Writer\FileWriter;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tests for the configurable access token lifetime (extension settings
 * "tokenLifetimeDays", "tokenSlidingExpiration", "tokenMaxLifetimeDays").
 *
 * Every test leaves the extension settings, the log configuration and the
 * table schema as it found them, so the tests pass in any order.
 */
class OAuthTokenLifetimeTest extends AbstractFunctionalTest
{
    private const DAY = 86400;
    private const MAX_TIMESTAMP = 2147483647;

    private OAuthService $service;
    private mixed $previousExtensionSettings;
    private mixed $previousLogConfiguration;

    protected function setUp(): void
    {
        parent::setUp();
        // Load the full extension configuration once, so every key exists:
        // a missing one makes core sync the templates and write the current
        // values - including a setting of this test - into the test
        // instance's settings.php, where the next test would read them.
        GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('mcp_server');
        $this->previousExtensionSettings = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] ?? null;
        $this->previousLogConfiguration = $GLOBALS['TYPO3_CONF_VARS']['LOG'] ?? null;
        $this->configure(['tokenLifetimeDays' => '30', 'tokenSlidingExpiration' => '0', 'tokenMaxLifetimeDays' => '0']);
        $this->service = GeneralUtility::makeInstance(OAuthService::class);
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = $this->previousExtensionSettings;
        $GLOBALS['TYPO3_CONF_VARS']['LOG'] = $this->previousLogConfiguration;
        GeneralUtility::makeInstance(LogManager::class)->reset();
        parent::tearDown();
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

    /**
     * A served MCP request: the token is validated and, as McpEndpoint does
     * once the backend user is available, extended.
     */
    private function useToken(string $accessToken): void
    {
        $tokenInfo = $this->service->validateToken($accessToken);
        $this->assertNotNull($tokenInfo, 'Token must be valid');
        $this->service->extendTokenOnUse($tokenInfo);
    }

    /**
     * Simulates "code deployed, schema update not run yet" and logs the
     * warnings to a file. Returns the log file; the column is restored in a
     * finally block by the caller via restoreLifetimeColumn().
     */
    private function dropLifetimeColumn(): string
    {
        $logFile = Environment::getVarPath() . '/log/oauth-lifetime-test.log';
        @unlink($logFile);
        $GLOBALS['TYPO3_CONF_VARS']['LOG']['Hn']['McpServer']['Service']['OAuthService']['writerConfiguration'] = [
            LogLevel::WARNING => [FileWriter::class => ['logFile' => $logFile]],
        ];
        GeneralUtility::makeInstance(LogManager::class)->reset();
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens')
            ->executeStatement('ALTER TABLE tx_mcpserver_access_tokens DROP COLUMN lifetime');
        return $logFile;
    }

    private function restoreLifetimeColumn(): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens')
            ->executeStatement('ALTER TABLE tx_mcpserver_access_tokens ADD COLUMN lifetime INTEGER DEFAULT 0 NOT NULL');
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

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidLifetimeSettingProvider(): array
    {
        return [
            'empty' => [''],
            'zero' => ['0'],
            'negative' => ['-5'],
            'not a number' => ['abc'],
        ];
    }

    #[DataProvider('invalidLifetimeSettingProvider')]
    public function testInvalidLifetimeSettingFallsBackToThirtyDays(string $value): void
    {
        $this->configure(['tokenLifetimeDays' => $value]);

        $tokenData = $this->issueTokenViaOAuthFlow();

        $this->assertSame(30 * self::DAY, $tokenData['expires_in']);
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

        $this->useToken($tokenData['access_token']);

        $this->assertSame($expiresSoon, (int)$this->latestToken()['expires']);
    }

    public function testSlidingExpirationSettingFalseStringMeansOff(): void
    {
        $this->configure(['tokenSlidingExpiration' => 'false']);
        $tokenData = $this->issueTokenViaOAuthFlow();
        $expiresSoon = time() + 100;
        $this->updateLatestToken(['expires' => $expiresSoon]);

        $this->useToken($tokenData['access_token']);

        $this->assertSame($expiresSoon, (int)$this->latestToken()['expires']);
    }

    public function testValidationAloneDoesNotExtendTheToken(): void
    {
        $this->configure(['tokenSlidingExpiration' => '1']);
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

        $this->useToken($tokenData['access_token']);

        $token = $this->latestToken();
        $this->assertSame((int)$token['last_used'] + 30 * self::DAY, (int)$token['expires']);
    }

    public function testSlidingExpirationOmitsExpiresInFromTheTokenResponse(): void
    {
        $this->configure(['tokenSlidingExpiration' => '1']);

        $tokenData = $this->issueTokenViaOAuthFlow();

        $this->assertArrayNotHasKey('expires_in', $tokenData);
        $this->assertArrayHasKey('access_token', $tokenData);
    }

    public function testBackendModuleTokenSlides(): void
    {
        $this->configure(['tokenLifetimeDays' => '30', 'tokenSlidingExpiration' => '1']);
        $accessToken = $this->service->createDirectAccessToken(1, 'backend-module');
        $this->updateLatestToken(['expires' => time() + 100]);

        $this->useToken($accessToken);

        $token = $this->latestToken();
        $this->assertSame((int)$token['last_used'] + 30 * self::DAY, (int)$token['expires']);
    }

    public function testSlidingExpirationStopsAtTheMaximumLifetime(): void
    {
        $this->configure(['tokenLifetimeDays' => '30', 'tokenSlidingExpiration' => '1', 'tokenMaxLifetimeDays' => '90']);
        $tokenData = $this->issueTokenViaOAuthFlow();
        $issuedAt = time() - 89 * self::DAY;
        $this->updateLatestToken(['crdate' => $issuedAt, 'expires' => time() + 100]);

        $this->useToken($tokenData['access_token']);

        $this->assertSame($issuedAt + 90 * self::DAY, (int)$this->latestToken()['expires']);
    }

    public function testMaximumLifetimeAlsoCapsANewToken(): void
    {
        $this->configure(['tokenLifetimeDays' => '30', 'tokenSlidingExpiration' => '1', 'tokenMaxLifetimeDays' => '7']);

        $this->issueTokenViaOAuthFlow();

        $this->assertExpiresAfterIssue(7 * self::DAY);
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
        $tokenData = $this->issueTokenViaOAuthFlow();
        $farAway = (int)$this->latestToken()['crdate'] + 365 * self::DAY;
        $this->updateLatestToken(['expires' => $farAway]);

        $this->useToken($tokenData['access_token']);

        $this->assertSame($farAway, (int)$this->latestToken()['expires']);
    }

    public function testSlidingExpirationDoesNotExtendACliTokenWithShorterExplicitTtl(): void
    {
        $this->configure(['tokenLifetimeDays' => '30', 'tokenSlidingExpiration' => '1']);
        $created = $this->service->createToken(1, 'cli', 2 * self::DAY);

        $this->useToken($created['access_token']);

        $token = $this->latestToken();
        $this->assertSame($created['expires'], (int)$token['expires']);
        $this->assertSame(0, (int)$token['lifetime'], 'An explicit TTL must be stored as a fixed expiry');
    }

    public function testSlidingExpirationDoesNotExtendACliTokenWithLongerExplicitTtl(): void
    {
        $this->configure(['tokenLifetimeDays' => '30', 'tokenSlidingExpiration' => '1']);
        $created = $this->service->createToken(1, 'cli', 365 * self::DAY);

        $this->useToken($created['access_token']);

        $token = $this->latestToken();
        $this->assertSame($created['expires'], (int)$token['expires']);
        $this->assertSame(0, (int)$token['lifetime'], 'An explicit TTL must be stored as a fixed expiry');
    }

    public function testCliTokenWithoutExplicitTtlSlidesLikeAnOAuthToken(): void
    {
        $this->configure(['tokenLifetimeDays' => '30', 'tokenSlidingExpiration' => '1']);
        $created = $this->service->createToken(1, 'cli');
        $this->updateLatestToken(['expires' => time() + 100]);

        $this->useToken($created['access_token']);

        $token = $this->latestToken();
        $this->assertSame((int)$token['last_used'] + 30 * self::DAY, (int)$token['expires']);
    }

    public function testSlidingUsesTheConfiguredLifetimeWhenItWasShortenedSinceIssue(): void
    {
        $this->configure(['tokenLifetimeDays' => '30', 'tokenSlidingExpiration' => '1']);
        $tokenData = $this->issueTokenViaOAuthFlow();
        $this->configure(['tokenLifetimeDays' => '7']);
        $this->updateLatestToken(['expires' => time() + 100]);

        $this->useToken($tokenData['access_token']);

        $token = $this->latestToken();
        $this->assertSame((int)$token['last_used'] + 7 * self::DAY, (int)$token['expires']);
    }

    public function testTokenWithoutStoredLifetimeDoesNotSlide(): void
    {
        $this->configure(['tokenSlidingExpiration' => '1']);
        $tokenData = $this->issueTokenViaOAuthFlow();
        $expiresSoon = time() + 100;
        // Like a token issued before the lifetime was stored
        $this->updateLatestToken(['lifetime' => 0, 'expires' => $expiresSoon]);

        $this->useToken($tokenData['access_token']);

        $this->assertSame($expiresSoon, (int)$this->latestToken()['expires']);
    }

    public function testHugeConfiguredLifetimeIsCappedToTheColumnRange(): void
    {
        $this->configure(['tokenLifetimeDays' => '99999999']);

        $tokenData = $this->issueTokenViaOAuthFlow();

        $token = $this->latestToken();
        $this->assertSame(self::MAX_TIMESTAMP, (int)$token['expires']);
        $this->assertSame(self::MAX_TIMESTAMP - (int)$token['crdate'], $tokenData['expires_in']);
        $this->assertNotNull($this->service->validateToken($tokenData['access_token']));
    }

    public function testHugeLifetimeIsCappedWhenSliding(): void
    {
        $this->configure(['tokenLifetimeDays' => '99999999', 'tokenSlidingExpiration' => '1']);
        $tokenData = $this->issueTokenViaOAuthFlow();
        $this->updateLatestToken(['expires' => time() + 100]);

        $this->useToken($tokenData['access_token']);

        $this->assertSame(self::MAX_TIMESTAMP, (int)$this->latestToken()['expires']);
    }

    public function testHugeExplicitCliTtlIsCappedToTheColumnRange(): void
    {
        $created = $this->service->createToken(1, 'cli', 99999999 * self::DAY);

        $this->assertSame(self::MAX_TIMESTAMP, $created['expires']);
        $this->assertSame(self::MAX_TIMESTAMP, (int)$this->latestToken()['expires']);
        $this->assertNotNull($this->service->validateToken($created['access_token']));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function issuePathProvider(): array
    {
        return [
            'OAuth flow' => ['oauth'],
            'backend module' => ['backend'],
            'CLI without TTL' => ['cli'],
        ];
    }

    #[DataProvider('issuePathProvider')]
    public function testMissingLifetimeColumnStillIssuesAFixedTokenAndLogsAWarning(string $issuePath): void
    {
        $this->configure(['tokenSlidingExpiration' => '1']);
        $logFile = $this->dropLifetimeColumn();
        try {
            $accessToken = match ($issuePath) {
                'oauth' => $this->issueTokenViaOAuthFlow()['access_token'],
                'backend' => $this->service->createDirectAccessToken(1, 'backend-module'),
                'cli' => $this->service->createToken(1, 'cli')['access_token'],
            };
            $this->updateLatestToken(['expires' => time() + 100]);
            $expiresSoon = (int)$this->latestToken()['expires'];

            $this->useToken($accessToken);

            $this->assertSame($expiresSoon, (int)$this->latestToken()['expires'], 'Without the column the token must not slide');
            $this->assertFileExists($logFile);
            $this->assertStringContainsString('extension:setup --extension=mcp_server', (string)file_get_contents($logFile));
        } finally {
            $this->restoreLifetimeColumn();
        }
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
