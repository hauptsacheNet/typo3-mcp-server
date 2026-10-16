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
 * Query restriction to exclude live records that are superseded by a version in the current
 * workspace which lives somewhere else or not at all: delete placeholders and move pointers.
 *
 * The standard WorkspaceRestriction admits live rows plus the workspace's new records and
 * move pointers (t3ver_state=4), and expects consumers to fold versions onto live rows with
 * BackendUtility::workspaceOL(). That leaves two gaps for a workspace-transparent read:
 *
 * - Deleted in the workspace (t3ver_state=2): the delete placeholder itself is filtered, but
 *   the live row is still selected and would show up as if it still existed.
 * - Moved in the workspace (t3ver_state=4, which is also how a reorder is staged): both the
 *   live row and the move pointer are selected, so the record appears twice, and the live
 *   row carries the old pid/sorting, so it also shows up at its old position or parent.
 *
 * Excluding these live rows at query level lets the remaining rows (live, new-in-workspace
 * and move pointers) be filtered, sorted, counted and paginated by SQL in their workspace
 * state. Callers map version uids back to live uids via t3ver_oid.
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
            
            // Create a subquery to find UIDs of live records that have a delete placeholder
            // or a move pointer in the current workspace
            try {
                $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
                $subQueryBuilder = $connectionPool->getQueryBuilderForTable($tableName);
                $subQueryBuilder->getRestrictions()->removeAll();
                
                $subQuery = $subQueryBuilder
                    ->select('t3ver_oid')
                    ->from($tableName)
                    ->where(
                        $subQueryBuilder->expr()->in('t3ver_state', [
                            $subQueryBuilder->expr()->literal((string)VersionState::DELETE_PLACEHOLDER->value),
                            $subQueryBuilder->expr()->literal((string)VersionState::MOVE_POINTER->value),
                        ]),
                        $subQueryBuilder->expr()->eq('t3ver_wsid', 
                            $subQueryBuilder->expr()->literal((string)$this->workspaceId)
                        ),
                        $subQueryBuilder->expr()->gt('t3ver_oid', 
                            $subQueryBuilder->expr()->literal('0')
                        )
                    );

                // A soft-deleted version no longer supersedes its live record. This matters
                // for move pointers in particular: the restricted main query drops deleted
                // move pointers too, so excluding the live row would make the record vanish.
                $deleteField = $GLOBALS['TCA'][$tableName]['ctrl']['delete'] ?? '';
                if ($deleteField !== '') {
                    $subQuery->andWhere(
                        $subQueryBuilder->expr()->eq($deleteField, $subQueryBuilder->expr()->literal('0'))
                    );
                }
                
                // Exclude live records that are deleted or moved in the current workspace
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