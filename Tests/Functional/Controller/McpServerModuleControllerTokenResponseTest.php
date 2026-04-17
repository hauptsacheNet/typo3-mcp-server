<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Controller;

use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tests that token creation, validation, and listing work correctly
 * with hashed storage.
 */
class McpServerModuleControllerTokenResponseTest extends AbstractFunctionalTest
{
    private OAuthService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = GeneralUtility::makeInstance(OAuthService::class);
    }

    public function testCreateDirectAccessTokenReturns64CharHexString(): void
    {
        $plainToken = $this->service->createDirectAccessToken(1, 'test-client');

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            $plainToken,
            'createDirectAccessToken must return a 64-char hex string'
        );
    }

    public function testPlainTokenCanBeValidated(): void
    {
        $plainToken = $this->service->createDirectAccessToken(1, 'test-client');
        $result = $this->service->validateToken($plainToken);

        $this->assertNotNull($result, 'Plain token returned by createDirectAccessToken must validate');
        $this->assertEquals(1, $result['be_user_uid']);
        $this->assertEquals('test-client', $result['client_name']);
    }

    public function testGetUserTokensReturnsHashedTokenNotPlainToken(): void
    {
        $plainToken = $this->service->createDirectAccessToken(1, 'test-client');
        $expectedHash = hash('sha256', $plainToken);

        $tokens = $this->service->getUserTokens(1);

        $this->assertNotEmpty($tokens, 'getUserTokens must return at least one token');

        $latestToken = $tokens[0];
        $this->assertArrayHasKey('token', $latestToken, 'Token row must have a "token" column');
        $this->assertEquals(
            $expectedHash,
            $latestToken['token'],
            'The "token" field in getUserTokens must be the SHA-256 hash, not the plain token'
        );
        $this->assertNotEquals(
            $plainToken,
            $latestToken['token'],
            'getUserTokens must never expose the plain token'
        );
    }
}
