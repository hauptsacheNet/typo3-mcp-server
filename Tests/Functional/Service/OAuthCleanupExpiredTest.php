<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * cleanupExpired() (also behind "mcp:oauth cleanup") removes expired
 * authorization codes and marks expired tokens deleted - and nothing else.
 *
 * The range condition used to be passed to Connection::delete()/update() as an
 * equality criterion, so neither ever matched a row.
 */
class OAuthCleanupExpiredTest extends AbstractFunctionalTest
{
    private OAuthService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = GeneralUtility::makeInstance(OAuthService::class);
    }

    #[Test]
    public function expiredCodesAreRemovedAndLiveOnesKept(): void
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_mcpserver_oauth_codes');
        foreach (['expired' => time() - 60, 'live' => time() + 600] as $name => $expires) {
            $connection->insert('tx_mcpserver_oauth_codes', [
                'pid' => 0,
                'tstamp' => time(),
                'crdate' => time(),
                'code' => $name,
                'be_user_uid' => 1,
                'client_name' => $name,
                'expires' => $expires,
            ]);
        }

        $this->service->cleanupExpired();

        $this->assertSame(['live'], $connection->select(['code'], 'tx_mcpserver_oauth_codes')->fetchFirstColumn());
    }

    #[Test]
    public function expiredTokensAreMarkedDeletedAndLiveOnesKept(): void
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_mcpserver_access_tokens');
        foreach (['expired' => time() - 60, 'live' => time() + 3600] as $name => $expires) {
            $connection->insert('tx_mcpserver_access_tokens', [
                'pid' => 0, 'tstamp' => time(), 'crdate' => time(),
                'token' => hash('sha256', $name),
                'token_version' => 1,
                'be_user_uid' => 1,
                'client_name' => $name,
                'expires' => $expires,
                'last_used' => 0, 'created_ip' => '', 'last_used_ip' => '',
            ]);
        }

        $this->service->cleanupExpired();

        $deleted = $connection->select(['client_name', 'deleted'], 'tx_mcpserver_access_tokens')->fetchAllKeyValue();
        $this->assertSame(['expired' => 1, 'live' => 0], array_map('intval', $deleted));
    }
}
