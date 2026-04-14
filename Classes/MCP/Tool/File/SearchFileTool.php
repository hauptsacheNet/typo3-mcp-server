<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;
use Mcp\Types\CallToolResult;
use Mcp\Types\ImageContent;
use Mcp\Types\TextContent;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tool for searching files in TYPO3 FAL with optional thumbnail preview
 */
class SearchFileTool extends AbstractRecordTool
{
    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 30;
    private const THUMBNAIL_WIDTH = 150;
    private const THUMBNAIL_HEIGHT = 150;

    public function getSchema(): array
    {
        return [
            'description' => 'Search for existing files in TYPO3 FAL by name, extension, folder, or MIME type. '
                . 'At least one search criterion is required (name, extension, folder, mimeType, or storage). '
                . 'Returns file metadata and optionally Base64-encoded JPEG thumbnails (150x150px) as inline images. '
                . 'SVG files are excluded from thumbnail generation. '
                . 'For larger previews or to save a preview to disk, use PreviewFile which provides a downloadable URL.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Search by filename (partial match, case-insensitive). Example: "logo" finds "logo.png", "company-logo.svg"',
                    ],
                    'extension' => [
                        'type' => 'string',
                        'description' => 'Filter by file extension(s), comma-separated. Example: "png,jpg,svg"',
                    ],
                    'folder' => [
                        'type' => 'string',
                        'description' => 'Filter by folder path (partial match). Example: "/user_upload/logos/"',
                    ],
                    'mimeType' => [
                        'type' => 'string',
                        'description' => 'Filter by MIME type (partial match). Example: "image/" for all images, "application/pdf" for PDFs',
                    ],
                    'storage' => [
                        'type' => 'integer',
                        'description' => 'Filter by storage UID (default: search all storages)',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of results (default: 10, max: 30)',
                    ],
                    'thumbnails' => [
                        'type' => 'boolean',
                        'description' => 'Include thumbnail previews as inline images for image files (default: true). Thumbnails are 150x150px JPEG.',
                    ],
                ],
                'required' => [],
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true,
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $name = (string)($params['name'] ?? '');
        $extension = (string)($params['extension'] ?? '');
        $folder = (string)($params['folder'] ?? '');
        $mimeType = (string)($params['mimeType'] ?? '');
        $storageUid = isset($params['storage']) ? (int)$params['storage'] : null;
        $limit = min((int)($params['limit'] ?? self::DEFAULT_LIMIT), self::MAX_LIMIT);
        $includeThumbnails = (bool)($params['thumbnails'] ?? true);

        if ($name === '' && $extension === '' && $folder === '' && $mimeType === '' && $storageUid === null) {
            return $this->createErrorResult('At least one search criterion is required (name, extension, folder, mimeType, or storage).');
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_file');

        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $queryBuilder
            ->select('uid', 'name', 'identifier', 'storage', 'extension', 'mime_type', 'size', 'sha1', 'creation_date', 'modification_date')
            ->from('sys_file')
            ->setMaxResults($limit);

        if ($name !== '') {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->like(
                    'name',
                    $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards($name) . '%')
                )
            );
        }

        if ($extension !== '') {
            $extensions = array_map('trim', explode(',', $extension));
            $extensionConstraints = [];
            foreach ($extensions as $ext) {
                $extensionConstraints[] = $queryBuilder->expr()->eq(
                    'extension',
                    $queryBuilder->createNamedParameter(ltrim($ext, '.'))
                );
            }
            $queryBuilder->andWhere($queryBuilder->expr()->or(...$extensionConstraints));
        }

        if ($folder !== '') {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->like(
                    'identifier',
                    $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards($folder) . '%')
                )
            );
        }

        if ($mimeType !== '') {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->like(
                    'mime_type',
                    $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards($mimeType) . '%')
                )
            );
        }

        if ($storageUid !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('storage', $queryBuilder->createNamedParameter($storageUid, ParameterType::INTEGER))
            );
        }

        $queryBuilder->orderBy('modification_date', 'DESC');

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

        if (empty($rows)) {
            return $this->createSuccessResult('No files found matching the search criteria.');
        }

        $content = [];
        $content[] = new TextContent(sprintf('%d file(s) found:', count($rows)));

        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);

        foreach ($rows as $row) {
            $line = sprintf(
                "uid:%d | %s | %s | %s | %d:%s",
                $row['uid'],
                $row['name'],
                $this->formatFileSize((int)$row['size']),
                $row['mime_type'],
                $row['storage'],
                $row['identifier']
            );
            $content[] = new TextContent($line);

            if ($includeThumbnails && $this->isImageMimeType($row['mime_type'])) {
                $thumbnailContent = $this->generateThumbnail($resourceFactory, (int)$row['uid']);
                if ($thumbnailContent !== null) {
                    $content[] = $thumbnailContent;
                }
            }
        }

        return new CallToolResult($content);
    }

    private function generateThumbnail(ResourceFactory $resourceFactory, int $fileUid): ?ImageContent
    {
        try {
            $file = $resourceFactory->getFileObject($fileUid);

            $processedFile = $file->process(
                ProcessedFile::CONTEXT_IMAGEPREVIEW,
                [
                    'width' => self::THUMBNAIL_WIDTH,
                    'height' => self::THUMBNAIL_HEIGHT,
                ]
            );

            $localPath = $processedFile->getForLocalProcessing(false);
            if ($localPath === '' || !file_exists($localPath)) {
                return null;
            }

            $imageData = file_get_contents($localPath);
            if ($imageData === false) {
                return null;
            }

            return new ImageContent(
                base64_encode($imageData),
                $processedFile->getMimeType()
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function isImageMimeType(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/') && $mimeType !== 'image/svg+xml';
    }

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
