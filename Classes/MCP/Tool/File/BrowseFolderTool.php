<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tool for browsing folder contents (subfolders and files) in a TYPO3 file storage
 */
class BrowseFolderTool extends AbstractRecordTool
{
    /**
     * Get the tool schema
     */
    public function getSchema(): array
    {
        return [
            'description' => 'Browse folder contents in a TYPO3 file storage. Lists subfolders and files with metadata (size, type, modification date). ' .
                'Use combined identifier format like "1:/user_upload/" where 1 is the storage UID. ' .
                'Note: File listing is limited to 100 files per folder. Use SearchFile for larger folders or filtered results.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'folder' => [
                        'type' => 'string',
                        'description' => 'Combined identifier of the folder to browse (e.g. "1:/user_upload/"). Use "/" or omit storage prefix for root of default storage.',
                    ],
                    'recursive' => [
                        'type' => 'boolean',
                        'description' => 'Show nested subfolders recursively (default: false)',
                    ],
                ],
                'required' => ['folder'],
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true,
            ],
        ];
    }

    /**
     * Execute the tool logic
     */
    protected function doExecute(array $params): CallToolResult
    {
        $folderIdentifier = (string)($params['folder'] ?? '/');
        $recursive = (bool)($params['recursive'] ?? false);

        $folder = $this->resolveFolder($folderIdentifier);

        $lines = [];
        $lines[] = sprintf('📁 Storage: %s (UID: %d)', $folder->getStorage()->getName(), $folder->getStorage()->getUid());
        $lines[] = sprintf('📂 %s', $folder->getIdentifier());
        $lines[] = '';

        $this->renderFolderContents($folder, $lines, $recursive, 0);

        return $this->createSuccessResult(implode("\n", $lines));
    }

    /**
     * Resolve a folder identifier to a Folder object
     */
    private function resolveFolder(string $identifier): Folder
    {
        if (str_contains($identifier, ':')) {
            $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
            return $resourceFactory->getFolderObjectFromCombinedIdentifier($identifier);
        }

        $storageRepository = GeneralUtility::makeInstance(StorageRepository::class);
        $defaultStorage = $storageRepository->getDefaultStorage();

        if ($defaultStorage === null) {
            throw new \RuntimeException('No default storage found. Please specify a storage UID (e.g. "1:/").');
        }

        if ($identifier === '' || $identifier === '/') {
            return $defaultStorage->getRootLevelFolder(true);
        }

        return $defaultStorage->getFolder($identifier);
    }

    /**
     * Render folder contents into output lines
     */
    private function renderFolderContents(Folder $folder, array &$lines, bool $recursive, int $depth): void
    {
        $indent = str_repeat('  ', $depth);

        try {
            $subfolders = $folder->getSubfolders();
        } catch (InsufficientFolderAccessPermissionsException) {
            $lines[] = $indent . '⚠️ Access denied to this folder';
            return;
        }

        foreach ($subfolders as $subfolder) {
            $fileCount = count($subfolder->getFiles());
            $combinedIdentifier = sprintf('%d:%s', $folder->getStorage()->getUid(), $subfolder->getIdentifier());
            $lines[] = sprintf('%s📂 %s (%d files) [%s]', $indent, $subfolder->getName(), $fileCount, $combinedIdentifier);

            if ($recursive) {
                $this->renderFolderContents($subfolder, $lines, true, $depth + 1);
            }
        }

        $files = $folder->getFiles(0, 100);
        foreach ($files as $file) {
            $lines[] = sprintf(
                '%s📄 %s (%s, %s) [uid:%d]',
                $indent,
                $file->getName(),
                $this->formatFileSize($file->getSize()),
                $file->getMimeType(),
                $file->getUid()
            );
        }

        if (count($subfolders) === 0 && count($files) === 0) {
            $lines[] = $indent . '(empty folder)';
        }
    }

    /**
     * Format file size to human-readable string
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
}
