<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\Exception\AccessDeniedException;
use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\McpApps;
use Hn\McpServer\MCP\Tool\AppOnlyToolInterface;
use Hn\McpServer\Service\RecordUrlService;
use Hn\McpServer\Service\WorkspaceChangeService;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Backs the change review view shown with every WriteTable call: describes the
 * unpublished change of a record and publishes or discards it on a click of the user.
 *
 * Only the view calls this tool (visibility "app"), never the model: publishing is the
 * editor's decision. The change is the one of the record's outermost inline parent, see
 * WorkspaceChangeService and Documentation/Architecture/McpApps.md.
 */
class ReviewChangeTool extends AbstractRecordTool implements AppOnlyToolInterface
{
    protected WorkspaceChangeService $changeService;

    public function __construct()
    {
        parent::__construct();
        $this->changeService = GeneralUtility::makeInstance(WorkspaceChangeService::class);
    }

    public function getSchema(): array
    {
        return [
            'description' => 'Show, publish or discard the unpublished workspace change of a record. Used by the change review view, publishing is a decision of the user.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => ['show', 'publish', 'discard'],
                        'description' => '"show" describes the change, "publish" makes it live, "discard" drops it.',
                    ],
                    'table' => [
                        'type' => 'string',
                        'description' => 'Table of the written record',
                    ],
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'Live uid of the written record, as returned by WriteTable',
                    ],
                ],
                'required' => ['action', 'table', 'uid'],
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
            ],
            '_meta' => McpApps::getToolMeta(McpApps::CHANGE_REVIEW_URI, ['app']),
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $action = (string)($params['action'] ?? 'show');
        $table = (string)($params['table'] ?? '');
        $uid = (int)($params['uid'] ?? 0);
        if (!in_array($action, ['show', 'publish', 'discard'], true)) {
            throw new ValidationException(['Invalid action: ' . $action . '. Valid actions are: show, publish, discard']);
        }
        if ($table === '' || $uid <= 0) {
            throw new ValidationException(['table and uid are required']);
        }
        $this->ensureTableAccess($table, 'read');

        $root = $this->changeService->resolveRoot($table, $uid);
        $this->ensureTableAccess($root['table'], 'read');
        $rootRecord = $this->changeService->getWorkspaceRecord($root['table'], $root['uid']);
        if ($rootRecord === null) {
            throw new ValidationException(['Record ' . $table . ':' . $uid . ' not found']);
        }
        $pid = $root['table'] === 'pages' ? (int)$rootRecord['uid'] : (int)$rootRecord['pid'];
        $backendUser = $GLOBALS['BE_USER'];
        if (!$backendUser->isAdmin() && !$backendUser->isInWebMount($pid)) {
            throw new AccessDeniedException(sprintf('You do not have access to page %d.', $pid));
        }

        $errors = match ($action) {
            'publish' => $this->changeService->canPublish($root['table'], $root['uid'])
                ? $this->changeService->publish($root['table'], $root['uid'])
                : ['You are not allowed to publish this change. An editor can publish it in the TYPO3 workspace module.'],
            'discard' => $this->changeService->discard($root['table'], $root['uid']),
            default => [],
        };
        if ($errors !== []) {
            return $this->createErrorResult(ucfirst($action) . ' failed: ' . implode(' ', $errors));
        }

        $change = $this->changeService->describe($root['table'], $root['uid']);
        if ($change === null) {
            // A discarded new record is gone.
            $change = [
                'record' => [
                    'table' => $root['table'],
                    'uid' => $root['uid'],
                    'tableLabel' => $this->getTableLabel($root['table']),
                    'title' => '',
                    'state' => WorkspaceChangeService::STATE_DELETED,
                    'fields' => [],
                    'relations' => [],
                ],
                'pending' => false,
            ];
        }

        $data = ['action' => $action] + $change + [
            'canPublish' => $change['pending'] && $this->changeService->canPublish($root['table'], $root['uid']),
            'canDiscard' => $change['pending'],
        ];
        $record = $this->changeService->getWorkspaceRecord($root['table'], $root['uid']);
        if ($record !== null) {
            $data += $this->getRecordUrls($root['table'], $record);
        }

        $result = $this->createJsonResult($data);

        return new CallToolResult($result->content, false, null, $data);
    }

    /**
     * @param array<string, mixed> $record
     * @return array{frontend_url?: string, preview_url?: string}
     */
    protected function getRecordUrls(string $table, array $record): array
    {
        try {
            return GeneralUtility::makeInstance(RecordUrlService::class)->getUrls($table, [$record])[0] ?? [];
        } catch (\Throwable $e) {
            $this->logException($e, sprintf('resolving URLs of %s:%d', $table, (int)$record['uid']));
            return [];
        }
    }
}
