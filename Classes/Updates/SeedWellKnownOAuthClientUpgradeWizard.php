<?php

declare(strict_types=1);

namespace Hn\McpServer\Updates;

use Hn\McpServer\Service\OAuthService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

#[UpgradeWizard('mcpServer_seedWellKnownOAuthClient')]
class SeedWellKnownOAuthClientUpgradeWizard implements UpgradeWizardInterface
{
    private const TABLE = 'tx_mcpserver_oauth_clients';

    public function getTitle(): string
    {
        return 'MCP Server: Seed well-known OAuth client';
    }

    public function getDescription(): string
    {
        return 'Inserts the legacy "typo3-mcp-server" public client into the new '
            . 'OAuth clients table so MCP clients configured before dynamic '
            . 'registration was implemented continue to work.';
    }

    public function executeUpdate(): bool
    {
        GeneralUtility::makeInstance(OAuthService::class)->ensureWellKnownClient();
        return true;
    }

    public function updateNecessary(): bool
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(self::TABLE);

        $count = (int)$connection->createQueryBuilder()
            ->count('uid')
            ->from(self::TABLE)
            ->where('client_id = ' . $connection->quote(OAuthService::WELL_KNOWN_CLIENT_ID))
            ->executeQuery()
            ->fetchOne();

        return $count === 0;
    }

    public function getPrerequisites(): array
    {
        return [DatabaseUpdatedPrerequisite::class];
    }
}
