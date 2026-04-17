<?php

declare(strict_types=1);

namespace Hn\McpServer\Updates;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

#[UpgradeWizard('mcpServer_hashExistingTokens')]
class HashExistingTokensUpgradeWizard implements UpgradeWizardInterface
{
    private const TABLE = 'tx_mcpserver_access_tokens';

    public function getTitle(): string
    {
        return 'MCP Server: Hash existing plain-text access tokens';
    }

    public function getDescription(): string
    {
        return 'Converts plain-text OAuth access tokens to SHA-256 hashes. '
            . 'Existing tokens remain valid after migration.';
    }

    public function executeUpdate(): bool
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(self::TABLE);

        $rows = $connection->createQueryBuilder()
            ->select('uid', 'token')
            ->from(self::TABLE)
            ->where('token_version = 0')
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            $connection->update(
                self::TABLE,
                ['token' => hash('sha256', $row['token']), 'token_version' => 1],
                ['uid' => $row['uid']]
            );
        }

        return true;
    }

    public function updateNecessary(): bool
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(self::TABLE);

        // Count ALL version-0 tokens including deleted — plaintext is a data leak risk
        $count = (int)$connection->createQueryBuilder()
            ->count('uid')
            ->from(self::TABLE)
            ->where('token_version = 0')
            ->executeQuery()
            ->fetchOne();

        return $count > 0;
    }

    public function getPrerequisites(): array
    {
        return [DatabaseUpdatedPrerequisite::class];
    }
}
