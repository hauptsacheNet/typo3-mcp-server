<?php

declare(strict_types=1);

namespace Hn\McpServer\Database\Query\Restriction;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\CompositeExpression;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Versioning\VersionState;

/**
 * Query restriction to exclude live records that have delete placeholders in the current workspace.
 * 
 * This restriction ensures that when working in a workspace, records that have been "deleted"
 * (which creates a delete placeholder) are not shown in the results. This is different from
 * the standard WorkspaceRestriction which handles workspace record selection but doesn't
 * exclude live records that have delete placeholders.
 * 
 * Only applies to workspace-enabled tables.
 */
class WorkspaceDeletePlaceholderRestriction implements QueryRestrictionInterface
{
    protected int $workspaceId;

    public function __construct(int $workspaceId)
    {
        $this->workspaceId = $workspaceId;
    }

    /**
     * Main method to build expressions for given tables
     *
     * @param array $queriedTables Array of tables, where array key is table alias and value is a table name
     * @param ExpressionBuilder $expressionBuilder Expression builder instance to add restrictions with
     * @return CompositeExpression The result of query builder expression(s)
     */
    public function buildExpression(array $queriedTables, ExpressionBuilder $expressionBuilder): CompositeExpression
    {
        $constraints = [];
        
        // Only apply restriction when in a workspace (not in live)
        if ($this->workspaceId === 0) {
            return $expressionBuilder->and();
        }
        
        foreach ($queriedTables as $tableAlias => $tableName) {
            // Only apply to workspace-enabled tables
            if (empty($GLOBALS['TCA'][$tableName]['ctrl']['versioningWS'] ?? false)) {
                continue;
            }
            
            // Create a subquery to find UIDs of live records that have delete placeholders
            // in the current workspace
            try {
                $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
                $subQueryBuilder = $connectionPool->getQueryBuilderForTable($tableName);
                $subQueryBuilder->getRestrictions()->removeAll();
                
                $subQuery = $subQueryBuilder
                    ->select('t3ver_oid')
                    ->from($tableName)
                    ->where(
                        $subQueryBuilder->expr()->eq('t3ver_state', 
                            $subQueryBuilder->expr()->literal((string)VersionState::DELETE_PLACEHOLDER->value)
                        ),
                        $subQueryBuilder->expr()->eq('t3ver_wsid', 
                            $subQueryBuilder->expr()->literal((string)$this->workspaceId)
                        ),
                        $subQueryBuilder->expr()->gt('t3ver_oid', 
                            $subQueryBuilder->expr()->literal('0')
                        )
                    );
                
                // Exclude live records that have delete placeholders in current workspace
                $constraints[] = $expressionBuilder->notIn(
                    $tableAlias . '.uid',
                    $subQuery->getSQL()
                );
            } catch (\Throwable) {
                // If the subquery fails (e.g., table doesn't have workspace fields), skip this constraint
                // This provides resilience for edge cases
            }
        }
        
        return $expressionBuilder->and(...$constraints);
    }
}