<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\Service\WorkspaceVersionService;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Publish a single workspace record to live.
 *
 * Designed to be triggered from the write_table MCP App widget's "Publish"
 * button. The LLM should never invoke this autonomously — the host must
 * keep it gated behind explicit user confirmation. DataHandler still
 * enforces TYPO3's workspace publish permissions, so unauthorised callers
 * will get an error result.
 */
class PublishRecordTool extends AbstractRecordTool
{
    protected WorkspaceVersionService $workspaceVersionService;

    public function __construct()
    {
        parent::__construct();
        $this->workspaceVersionService = GeneralUtility::makeInstance(WorkspaceVersionService::class);
    }

    public function getSchema(): array
    {
        return [
            'description' => 'Publish a single pending workspace change to live. '
                . 'Intended to be invoked from the write_table preview widget when the user '
                . 'clicks "Publish". Requires workspace publish permission on the record.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'table' => [
                        'type' => 'string',
                        'description' => 'The table name of the record to publish.',
                    ],
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'The live UID of the record (same UID write_table returned).',
                    ],
                ],
                'required' => ['table', 'uid'],
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'idempotentHint' => false,
                'destructiveHint' => true,
            ],
            // visibility=["app"] keeps this tool out of the LLM's tool list per
            // SEP-1865 — only the embedded MCP App widget can invoke it via
            // window.openai.callTool. The LLM cannot autonomously publish.
            '_meta' => [
                'ui' => [
                    'visibility' => ['app'],
                ],
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $table = (string)($params['table'] ?? '');
        $liveUid = (int)($params['uid'] ?? 0);

        if ($table === '' || $liveUid <= 0) {
            throw new ValidationException(['table and uid are required']);
        }

        $this->ensureTableAccess($table, 'write');

        $workspaceUid = $this->workspaceVersionService->resolveToWorkspaceUid($table, $liveUid);

        // resolveToWorkspaceUid returns $liveUid unchanged for two situations:
        // (a) no workspace overlay exists, or (b) it's a brand-new workspace
        // record whose workspace UID IS our externally exposed live UID. Use
        // t3ver_state on the underlying row to tell them apart.
        if ($workspaceUid === $liveUid) {
            $row = BackendUtility::getRecord($table, $liveUid, 't3ver_state,t3ver_wsid', '', false);
            $state = (int)($row['t3ver_state'] ?? 0);
            $wsid = (int)($row['t3ver_wsid'] ?? 0);
            $isNewWorkspaceRecord = $row !== null && $wsid > 0 && $state === 1;
            if (!$isNewWorkspaceRecord) {
                return $this->createJsonResult([
                    'published' => false,
                    'table' => $table,
                    'uid' => $liveUid,
                    'error' => 'No pending workspace change found for this record.',
                ]);
            }
        }

        // Mirrors WorkspaceService::getCmdArrayForPublishWS(): the cmdmap key
        // is the live id (t3ver_oid for modified records, the workspace uid
        // for new records) and `swapWith` always points at the workspace uid.
        $cmdMap = [
            $table => [
                $liveUid => [
                    'version' => [
                        'action' => 'swap',
                        'swapWith' => $workspaceUid,
                    ],
                ],
            ],
        ];

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->BE_USER = $GLOBALS['BE_USER'];
        $dataHandler->start([], $cmdMap);
        $dataHandler->process_cmdmap();

        if (!empty($dataHandler->errorLog)) {
            return $this->createJsonResult([
                'published' => false,
                'table' => $table,
                'uid' => $liveUid,
                'error' => implode('; ', $dataHandler->errorLog),
            ]);
        }

        return $this->createJsonResult([
            'published' => true,
            'table' => $table,
            'uid' => $liveUid,
        ]);
    }
}
