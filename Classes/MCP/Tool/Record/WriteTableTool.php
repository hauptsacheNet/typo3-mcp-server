<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\Event\AfterRecordWriteEvent;
use Hn\McpServer\Event\BeforeRecordWriteEvent;
use Hn\McpServer\Exception\DatabaseException;
use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\Service\LanguageService;
use Mcp\Types\CallToolResult;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;

/**
 * Tool for writing records to TYPO3 tables
 */
class WriteTableTool extends AbstractRecordTool
{
    protected LanguageService $languageService;

    public function __construct()
    {
        parent::__construct();
        $this->languageService = GeneralUtility::makeInstance(LanguageService::class);
    }

    /**
     * Get the tool schema
     */
    public function getSchema(): array
    {
        // Get all accessible tables for enum (exclude read-only tables for write operations)
        $accessibleTables = $this->tableAccessService->getAccessibleTables(false);
        $tableNames = array_keys($accessibleTables);
        sort($tableNames); // Sort alphabetically for better readability
        
        return [
            'description' => 'Create, update, translate, move, or delete records in workspace-capable TYPO3 tables. All changes are made in workspace context and require publishing to become live. ' .
                'Language fields (sys_language_uid) accept ISO codes ("de", "fr") instead of numeric IDs. Date/time fields accept ISO 8601 strings and are auto-converted to timestamps. Slug fields are auto-normalized (leading slash ensured). ' .
                'REQUIRED PARAMETERS PER ACTION: ' .
                'create: table, pid, data. update: table, uid, data. delete: table, uid. move: table, uid, position (and optionally pid for cross-page). translate: table, uid, data (with sys_language_uid). ' .
                'INLINE RELATIONS (CRITICAL): On update, passing an inline field REPLACES ALL existing children — omitted children are deleted (embedded) or unlinked (independent). ' .
                'To keep existing children, include their UIDs: [2546, 2547, {"CType": "textmedia", "header": "New"}]. To update an existing child: {"uid": 2546, "header": "Updated"}. Order in the array defines sorting. ' .
                'Nested inline relations are supported: child record data may itself contain inline arrays. ' .
                'FLEXFORM FIELDS: Pass as JSON objects (auto-converted to XML). Use "settings.fieldName" keys for plugin settings. ' .
                'ORDERING: When creating multiple elements on a page, chain positions: create first with "bottom", then "after:{uid}" for each next. ' .
                'Before creating content, use GetPage + ReadTable to understand page structure and existing content.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'description' => 'Action to perform: "create", "update", "move", "translate", or "delete"',
                        'enum' => ['create', 'update', 'move', 'translate', 'delete'],
                    ],
                    'table' => [
                        'type' => 'string',
                        'description' => 'The table name to write records to',
                        'enum' => $tableNames,
                    ],
                    'pid' => [
                        'type' => 'integer',
                        'description' => 'Page ID — required for "create" action, optional for "move" action (target page for cross-page moves)',
                    ],
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'Record UID (required for "update" and "delete" actions)',
                    ],
                    'data' => [
                        'type' => 'object',
                        'description' => 'Record data as field-value pairs. ' .
                            'INLINE RELATIONS: For embedded tables, pass record data arrays to create new children or {"uid": N} to reference existing ones (optionally with fields to update). ' .
                            'For independent tables, pass UIDs to link. On update, the array REPLACES all children — include existing UIDs to keep them. ' .
                            'FILE FIELDS (image, media, assets): Array of sys_file UIDs [3, 4] or objects [{"uid_local": 3, "title": "...", "alternative": "...", "description": "Caption"}]. ' .
                            'SEARCH-AND-REPLACE (update only): For text/input/email/link/slug fields, pass [{"search": "old", "replace": "new"}] instead of full text. ' .
                            'Add "replaceAll": true per operation if search may match multiple times. Only these field types support search-and-replace. ' .
                            'FLEXFORM: Pass as JSON object with "settings.fieldName" keys — auto-converted to XML.',
                        'additionalProperties' => true,
                        'examples' => [
                            ['title' => 'News Title', 'bodytext' => 'News <b>content</b>', 'datetime' => '2024-01-01 10:00:00'],
                            ['header' => 'Content Element Header', 'bodytext' => 'Content <b>text</b>', 'CType' => 'text'],
                            ['sys_language_uid' => 'de', 'title' => 'German translation'],
                            ['header' => [['search' => 'Welcom', 'replace' => 'Welcome'], ['search' => 'Compnay', 'replace' => 'Company']]],
                            ['header' => 'With images', 'CType' => 'textmedia', 'assets' => [3, 4]],
                            ['image' => [['uid_local' => 5, 'title' => 'Photo', 'alternative' => 'Alt text']]],
                        ]
                    ],
                    'position' => [
                        'type' => 'string',
                        'description' => 'Position for "create" and "move" actions: "bottom" (default, append after last), "top" (prepend before first), '
                            . '"after:UID" (insert after specific element), "before:UID" (insert before specific element). '
                            . 'For "move", position is required unless moving to bottom of same page. '
                            . 'To create elements in order, chain "after:UID" with the UID from the previous response.',
                        'default' => 'bottom',
                    ],
                ],
                'required' => ['action', 'table'],
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'idempotentHint' => false
            ]
        ];
    }

    /**
     * Execute the tool logic
     */
    protected function doExecute(array $params): CallToolResult
    {
        
        // Get parameters
        $action = $params['action'] ?? '';
        $table = $params['table'] ?? '';
        $pid = isset($params['pid']) ? (int)$params['pid'] : null;
        $uid = isset($params['uid']) ? (int)$params['uid'] : null;
        $data = $params['data'] ?? [];
        $position = $params['position'] ?? 'bottom';

        // Validate parameters
        if (empty($action)) {
            throw new ValidationException(['Action is required (create, update, translate, or delete)']);
        }

        if (empty($table)) {
            throw new ValidationException(['Table name is required']);
        }

        // Validate data parameter type
        if (in_array($action, ['create', 'update', 'translate'], true) && isset($params['data'])) {
            if (!is_array($params['data'])) {
                $dataType = gettype($params['data']);
                throw new ValidationException([
                    "Invalid data parameter: Expected an object/array with field names as keys, but received {$dataType}. " .
                    "The data parameter must be an object like {\"title\": \"My Title\", \"bodytext\": \"Content\"}, " .
                    "not a plain string. Each field name should be a key with its corresponding value."
                ]);
            }
        }

        // Extract search/replace operations from data (arrays of {search, replace} objects
        // on non-inline fields are treated as search-and-replace operations)
        $searchReplace = $this->extractSearchReplaceFromData($table, $data, $action);

        /**
         * IMPORTANT FEATURE: ISO Code Support for sys_language_uid
         *
         * The WriteTableTool accepts ISO language codes (e.g., 'de', 'fr', 'en') for the
         * sys_language_uid field instead of numeric IDs. This makes it much easier for LLMs
         * to work with multilingual content without needing to know the numeric language IDs.
         *
         * Example:
         *   'sys_language_uid' => 'de'  // Will be converted to numeric ID (e.g., 1)
         *
         * This conversion happens automatically for any table that has a sys_language_uid field.
         * The available ISO codes depend on the site configuration.
         */
        // Convert sys_language_uid from ISO code to UID if present
        if (!empty($data) && isset($data['sys_language_uid']) && is_string($data['sys_language_uid'])) {
            $languageUid = $this->languageService->getUidFromIsoCode($data['sys_language_uid']);
            if ($languageUid === null) {
                throw new ValidationException(['Unknown language code: ' . $data['sys_language_uid']]);
            }
            $data['sys_language_uid'] = $languageUid;
        }

        // Validate table access using TableAccessService
        $this->ensureTableAccess($table, $action === 'delete' ? 'delete' : 'write');
        
        // Validate action-specific parameters
        switch ($action) {
            case 'create':
                if ($pid === null) {
                    throw new ValidationException(['Page ID (pid) is required for create action']);
                }
                
                if (empty($data)) {
                    throw new ValidationException(['Data is required for create action']);
                }
                break;
                
            case 'update':
                if ($uid === null) {
                    throw new ValidationException(['Record UID is required for update action']);
                }

                if (empty($data) && empty($searchReplace)) {
                    throw new ValidationException(['Data is required for update action']);
                }
                break;
                
            case 'delete':
                if ($uid === null) {
                    throw new ValidationException(['Record UID is required for delete action']);
                }
                break;
                
            case 'move':
                if ($uid === null) {
                    throw new ValidationException(['Record UID is required for move action']);
                }
                if ($pid === null && $position === 'bottom') {
                    // Same-page bottom move is a no-op, but allowed
                } elseif (!preg_match('/^(after|before):\d+$/', $position) && $position !== 'top' && $position !== 'bottom') {
                    throw new ValidationException(['Position is required for move action. Use "after:UID", "before:UID", "top", or "bottom". For cross-page moves, also provide pid.']);
                }
                break;

            case 'translate':
                if ($uid === null) {
                    throw new ValidationException(['Record UID is required for translate action']);
                }

                if (empty($data)) {
                    throw new ValidationException(['Data is required for translate action']);
                }

                if (!isset($data['sys_language_uid'])) {
                    throw new ValidationException(['sys_language_uid is required in data for translate action']);
                }
                break;

            default:
                throw new ValidationException(['Invalid action: ' . $action . '. Valid actions are: create, update, move, translate, delete']);
        }
        
        // Dispatch BeforeRecordWriteEvent — allows listeners to modify data or veto
        $eventDispatcher = GeneralUtility::makeInstance(EventDispatcherInterface::class);
        $beforeEvent = new BeforeRecordWriteEvent($table, $action, $data, $uid, $pid);
        $eventDispatcher->dispatch($beforeEvent);

        if ($beforeEvent->isVetoed()) {
            return $this->createErrorResult('Operation vetoed: ' . ($beforeEvent->getVetoReason() ?? 'No reason given'));
        }
        $data = $beforeEvent->getData();

        // Execute the action
        switch ($action) {
            case 'create':
                return $this->createRecord($table, $pid, $data, $position);
                
            case 'update':
                // Resolve search_replace into concrete field values and merge into data
                if (!empty($searchReplace)) {
                    $resolvedFields = $this->resolveSearchReplace($table, $uid, $searchReplace);
                    $data = array_merge($data, $resolvedFields);
                }
                return $this->updateRecord($table, $uid, $data);
                
            case 'move':
                return $this->moveRecord($table, $uid, $position, $pid);

            case 'delete':
                return $this->deleteRecord($table, $uid);

            case 'translate':
                // The language UID has already been converted from ISO code if needed
                $targetLanguageUid = (int)$data['sys_language_uid'];
                return $this->translateRecord($table, $uid, $targetLanguageUid);
                
            default:
                // This should never happen due to earlier validation
                throw new \LogicException('Invalid action: ' . $action);
        }
    }
    
    /**
     * Create a new record
     */
    protected function createRecord(string $table, int $pid, array $data, string $position): CallToolResult
    {
        // Pre-validate page access for non-admin users
        $pageAccessError = $this->validatePageAccess($pid);
        if ($pageAccessError !== null) {
            return $this->createErrorResult($pageAccessError);
        }

        // Ensure language field is set for language-aware tables (needed for non-admin permission checks)
        $data = $this->ensureLanguageField($table, $data);

        // Pre-validate authMode permissions (e.g., CType values) for non-admin users
        $authModeError = $this->validateAuthModePermissions($table, $data);
        if ($authModeError !== null) {
            return $this->createErrorResult($authModeError);
        }

        // Validate the data
        $validationResult = $this->validateRecordData($table, $data, 'create');
        if ($validationResult !== true) {
            return $this->createErrorResult('Validation error: ' . $validationResult);
        }
        
        // Extract inline relations and build unified dataMap
        $inlineRelations = $this->extractInlineRelations($table, $data);

        // Convert data for storage
        $data = $this->convertDataForStorage($table, $data);

        // Prepare the data array
        $newRecordData = $data;
        $newRecordData['pid'] = $pid;

        // Sorting is handled after record creation via DataHandler move commands.
        // This ensures correct sorting in all cases (live, workspace, colPos).

        // Create a unique ID for this new record
        $newId = 'NEW' . bin2hex(random_bytes(8));

        // Build unified dataMap: parent + all inline children in one structure.
        // DataHandler resolves NEW keys, sets foreign_field, and handles workspace
        // versioning atomically in a single process_datamap() call.
        $dataMap = [];
        $dataMap[$table][$newId] = $newRecordData;

        // Add inline children to the same dataMap and set CSV references on parent
        if (!empty($inlineRelations)) {
            $this->buildInlineDataMap($dataMap, $table, $newId, $pid, $inlineRelations);
        }

        // Process everything in a single DataHandler call
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->BE_USER = $GLOBALS['BE_USER'];
        $dataHandler->start($dataMap, []);
        $dataHandler->process_datamap();

        // Check for errors
        if (!empty($dataHandler->errorLog)) {
            return $this->createErrorResult('Error creating record: ' . $this->formatDataHandlerErrors($dataHandler->errorLog));
        }

        // Get the UID of the newly created parent record
        $parentUid = $dataHandler->substNEWwithIDs[$newId] ?? null;

        if (!$parentUid) {
            return $this->createErrorResult('Error creating record: No UID returned');
        }

        // Handle positioning via DataHandler move command
        $positionError = $this->applyPosition($table, $parentUid, $pid, $position);
        if ($positionError !== null) {
            $liveUid = $this->getLiveUid($table, $parentUid);
            return $this->createJsonResult([
                'action' => 'create',
                'table' => $table,
                'uid' => $liveUid,
                'warning' => 'Record created but positioning failed: ' . $positionError,
            ]);
        }
        
        // Get the live UID for workspace transparency
        $liveUid = $this->getLiveUid($table, $parentUid);

        // Dispatch AfterRecordWriteEvent
        $eventDispatcher = GeneralUtility::makeInstance(EventDispatcherInterface::class);
        $eventDispatcher->dispatch(new AfterRecordWriteEvent($table, 'create', $liveUid, $data, $pid));

        // Return the result with live UID
        return $this->createJsonResult([
            'action' => 'create',
            'table' => $table,
            'uid' => $liveUid,
        ]);
    }
    
    /**
     * Update an existing record
     */
    protected function updateRecord(string $table, int $uid, array $data): CallToolResult
    {
        // Validate the data
        $validationResult = $this->validateRecordData($table, $data, 'update', $uid);
        if ($validationResult !== true) {
            return $this->createErrorResult('Validation error: ' . $validationResult);
        }
        
        // Extract inline relations and build unified dataMap
        $inlineRelations = $this->extractInlineRelations($table, $data);

        // Convert data for storage
        $data = $this->convertDataForStorage($table, $data);

        // For translation records, add l10n_state overrides so DataHandler treats
        // explicitly updated fields as "custom" (not synced from parent)
        $data = $this->ensureL10nStateForTranslation($table, $uid, $data);

        // Resolve the live UID to workspace UID (once, used throughout)
        $workspaceUid = $this->resolveToWorkspaceUid($table, $uid);

        // Build unified dataMap: parent update + all inline children
        $dataMap = [$table => [$workspaceUid => $data]];
        $cmdMap = [];

        if (!empty($inlineRelations)) {
            // Get record's pid for creating new inline records
            $record = BackendUtility::getRecord($table, $workspaceUid, 'pid');
            $pid = $record['pid'] ?? 0;

            $this->buildInlineDataMap($dataMap, $table, $workspaceUid, $pid, $inlineRelations);

            // Sync inline relations: remove children that are no longer in the new list.
            // DataHandler's raw dataMap processing does not automatically delete absent
            // children (that's FormEngine's job), so we handle it explicitly via cmdMap.
            $this->syncInlineRelations($dataMap, $cmdMap, $table, $uid, $inlineRelations);
        }

        // Process everything in a single DataHandler call (dataMap + cmdMap)
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->BE_USER = $GLOBALS['BE_USER'];
        $dataHandler->start($dataMap, $cmdMap);
        $dataHandler->process_datamap();
        if (!empty($cmdMap)) {
            $dataHandler->process_cmdmap();
        }

        // Check for errors
        if (!empty($dataHandler->errorLog)) {
            return $this->createErrorResult('Error updating record: ' . implode(', ', $dataHandler->errorLog));
        }
        
        // Dispatch AfterRecordWriteEvent
        $eventDispatcher = GeneralUtility::makeInstance(EventDispatcherInterface::class);
        $eventDispatcher->dispatch(new AfterRecordWriteEvent($table, 'update', $uid, $data, null));

        // Return the result with the original live UID
        return $this->createJsonResult([
            'action' => 'update',
            'table' => $table,
            'uid' => $uid, // Return the live UID that was passed in
        ]);
    }
    
    /**
     * Delete a record
     */
    protected function deleteRecord(string $table, int $uid): CallToolResult
    {
        // Resolve the live UID to workspace UID
        $workspaceUid = $this->resolveToWorkspaceUid($table, $uid);
        
        // Delete the record using DataHandler
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->BE_USER = $GLOBALS['BE_USER'];
        $dataHandler->start([], [$table => [$workspaceUid => ['delete' => 1]]]);
        $dataHandler->process_cmdmap();
        
        // Check for errors
        if ($dataHandler->errorLog) {
            return $this->createErrorResult('Error deleting record: ' . implode(', ', $dataHandler->errorLog));
        }
        
        // Dispatch AfterRecordWriteEvent
        $eventDispatcher = GeneralUtility::makeInstance(EventDispatcherInterface::class);
        $eventDispatcher->dispatch(new AfterRecordWriteEvent($table, 'delete', $uid, [], null));

        return $this->createJsonResult([
            'action' => 'delete',
            'table' => $table,
            'uid' => $uid, // Return the live UID that was passed in
        ]);
    }
    
    /**
     * Move/reorder an existing record
     */
    protected function moveRecord(string $table, int $uid, string $position, ?int $targetPid = null): CallToolResult
    {
        $workspaceUid = $this->resolveToWorkspaceUid($table, $uid);
        $record = BackendUtility::getRecord($table, $workspaceUid, 'pid');
        if (!$record) {
            return $this->createErrorResult('Record not found');
        }

        // Use target pid for cross-page moves, or current pid for same-page moves
        $pid = $targetPid ?? (int)$record['pid'];

        $error = $this->applyPosition($table, $workspaceUid, $pid, $position);
        if ($error !== null) {
            return $this->createErrorResult('Error moving record: ' . $error);
        }

        return $this->createJsonResult([
            'action' => 'move',
            'table' => $table,
            'uid' => $uid,
            'pid' => $pid,
        ]);
    }

    /**
     * Apply a position to a record via DataHandler move command
     *
     * @return string|null Error message, or null on success
     */
    protected function applyPosition(string $table, int $recordUid, int $pid, string $position): ?string
    {
        if ($position === 'top') {
            // DataHandler: positive pid = first position on that page
            $cmdMap = [$table => [$recordUid => ['move' => $pid]]];
        } elseif ($position === 'bottom') {
            // Find the last record on the page and move after it
            $sortingField = $this->tableAccessService->getSortingFieldName($table);
            $currentWorkspace = $GLOBALS['BE_USER']->workspace ?? 0;
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll()
                ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
                ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $currentWorkspace));

            $orderField = $sortingField ?? 'uid';
            $lastUid = $queryBuilder
                ->select('uid')
                ->from($table)
                ->where(
                    $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, ParameterType::INTEGER)),
                    $queryBuilder->expr()->neq('uid', $queryBuilder->createNamedParameter($recordUid, ParameterType::INTEGER))
                )
                ->orderBy($orderField, 'DESC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();

            if ($lastUid) {
                // DataHandler: negative uid = after that record
                $cmdMap = [$table => [$recordUid => ['move' => -(int)$lastUid]]];
            } else {
                // No other records on page — move to first position on target page
                $cmdMap = [$table => [$recordUid => ['move' => $pid]]];
            }
        } elseif (strpos($position, 'after:') === 0) {
            // DataHandler: negative uid = after that record
            $referenceUid = (int)substr($position, 6);
            $cmdMap = [$table => [$recordUid => ['move' => -$referenceUid]]];
        } elseif (strpos($position, 'before:') === 0) {
            // DataHandler has no native "before" — find the previous sibling and insert after it,
            // or move to first position on the page if the reference is already first.
            $referenceUid = (int)substr($position, 7);
            $cmdMap = $this->buildBeforePositionCmdMap($table, $recordUid, $pid, $referenceUid);
        } else {
            // Unknown position — skip
            return null;
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->BE_USER = $GLOBALS['BE_USER'];
        $dataHandler->start([], $cmdMap);
        $dataHandler->process_cmdmap();

        if (!empty($dataHandler->errorLog)) {
            return implode(', ', $dataHandler->errorLog);
        }

        return null;
    }

    /**
     * Build a cmdMap for "before:X" positioning.
     *
     * DataHandler has no native "before" concept. We find the previous sibling of the
     * reference record (same pid, lower sorting) and insert after it. If the reference
     * is already the first record on the page, we insert at the top of the page instead.
     *
     * @return array cmdMap ready for DataHandler::start()
     */
    protected function buildBeforePositionCmdMap(string $table, int $recordUid, int $pid, int $referenceUid): array
    {
        $sortingField = $this->tableAccessService->getSortingFieldName($table);

        // Resolve reference record's pid and sorting in workspace context
        $refRecord = BackendUtility::getRecord($table, $referenceUid, 'pid' . ($sortingField ? ',' . $sortingField : ''));
        BackendUtility::workspaceOL($table, $refRecord);

        $refPid = $refRecord ? (int)$refRecord['pid'] : $pid;
        $refSorting = $sortingField && $refRecord ? (int)$refRecord[$sortingField] : 0;

        if ($sortingField && $refSorting > 0) {
            // Find the record just before the reference in sort order on the same page
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll()
                ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

            $prevUid = $queryBuilder
                ->select('uid')
                ->from($table)
                ->where(
                    $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($refPid, ParameterType::INTEGER)),
                    $queryBuilder->expr()->lt($sortingField, $queryBuilder->createNamedParameter($refSorting, ParameterType::INTEGER)),
                    $queryBuilder->expr()->neq('uid', $queryBuilder->createNamedParameter($recordUid, ParameterType::INTEGER))
                )
                ->orderBy($sortingField, 'DESC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();

            if ($prevUid) {
                // Insert after the previous sibling
                return [$table => [$recordUid => ['move' => -(int)$prevUid]]];
            }
        }

        // No previous sibling (or no sorting field) — insert as first on the page
        return [$table => [$recordUid => ['move' => $refPid]]];
    }

    /**
     * Translate a record to another language
     */
    protected function translateRecord(string $table, int $uid, int $targetLanguageUid): CallToolResult
    {
        // Check if table supports translations
        $languageField = $this->tableAccessService->getLanguageFieldName($table);
        if (!$languageField) {
            return $this->createErrorResult('Table ' . $table . ' does not support translations');
        }

        // Check if translation parent field exists
        $translationParentField = $this->tableAccessService->getTranslationParentFieldName($table);
        if (!$translationParentField) {
            return $this->createErrorResult('Table ' . $table . ' does not have a translation parent field configured');
        }

        // Get the record to be translated
        $record = BackendUtility::getRecord($table, $uid);
        if (!$record) {
            return $this->createErrorResult('Record not found');
        }

        // Check if this is already a translation
        if (!empty($record[$translationParentField]) && $record[$translationParentField] > 0) {
            return $this->createErrorResult('Cannot translate a record that is already a translation. Translate the original record instead.');
        }

        // Check if translation already exists
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);

        $existingTranslation = $queryBuilder
            ->select('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq($translationParentField, $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq($languageField, $queryBuilder->createNamedParameter($targetLanguageUid, ParameterType::INTEGER))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        if ($existingTranslation) {
            $targetIsoCode = $this->languageService->getIsoCodeFromUid($targetLanguageUid) ?? $targetLanguageUid;
            return $this->createErrorResult('Translation already exists for language "' . $targetIsoCode . '" (UID: ' . $existingTranslation . ')');
        }

        // Use DataHandler to create the translation
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->BE_USER = $GLOBALS['BE_USER'];

        // Use the localize command to create a translation
        $cmdMap = [
            $table => [
                $uid => [
                    'localize' => $targetLanguageUid
                ]
            ]
        ];

        $dataHandler->start([], $cmdMap);
        $dataHandler->process_cmdmap();

        // Check for errors
        if (!empty($dataHandler->errorLog)) {
            return $this->createErrorResult('Error creating translation: ' . implode(', ', $dataHandler->errorLog));
        }

        // Get the UID of the newly created translation
        $newTranslationUid = null;
        if (isset($dataHandler->copyMappingArray[$table][$uid])) {
            $newTranslationUid = $dataHandler->copyMappingArray[$table][$uid];
        }

        if (!$newTranslationUid) {
            // Try to find the translation we just created
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable($table);

            $newTranslationUid = $queryBuilder
                ->select('uid')
                ->from($table)
                ->where(
                    $queryBuilder->expr()->eq($translationParentField, $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)),
                    $queryBuilder->expr()->eq($languageField, $queryBuilder->createNamedParameter($targetLanguageUid, ParameterType::INTEGER))
                )
                ->orderBy('uid', 'DESC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();
        }

        $targetIsoCode = $this->languageService->getIsoCodeFromUid($targetLanguageUid) ?? $targetLanguageUid;

        return $this->createJsonResult([
            'action' => 'translate',
            'table' => $table,
            'sourceUid' => $uid,
            'translationUid' => $newTranslationUid ?: 'Translation created but UID not found',
            'targetLanguage' => $targetIsoCode,
        ]);
    }

    /**
     * Validate record data against TCA
     * 
     * @param int|null $uid Record UID (required for update actions)
     * @return true|string True if valid, error message if invalid
     */
    protected function validateRecordData(string $table, array &$data, string $action, ?int $uid = null)
    {
        // Table access has already been validated by ensureTableAccess() before this method is called
        // No need to re-check table existence here
        
        // Special handling for uid and pid
        if (isset($data['uid'])) {
            return "Field 'uid' cannot be modified directly";
        }
        if (isset($data['pid']) && $action !== 'create') {
            return "Field 'pid' can only be set during record creation";
        }
        
        // Validate and convert field values
        foreach ($data as $fieldName => $value) {
            $fieldConfig = $this->tableAccessService->getFieldConfig($table, $fieldName);
            if (!$fieldConfig) {
                continue;
            }

            // Check if field is accessible (filters out inaccessible inline relations)
            if (!$this->tableAccessService->canAccessField($table, $fieldName)) {
                return "Field '{$fieldName}' is not accessible";
            }

            // Validate field value
            $validationError = $this->tableAccessService->validateFieldValue($table, $fieldName, $value);
            if ($validationError !== null) {
                return $validationError;
            }

            // Handle date/time fields - convert ISO 8601 to timestamp for TYPO3
            if (!empty($fieldConfig['config']['eval'])) {
                $evalRules = GeneralUtility::trimExplode(',', $fieldConfig['config']['eval'], true);
                if (array_intersect(['date', 'datetime', 'time'], $evalRules)) {
                    if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value)) {
                        try {
                            $dateTime = new \DateTime($value);
                            $data[$fieldName] = $dateTime->getTimestamp();
                        } catch (\Exception $e) {
                            // Log the error but let DataHandler handle the invalid date
                            $this->logException($e, 'parsing date value');
                        }
                    }
                }
            }

            // Validate inline and file field types (file fields are inline to sys_file_reference)
            if (in_array($fieldConfig['config']['type'], ['inline', 'file'], true)) {
                // Validate inline relation data
                $validationError = $this->validateInlineRelationData($fieldConfig, $value);
                if ($validationError !== null) {
                    return "Field '{$fieldName}': " . $validationError;
                }
                continue;
            }
            // Convert arrays to comma-separated strings for multi-value fields
            elseif (is_array($value)) {
                $fieldType = $fieldConfig['config']['type'] ?? '';
                if (in_array($fieldType, ['select', 'category']) || 
                    ($fieldType === 'group' && !empty($fieldConfig['config']['multiple']))) {
                    $data[$fieldName] = implode(',', array_map('strval', $value));
                }
            }
        }
        
        // After validating all field values, check field availability based on record type
        // This ensures type field validation happens first
        $recordType = '';
        $typeField = $this->tableAccessService->getTypeFieldName($table);
        if ($typeField) {
            if ($action === 'update' && $uid !== null) {
                // For updates, fetch the current record type
                $currentRecord = BackendUtility::getRecord($table, $uid, $typeField);
                if ($currentRecord && isset($currentRecord[$typeField])) {
                    $recordType = (string)$currentRecord[$typeField];
                }
                // If type is being changed in the update, use the new type
                if (isset($data[$typeField])) {
                    $recordType = (string)$data[$typeField];
                }
            } else {
                // For creates, get type from data
                $recordType = isset($data[$typeField]) ? (string)$data[$typeField] : '';
            }
        }
        
        // Get available fields for this record type
        $availableFields = $this->tableAccessService->getAvailableFields($table, $recordType);
        
        // The type field itself should always be available if it exists
        if ($typeField) {
            $typeFieldConfig = $this->tableAccessService->getFieldConfig($table, $typeField);
            if ($typeFieldConfig) {
                $availableFields[$typeField] = $typeFieldConfig;
            }
        }
        
        // If we have type-specific configuration, validate field availability
        if (!empty($availableFields) || !empty($typeField)) {
            // Check each field in data is available
            foreach ($data as $fieldName => $value) {
                // Skip fields that don't exist in TCA (already validated above)
                if (!$this->tableAccessService->getFieldConfig($table, $fieldName)) {
                    continue;
                }
                
                // Special handling for FlexForm fields which are dynamically added
                if ($this->isFlexFormField($table, $fieldName)) {
                    // FlexForm fields are valid if they exist in TCA, even if not in showitem
                    continue;
                }
                
                // Special handling for passthrough fields (often used for inline relations)
                $fieldConfig = $this->tableAccessService->getFieldConfig($table, $fieldName);
                if ($fieldConfig && isset($fieldConfig['config']['type']) && $fieldConfig['config']['type'] === 'passthrough') {
                    // Passthrough fields are valid if they exist in TCA, even if not in showitem
                    // Example: tx_news_related_news stores the foreign key for inline relations
                    continue;
                }
                
                
                // If we have available fields configured and this field is not in the list
                if (!empty($availableFields) && !isset($availableFields[$fieldName])) {
                    return "Field '{$fieldName}' is not available for this record type";
                }
            }
        }
        
        return true;
    }
    
    
    /**
     * Extract inline relations from data array.
     *
     * Removes inline/file fields from $data and returns them separately
     * so they can be processed via buildInlineDataMap().
     */
    protected function extractInlineRelations(string $table, array &$data): array
    {
        $inlineRelations = [];

        if (!isset($GLOBALS['TCA'][$table]['columns'])) {
            return $inlineRelations;
        }

        foreach ($data as $fieldName => $value) {
            $fieldConfig = $this->tableAccessService->getFieldConfig($table, $fieldName);
            $fieldType = $fieldConfig['config']['type'] ?? '';
            // Handle both inline and file fields (file is inline to sys_file_reference)
            if ($fieldConfig && in_array($fieldType, ['inline', 'file'], true)) {
                $config = $fieldConfig['config'];
                // For file fields, ensure foreign_table defaults to sys_file_reference
                if ($fieldType === 'file' && empty($config['foreign_table'])) {
                    $config['foreign_table'] = 'sys_file_reference';
                }
                if ($fieldType === 'file' && empty($config['foreign_field'])) {
                    $config['foreign_field'] = 'uid_foreign';
                }
                $inlineRelations[$fieldName] = [
                    'config' => $config,
                    'value' => $value
                ];
                // Remove from data array as we'll process it via buildInlineDataMap
                unset($data[$fieldName]);
            }
        }

        return $inlineRelations;
    }

    /**
     * Build a unified DataHandler dataMap for parent + all inline children.
     *
     * Instead of creating parent and children in separate DataHandler calls,
     * this builds a single dataMap where the parent's inline field is set to
     * a comma-separated list of child NEW keys (or existing UIDs). DataHandler
     * natively resolves these references, sets foreign_field values, handles
     * workspace versioning, and manages relation sync — all atomically.
     *
     * @param array &$dataMap The dataMap to add inline children to (parent entry must already exist)
     * @param string $parentTable The parent table name
     * @param string|int $parentId The parent's key in the dataMap (NEW key or existing UID)
     * @param int $pid Page ID for new child records
     * @param array $inlineRelations Extracted inline relations from extractInlineRelations()
     */
    protected function buildInlineDataMap(
        array &$dataMap,
        string $parentTable,
        $parentId,
        int $pid,
        array $inlineRelations
    ): void {
        foreach ($inlineRelations as $fieldName => $relationData) {
            $config = $relationData['config'];
            $value = $relationData['value'];
            $foreignTable = $config['foreign_table'] ?? '';
            $foreignField = $config['foreign_field'] ?? '';

            if (empty($foreignTable) || empty($foreignField)) {
                continue;
            }

            $isFileReference = ($foreignTable === 'sys_file_reference');

            // Build the list of child identifiers (NEW keys for new records, UIDs for existing)
            $childIdentifiers = [];

            foreach ($value as $index => $item) {
                if (is_array($item) && isset($item['uid']) && is_numeric($item['uid']) && (int)$item['uid'] > 0) {
                    // Existing record reference via {"uid": N, ...} — keep or update
                    $existingUid = (int)$item['uid'];
                    unset($item['uid'], $item[$foreignField]);

                    // If additional fields provided, add as update to dataMap
                    if (!empty($item)) {
                        $dataMap[$foreignTable][$existingUid] = $item;
                    }

                    $childIdentifiers[] = $existingUid;
                } elseif (is_array($item)) {
                    // Embedded record data — create a new child record
                    $childNewId = 'NEW' . bin2hex(random_bytes(8));

                    // Remove foreign field — DataHandler sets it via inline parent context
                    unset($item[$foreignField]);
                    $item['pid'] = $pid;

                    // For sys_file_reference, force context fields server-side (don't trust client)
                    if ($isFileReference) {
                        $item['tablenames'] = $parentTable;
                        $item['fieldname'] = $fieldName;
                        $item['table_local'] = 'sys_file';
                    }

                    // Recursively handle nested inline relations in the child record
                    $nestedInlineRelations = $this->extractInlineRelations($foreignTable, $item);
                    if (!empty($nestedInlineRelations)) {
                        // Add child to dataMap first so buildInlineDataMap can reference it
                        $dataMap[$foreignTable][$childNewId] = $item;
                        $this->buildInlineDataMap($dataMap, $foreignTable, $childNewId, $pid, $nestedInlineRelations);
                    } else {
                        $dataMap[$foreignTable][$childNewId] = $item;
                    }

                    $childIdentifiers[] = $childNewId;
                } elseif (is_numeric($item) && (int)$item > 0) {
                    if ($isFileReference) {
                        // File reference shorthand: plain UID means uid_local (sys_file UID)
                        $childNewId = 'NEW' . bin2hex(random_bytes(8));
                        $dataMap[$foreignTable][$childNewId] = [
                            'uid_local' => (int)$item,
                            'pid' => $pid,
                            'tablenames' => $parentTable,
                            'fieldname' => $fieldName,
                            'table_local' => 'sys_file',
                        ];
                        $childIdentifiers[] = $childNewId;
                    } else {
                        // Existing UID — reference it directly
                        $childIdentifiers[] = (int)$item;
                    }
                }
                // Invalid items were already caught by validateInlineRelationData
            }

            // Set the inline field on the parent to the CSV of child identifiers.
            // DataHandler resolves NEW keys, sets foreign_field values, and handles
            // workspace versioning. For creates, this is sufficient. For updates,
            // syncInlineRelations() must also be called to delete absent children
            // (DataHandler's raw dataMap does not handle relation sync automatically).
            $dataMap[$parentTable][$parentId][$fieldName] = implode(',', $childIdentifiers);
        }
    }

    /**
     * Sync inline relations on update: delete/unlink children absent from the new list.
     *
     * DataHandler's raw dataMap processing does not automatically remove children
     * that are no longer referenced (that behavior is part of FormEngine, not DataHandler).
     * This method explicitly builds cmdMap entries to delete embedded children (hideTable)
     * or unlink independent children (clear foreign_field) that are absent from the new list.
     *
     * @param array &$dataMap The dataMap (read to extract new child identifiers per field)
     * @param array &$cmdMap The cmdMap to add deletion commands to
     * @param string $parentTable The parent table name
     * @param int $parentLiveUid The parent's live UID (used to query existing children)
     * @param array $inlineRelations The extracted inline relations
     */
    protected function syncInlineRelations(
        array &$dataMap,
        array &$cmdMap,
        string $parentTable,
        int $parentLiveUid,
        array $inlineRelations
    ): void {
        foreach ($inlineRelations as $fieldName => $relationData) {
            $config = $relationData['config'];
            $foreignTable = $config['foreign_table'] ?? '';
            $foreignField = $config['foreign_field'] ?? '';

            if (empty($foreignTable) || empty($foreignField)) {
                continue;
            }

            // Determine which existing UIDs are in the new list
            $newChildUids = [];
            // Read the parent's inline field CSV from the dataMap
            foreach ($dataMap[$parentTable] as $parentData) {
                if (isset($parentData[$fieldName])) {
                    $csv = (string)$parentData[$fieldName];
                    if ($csv !== '') {
                        foreach (explode(',', $csv) as $identifier) {
                            // Only collect numeric UIDs (skip NEW keys — those are new records)
                            if (is_numeric($identifier)) {
                                $newChildUids[] = (int)$identifier;
                            }
                        }
                    }
                }
            }

            // Query existing children from database.
            // Use DeletedRestriction only (not WorkspaceRestriction) so we get
            // live records with their live UIDs. This avoids the mismatch where
            // WorkspaceRestriction returns workspace overlay UIDs that don't match
            // the live UIDs in $newChildUids.
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable($foreignTable);
            $queryBuilder->getRestrictions()
                ->removeAll()
                ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

            $existingChildren = $queryBuilder
                ->select('uid')
                ->from($foreignTable)
                ->where(
                    $queryBuilder->expr()->eq(
                        $foreignField,
                        $queryBuilder->createNamedParameter($parentLiveUid, ParameterType::INTEGER)
                    ),
                    // Only live records (not workspace overlays which have t3ver_oid > 0)
                    $queryBuilder->expr()->eq('t3ver_oid', 0)
                )
                ->executeQuery()
                ->fetchAllAssociative();

            // Find children that should be removed (exist in DB but not in new list)
            $foreignTableTCA = $GLOBALS['TCA'][$foreignTable] ?? [];
            $isEmbeddedTable = !empty($foreignTableTCA['ctrl']['hideTable']);

            foreach ($existingChildren as $existingChild) {
                $childLiveUid = (int)$existingChild['uid'];
                if (!in_array($childLiveUid, $newChildUids, true)) {
                    if ($isEmbeddedTable) {
                        // Embedded (hideTable) children: delete via DataHandler cmdMap.
                        // DataHandler handles workspace versioning (creates delete placeholder).
                        $cmdMap[$foreignTable][$childLiveUid]['delete'] = 1;
                    } else {
                        // Independent children: clear foreign_field via DataHandler dataMap.
                        // DataHandler handles workspace versioning (creates workspace overlay).
                        $dataMap[$foreignTable][$childLiveUid] = [$foreignField => 0];
                    }
                }
            }
        }
    }

    /**
     * Validate inline relation data
     */
    protected function validateInlineRelationData(array $fieldConfig, $value): ?string
    {
        // Check if value is an array
        if (!is_array($value)) {
            return 'Inline relation field must be an array of UIDs or record data';
        }

        // Get foreign table
        $foreignTable = $fieldConfig['config']['foreign_table'] ?? '';
        if (empty($foreignTable)) {
            return 'Invalid inline relation configuration: missing foreign_table';
        }

        $isFileReference = ($foreignTable === 'sys_file_reference');

        // Validate each item - accept both record data arrays and UIDs
        foreach ($value as $index => $item) {
            if ($isFileReference) {
                // File references accept either plain UIDs (shorthand) or record data arrays
                if (is_numeric($item) && (int)$item > 0) {
                    continue;
                }
                if (is_array($item)) {
                    if (empty($item['uid_local']) || !is_numeric($item['uid_local'])) {
                        return 'File reference at index ' . $index . ' must contain uid_local (sys_file UID)';
                    }
                    continue;
                }
                return 'File reference at index ' . $index . ' must be a sys_file UID or an object with uid_local';
            } elseif (is_array($item)) {
                // Record data arrays for embedded inline relations
                if (empty($item)) {
                    return 'Embedded inline relation record at index ' . $index . ' is empty';
                }
            } elseif (is_numeric($item) && $item > 0) {
                // UIDs for independent inline relations
                continue;
            } else {
                return 'Inline relation at index ' . $index . ' must be a record data array or a positive integer UID';
            }
        }

        return null;
    }
    
    /**
     * Check if a field is a FlexForm field
     */
    protected function isFlexFormField(string $table, string $fieldName): bool
    {
        return $this->tableAccessService->isFlexFormField($table, $fieldName);
    }
    
    /**
     * Extract search-and-replace operations from the data array.
     *
     * When a non-inline field value is an array of objects with 'search' and 'replace' keys,
     * it's treated as search-and-replace operations instead of a direct value assignment.
     * These are extracted from the data array and returned separately.
     *
     * @param string $table Table name
     * @param array &$data Data array (modified in place to remove search/replace entries)
     * @param string $action Current action (search/replace only valid for 'update')
     * @return array Map of field name => array of search/replace operations
     * @throws ValidationException If search/replace used in non-update action or operations are invalid
     */
    protected function extractSearchReplaceFromData(string $table, array &$data, string $action): array
    {
        $searchReplace = [];

        foreach ($data as $fieldName => $value) {
            if (!is_array($value)) {
                continue;
            }

            // Check if this is an inline/file relation field — those genuinely use arrays
            $fieldConfig = $this->tableAccessService->getFieldConfig($table, $fieldName);
            if ($fieldConfig && in_array($fieldConfig['config']['type'] ?? '', ['inline', 'file'], true)) {
                continue;
            }

            // Check if this looks like search/replace operations:
            // sequential array of objects with 'search' and 'replace' keys
            if (!$this->isSearchReplaceArray($value)) {
                continue;
            }

            // Validate action — search/replace only works for update
            if ($action !== 'update') {
                throw new ValidationException(["Search-and-replace operations in data are only supported for the \"update\" action (field '{$fieldName}')"]);
            }

            // Validate each operation
            foreach ($value as $index => $operation) {
                if ($operation['search'] === '') {
                    throw new ValidationException(["Field '{$fieldName}' search-and-replace operation at index {$index} has an empty search string"]);
                }
            }

            $searchReplace[$fieldName] = $value;
            unset($data[$fieldName]);
        }

        return $searchReplace;
    }

    /**
     * Check if a value looks like an array of search/replace operations.
     *
     * Returns true if the value is a non-empty sequential array where every item
     * is an associative array with at least 'search' (string) and 'replace' (string) keys.
     */
    protected function isSearchReplaceArray(array $value): bool
    {
        if (empty($value)) {
            return false;
        }

        // Must be a sequential (non-associative) array
        if (array_keys($value) !== range(0, count($value) - 1)) {
            return false;
        }

        foreach ($value as $item) {
            if (!is_array($item)) {
                return false;
            }
            if (!isset($item['search']) || !is_string($item['search'])) {
                return false;
            }
            if (!array_key_exists('replace', $item) || !is_string($item['replace'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve search_replace operations into concrete field values.
     *
     * Fetches the current record (workspace-aware), validates field types,
     * applies search-and-replace operations sequentially, and returns
     * the resolved field values ready to merge into the data array.
     *
     * @param string $table Table name
     * @param int $uid Live record UID
     * @param array $searchReplace Map of field name => array of operations
     * @return array Resolved field values (field name => new value)
     * @throws ValidationException If a field is not a string type or search string is not found/ambiguous
     */
    protected function resolveSearchReplace(string $table, int $uid, array $searchReplace): array
    {
        // String-storable TCA field types that support search_replace
        $stringFieldTypes = ['input', 'text', 'email', 'link', 'slug', 'color'];

        // Collect all field names we need to fetch
        $fieldNames = array_keys($searchReplace);

        // Validate all fields exist, are accessible, and are string-type before fetching the record.
        // Field access MUST be checked before any DB read to prevent information disclosure
        // via search/replace error messages ("not found" / "found N times").
        foreach ($fieldNames as $fieldName) {
            $fieldConfig = $this->tableAccessService->getFieldConfig($table, $fieldName);
            if (!$fieldConfig) {
                throw new ValidationException(["search_replace field '{$fieldName}' does not exist in table '{$table}'"]);
            }
            if (!$this->tableAccessService->canAccessField($table, $fieldName)) {
                throw new ValidationException(["Field '{$fieldName}' is not accessible"]);
            }
            $fieldType = $fieldConfig['config']['type'] ?? '';
            if (!in_array($fieldType, $stringFieldTypes, true)) {
                throw new ValidationException(["search_replace is not supported for field '{$fieldName}' (type: {$fieldType}). Only string fields (text, input, etc.) are supported."]);
            }
        }

        // Fetch full record with workspace overlay to get the current workspace version data,
        // which is what the LLM sees from ReadTable output.
        // We fetch all fields because workspaceOL needs uid and workspace metadata fields.
        $record = BackendUtility::getRecord($table, $uid);
        if (!$record) {
            throw new ValidationException(["Record {$uid} not found in table '{$table}'"]);
        }
        BackendUtility::workspaceOL($table, $record);

        $resolved = [];
        foreach ($searchReplace as $fieldName => $operations) {
            $currentValue = (string)($record[$fieldName] ?? '');

            foreach ($operations as $index => $operation) {
                $search = $operation['search'];
                $replaceAll = !empty($operation['replaceAll']);
                $replace = $operation['replace'];

                $count = substr_count($currentValue, $search);

                if ($count === 0) {
                    throw new ValidationException(["search_replace field '{$fieldName}' operation {$index}: Search string not found in current field value"]);
                }

                if ($count > 1 && !$replaceAll) {
                    throw new ValidationException(["search_replace field '{$fieldName}' operation {$index}: Search string found {$count} times, must be unique. Set replaceAll to true to replace all occurrences."]);
                }

                if ($replaceAll) {
                    $currentValue = str_replace($search, $replace, $currentValue);
                } else {
                    // Replace only the first (and only) occurrence
                    $pos = strpos($currentValue, $search);
                    $currentValue = substr_replace($currentValue, $replace, $pos, strlen($search));
                }
            }

            $resolved[$fieldName] = $currentValue;
        }

        return $resolved;
    }

    /**
     * Convert data for storage
     */
    protected function convertDataForStorage(string $table, array $data): array
    {
        // Process each field
        foreach ($data as $fieldName => $value) {
            // Skip null values
            if ($value === null) {
                continue;
            }

            // Normalize slug fields: trim all slashes, then prepend exactly one.
            // TYPO3's SlugNormalizer preserves trailing slashes if present in the input,
            // but the frontend routing always strips them. LLMs commonly produce slugs
            // with trailing slashes or missing leading slashes, so we normalize here.
            // The root page slug "/" is handled correctly: trim('/', '/') = '' → '/' + '' = '/'.
            $fieldConfig = $this->tableAccessService->getFieldConfig($table, $fieldName);
            if ($fieldConfig && ($fieldConfig['config']['type'] ?? '') === 'slug' && is_string($value)) {
                $data[$fieldName] = '/' . trim($value, '/');
            }

            // Handle FlexForm fields
            if ($this->isFlexFormField($table, $fieldName)) {
                // If the value is already a string (XML), keep it as is
                if (is_string($value) && strpos($value, '<?xml') === 0) {
                    continue;
                }
                
                // If the value is an array or JSON string, convert it to XML
                $flexFormArray = is_array($value) ? $value : (is_string($value) && strpos($value, '{') === 0 ? json_decode($value, true) : null);
                
                if (is_array($flexFormArray)) {
                    // Prepare the data structure for TYPO3's XML conversion
                    $flexFormData = [
                        'data' => [
                            'sDEF' => [
                                'lDEF' => []
                            ]
                        ]
                    ];
                    
                    // Process settings fields
                    if (isset($flexFormArray['settings']) && is_array($flexFormArray['settings'])) {
                        foreach ($flexFormArray['settings'] as $settingKey => $settingValue) {
                            $flexFormData['data']['sDEF']['lDEF']['settings.' . $settingKey]['vDEF'] = $settingValue;
                        }
                    }
                    
                    // Process other fields
                    foreach ($flexFormArray as $key => $val) {
                        if ($key !== 'settings' && !is_array($val)) {
                            $flexFormData['data']['sDEF']['lDEF'][$key]['vDEF'] = $val;
                        }
                    }
                    
                    // Use TYPO3's GeneralUtility::array2xml to convert the array to XML
                    $xml = '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>' . "\n";
                    $xml .= GeneralUtility::array2xml($flexFormData, '', 0, 'T3FlexForms');
                    
                    $data[$fieldName] = $xml;
                }
            }
        }
        
        return $data;
    }
    
    /**
     * For translation records, set l10n_state to "custom" for fields that
     * have allowLanguageSynchronization enabled and are being explicitly updated.
     *
     * Without this, DataHandler's DataMapProcessor would sync these fields from
     * the default language record and silently discard the values the MCP client sent.
     *
     * This uses the same mechanism as TYPO3's FormEngine: passing l10n_state as an
     * array in the dataMap. DataMapProcessor's DataMapItem::buildState() reads the
     * persisted l10n_state JSON from the database first, then merges incoming array
     * values on top (see DataMapItem::buildState step 4).
     */
    protected function ensureL10nStateForTranslation(string $table, int $uid, array $data): array
    {
        $translationParentField = $this->tableAccessService->getTranslationParentFieldName($table);
        if (!$translationParentField) {
            return $data;
        }

        // $uid is already the workspace UID (resolved by the caller)
        $record = BackendUtility::getRecord($table, $uid, $translationParentField);
        if (!$record || empty($record[$translationParentField])) {
            // Not a translation — nothing to do
            return $data;
        }

        $columns = $GLOBALS['TCA'][$table]['columns'] ?? [];
        $l10nStateOverrides = [];

        foreach ($data as $fieldName => $_value) {
            $behaviour = $columns[$fieldName]['config']['behaviour'] ?? [];
            if (!empty($behaviour['allowLanguageSynchronization'])) {
                $l10nStateOverrides[$fieldName] = 'custom';
            }
        }

        if (!empty($l10nStateOverrides)) {
            // Pass as array — DataMapProcessor merges this on top of the DB value,
            // exactly like FormEngine's LocalizationStateSelector does.
            $data['l10n_state'] = $l10nStateOverrides;
        }

        return $data;
    }

    /**
     * Get the live UID for a workspace record
     * For workspace records, this returns the t3ver_oid (original/live UID)
     * For new records (placeholders), this returns the placeholder UID
     */
    protected function getLiveUid(string $table, int $workspaceUid): int
    {
        // If we're in live workspace, the UID is already the live UID
        $currentWorkspace = $GLOBALS['BE_USER']->workspace ?? 0;
        if ($currentWorkspace === 0) {
            return $workspaceUid;
        }
        
        // Look up the record to get its t3ver_oid
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);
        
        $queryBuilder->getRestrictions()->removeAll();
        
        $record = $queryBuilder
            ->select('t3ver_oid', 't3ver_state', 't3ver_wsid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($workspaceUid, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAssociative();
        
        if (!$record) {
            // Record not found, return the original UID
            return $workspaceUid;
        }
        
        // If this is a workspace record with an original, return the original UID
        if ($record['t3ver_oid'] > 0) {
            return (int)$record['t3ver_oid'];
        }
        
        // For new records (t3ver_state = 1), the workspace UID IS the UID we should use
        // New records don't have a live counterpart until published
        if ($record['t3ver_state'] == 1) {
            return $workspaceUid;
        }
        
        // Default: return the workspace UID
        return $workspaceUid;
    }
    
    /**
     * Resolve a live UID to its workspace version
     * Used for update/delete operations where we receive a live UID but need the workspace version
     */
    protected function resolveToWorkspaceUid(string $table, int $liveUid): int
    {
        $currentWorkspace = $GLOBALS['BE_USER']->workspace ?? 0;
        
        // If we're in live workspace, no resolution needed
        if ($currentWorkspace === 0) {
            return $liveUid;
        }
        
        // Use BackendUtility to get the workspace version
        $record = BackendUtility::getRecord($table, $liveUid);
        if (!$record) {
            return $liveUid;
        }
        
        // Let BackendUtility handle the workspace overlay
        BackendUtility::workspaceOL($table, $record);
        
        // If we got a different UID, that's the workspace version
        if (isset($record['_ORIG_uid']) && $record['_ORIG_uid'] != $liveUid) {
            return (int)$record['uid'];
        }
        
        return $liveUid;
    }

    /**
     * Ensure sys_language_uid is set for language-aware tables.
     * Non-admin users require this field to be set for language permission checks.
     *
     * Note: This method only adds the language field if it's not already set and
     * the table supports it. The validation step will catch if the field is not
     * available for the specific record type.
     *
     * @param string $table Table name
     * @param array $data Record data
     * @return array Modified data with sys_language_uid if needed
     */
    protected function ensureLanguageField(string $table, array $data): array
    {
        // Only modify data for non-admin users who need this for permission checks
        $beUser = $GLOBALS['BE_USER'];
        if ($beUser->isAdmin()) {
            return $data;
        }

        $languageField = $this->tableAccessService->getLanguageFieldName($table);

        // If table has no language field, nothing to do
        if ($languageField === null) {
            return $data;
        }

        // If language field is already set, keep it
        if (isset($data[$languageField])) {
            return $data;
        }

        // Get the type field to check if language field is available for this type
        $typeFieldName = $this->tableAccessService->getTypeFieldName($table);
        $type = '';
        if ($typeFieldName !== null && isset($data[$typeFieldName])) {
            $type = (string)$data[$typeFieldName];
        }

        // Check if the language field is actually available for this record type
        if (!$this->tableAccessService->canAccessField($table, $languageField, $type)) {
            // Language field is not available for this type, don't add it
            return $data;
        }

        // Default to default language (0) for create operations
        $data[$languageField] = 0;

        return $data;
    }

    /**
     * Validate that the current user has access to the target page.
     * This checks webmounts for non-admin users.
     *
     * @param int $pid Target page ID
     * @return string|null Error message if access denied, null if access granted
     */
    protected function validatePageAccess(int $pid): ?string
    {
        $beUser = $GLOBALS['BE_USER'];

        // Admin users have access to all pages
        if ($beUser->isAdmin()) {
            return null;
        }

        // Check if user has access to this page through webmounts
        if (!$beUser->isInWebMount($pid)) {
            return sprintf(
                'Permission denied: You do not have access to page %d. Your account needs database mount point (DB Mount) ' .
                'access to this page or its parent pages. Contact your administrator.',
                $pid
            );
        }

        return null;
    }

    /**
     * Validate authMode permissions for fields like CType.
     * Non-admin users need explicit permissions for certain field values.
     *
     * @param string $table Table name
     * @param array $data Record data
     * @return string|null Error message if permission denied, null if all permissions granted
     */
    protected function validateAuthModePermissions(string $table, array $data): ?string
    {
        $beUser = $GLOBALS['BE_USER'];

        // Admin users bypass authMode checks
        if ($beUser->isAdmin()) {
            return null;
        }

        $tca = $GLOBALS['TCA'][$table] ?? [];
        $columns = $tca['columns'] ?? [];

        foreach ($data as $fieldName => $value) {
            if (!isset($columns[$fieldName])) {
                continue;
            }

            $fieldConfig = $columns[$fieldName]['config'] ?? [];
            $authMode = $fieldConfig['authMode'] ?? null;

            // Only check fields with authMode configured
            if ($authMode === null) {
                continue;
            }

            // Check if user has permission for this value
            if (!$beUser->checkAuthMode($table, $fieldName, $value)) {
                $fieldLabel = $this->tableAccessService->translateLabel(
                    $columns[$fieldName]['label'] ?? $fieldName
                );

                // Collect allowed values for this field
                $allowedValues = $this->getAllowedAuthModeValues($table, $fieldName, $fieldConfig);

                $errorMsg = sprintf(
                    'You do not have permission to use %s="%s" for field "%s".',
                    $fieldName,
                    $value,
                    $fieldLabel
                );

                if (!empty($allowedValues)) {
                    $errorMsg .= ' Allowed values for your user: ' . implode(', ', $allowedValues) . '.';
                } else {
                    $errorMsg .= ' No values are allowed for your user group. Contact your administrator.';
                }

                return $errorMsg;
            }
        }

        return null;
    }

    /**
     * Get allowed authMode values for the current user.
     *
     * @param string $table Table name
     * @param string $fieldName Field name
     * @param array $fieldConfig Field configuration
     * @return array List of allowed values
     */
    protected function getAllowedAuthModeValues(string $table, string $fieldName, array $fieldConfig): array
    {
        $beUser = $GLOBALS['BE_USER'];
        $allowedValues = [];

        // Get all possible values from the field config
        $items = $fieldConfig['items'] ?? [];
        $parsed = $this->tableAccessService->parseSelectItems($items, true); // Skip dividers

        foreach ($parsed['values'] as $itemValue) {
            if ($beUser->checkAuthMode($table, $fieldName, $itemValue)) {
                $label = $parsed['labels'][$itemValue] ?? '';
                $translatedLabel = $this->tableAccessService->translateLabel($label);
                $allowedValues[] = $itemValue . ' (' . $translatedLabel . ')';
            }
        }

        return $allowedValues;
    }

    /**
     * Format DataHandler error messages into user-friendly messages.
     *
     * @param array $errorLog DataHandler error log
     * @return string Formatted error message
     */
    protected function formatDataHandlerErrors(array $errorLog): string
    {
        $errors = [];

        foreach ($errorLog as $error) {
            // Parse common TYPO3 DataHandler error patterns
            if (strpos($error, 'Attempt to insert record on pages:') !== false) {
                if (strpos($error, 'not allowed') !== false) {
                    $errors[] = 'Cannot create record on this page. Check that you have database mount point access ' .
                        'and the necessary table permissions.';
                    continue;
                }
            }

            if (strpos($error, 'recordEditAccessInternals()') !== false) {
                if (strpos($error, 'authMode') !== false) {
                    // Already handled by validateAuthModePermissions, but show if it slipped through
                    preg_match('/field "([^"]+)" with value "([^"]+)"/', $error, $matches);
                    if (count($matches) === 3) {
                        $errors[] = sprintf(
                            'Permission denied for %s="%s". Your user group needs explicit permission for this value.',
                            $matches[1],
                            $matches[2]
                        );
                        continue;
                    }
                }

                if (strpos($error, 'languageField') !== false) {
                    $errors[] = 'Language permission check failed. Ensure sys_language_uid is set in your data.';
                    continue;
                }
            }

            // Default: include original error
            $errors[] = $error;
        }

        return implode(' | ', $errors);
    }
}
