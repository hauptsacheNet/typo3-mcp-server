<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tool for uploading new files into the TYPO3 fileadmin via MCP.
 *
 * The upload itself is not workspace-capable (sys_file is a live record by
 * design — files exist on the filesystem, not in a workspace overlay).
 * Optional sys_file_metadata edits go through DataHandler and therefore
 * respect the active workspace, consistent with WriteTable behaviour.
 */
class UploadFileTool extends AbstractRecordTool
{
    private const DEFAULT_FOLDER = '/user_upload/mcp/';
    private const MAX_SIZE_BYTES = 50 * 1024 * 1024;
    private const FETCH_TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly RequestFactory $requestFactory,
    ) {
        parent::__construct();
    }

    public function getSchema(): array
    {
        $maxMb = (int) (self::MAX_SIZE_BYTES / 1024 / 1024);

        return [
            'description' => 'Upload a new file into the TYPO3 fileadmin. Accepts either base64-encoded "data" or a "url" to fetch from. ' .
                'Returns the new sys_file uid which can be used immediately in a sys_file_reference relation ' .
                '(e.g. WriteTable action=update table=tt_content data={image: [{uid_local: <uid>, alternative: "..."}]}). ' .
                'Optional metadata (alternative, title, description) is applied to sys_file_metadata right after upload. ' .
                'sys_file_metadata can also be edited later via WriteTable. ' .
                'Use ReadTable table=sys_file_storage to list available storages if multiple exist.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'filename' => [
                        'type' => 'string',
                        'description' => 'Target filename including extension (e.g. "hero-burg.jpg"). Prefer lowercase, hyphen-separated, URL-safe names.',
                    ],
                    'data' => [
                        'type' => 'string',
                        'description' => 'Base64-encoded file content. Provide either "data" or "url", not both.',
                    ],
                    'url' => [
                        'type' => 'string',
                        'description' => 'Publicly accessible http(s) URL to fetch the file from. Provide either "data" or "url", not both.',
                    ],
                    'folder' => [
                        'type' => 'string',
                        'description' => 'Target folder. Accepts a combined identifier ("1:/user_upload/mcp/") or a plain path ("/user_upload/mcp/"). Default: "' . self::DEFAULT_FOLDER . '" on the chosen storage. Missing folders are created automatically.',
                    ],
                    'storage' => [
                        'type' => 'integer',
                        'description' => 'sys_file_storage UID. Required when multiple writable storages exist (typical multi-tenant setups have exactly one per project, in which case this parameter can be omitted).',
                    ],
                    'overwrite' => [
                        'type' => 'boolean',
                        'description' => 'If true and a file with the same name exists, replace it. If false (default), the new file is auto-renamed (hero.jpg -> hero_01.jpg).',
                        'default' => false,
                    ],
                    'metadata' => [
                        'type' => 'object',
                        'description' => 'Optional sys_file_metadata to apply immediately. Most sites require alt-text on images, set it here on upload.',
                        'properties' => [
                            'alternative' => ['type' => 'string', 'description' => 'Alt text (required on many sites; shown when the image cannot be rendered and read by screen readers).'],
                            'title' => ['type' => 'string', 'description' => 'File title (shown as tooltip in some frontends).'],
                            'description' => ['type' => 'string', 'description' => 'Long description / caption default.'],
                        ],
                    ],
                ],
                'required' => ['filename'],
                'description' => sprintf('Maximum upload size: %d MB. Supported sources: base64 data inline, or http(s) URL fetched server-side.', $maxMb),
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'idempotentHint' => false,
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $filename = $this->sanitizeFilename((string) ($params['filename'] ?? ''));
        if ($filename === '') {
            throw new ValidationException(['filename is required and must not be empty']);
        }

        $hasData = isset($params['data']) && $params['data'] !== '';
        $hasUrl = isset($params['url']) && $params['url'] !== '';
        if ($hasData === $hasUrl) {
            throw new ValidationException(['Provide exactly one of "data" (base64) or "url"']);
        }

        $storage = $this->resolveStorage(isset($params['storage']) ? (int) $params['storage'] : null);
        $folder = $this->resolveFolder($storage, isset($params['folder']) ? (string) $params['folder'] : null);

        $tempFile = GeneralUtility::tempnam('mcp_upload_');
        try {
            if ($hasData) {
                $this->writeBase64ToFile((string) $params['data'], $tempFile);
            } else {
                $this->fetchUrlToFile((string) $params['url'], $tempFile);
            }

            $conflictMode = !empty($params['overwrite'])
                ? DuplicationBehavior::REPLACE
                : DuplicationBehavior::RENAME;

            $file = $folder->addFile($tempFile, $filename, $conflictMode);
        } finally {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }

        if (!$file instanceof File) {
            throw new \RuntimeException('Upload did not return a File object');
        }

        $fileUid = $file->getUid();

        if (isset($params['metadata']) && is_array($params['metadata']) && $params['metadata'] !== []) {
            $this->writeFileMetadata($fileUid, $params['metadata']);
        }

        $result = [
            'uid' => $fileUid,
            'identifier' => $file->getIdentifier(),
            'name' => $file->getName(),
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'public_url' => $this->getPublicUrl($file),
            'storage' => [
                'uid' => $storage->getUid(),
                'name' => $storage->getName(),
            ],
            'hint' => sprintf(
                'Attach to a content element: WriteTable action=update table=tt_content uid=<cid> data={"image": [{"uid_local": %d, "alternative": "..."}]}',
                $fileUid
            ),
        ];

        return new CallToolResult([new TextContent(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))]);
    }

    private function resolveStorage(?int $storageUid): ResourceStorage
    {
        if ($storageUid !== null && $storageUid > 0) {
            $storage = $this->storageRepository->findByUid($storageUid);
            if (!$storage instanceof ResourceStorage) {
                throw new ValidationException([sprintf('Storage uid %d not found', $storageUid)]);
            }
            if (!$storage->isWritable()) {
                throw new ValidationException([sprintf('Storage uid %d is not writable', $storageUid)]);
            }
            return $storage;
        }

        $writableStorages = array_values(array_filter(
            $this->storageRepository->findAll(),
            static fn (ResourceStorage $s) => $s->isWritable() && $s->isOnline()
        ));

        if ($writableStorages === []) {
            throw new \RuntimeException('No writable storage available');
        }
        if (count($writableStorages) === 1) {
            return $writableStorages[0];
        }

        $list = implode(', ', array_map(
            static fn (ResourceStorage $s) => sprintf('%d ("%s")', $s->getUid(), $s->getName()),
            $writableStorages
        ));
        throw new ValidationException([
            sprintf('Multiple writable storages exist: %s. Specify "storage": <uid>.', $list),
        ]);
    }

    private function resolveFolder(ResourceStorage $storage, ?string $requested): Folder
    {
        $path = $requested !== null && $requested !== '' ? $requested : self::DEFAULT_FOLDER;

        if (str_contains($path, ':')) {
            [$storagePart, $folderPart] = explode(':', $path, 2);
            if ((int) $storagePart !== $storage->getUid()) {
                throw new ValidationException([sprintf(
                    'Folder identifier "%s" references storage %s but storage %d was resolved',
                    $path,
                    $storagePart,
                    $storage->getUid()
                )]);
            }
            $path = $folderPart;
        }

        $path = '/' . trim($path, '/') . '/';
        if ($path === '//') {
            $path = '/';
        }

        if ($storage->hasFolder($path)) {
            return $storage->getFolder($path);
        }

        return $this->createFolderRecursive($storage, $path);
    }

    private function createFolderRecursive(ResourceStorage $storage, string $path): Folder
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn ($s) => $s !== ''));
        $current = $storage->getRootLevelFolder();

        foreach ($segments as $segment) {
            if ($current->hasFolder($segment)) {
                $current = $current->getSubfolder($segment);
            } else {
                $current = $current->createFolder($segment);
            }
        }

        return $current;
    }

    private function writeBase64ToFile(string $base64, string $path): void
    {
        // Strip a possible "data:...;base64," prefix
        if (str_contains($base64, ',') && str_starts_with($base64, 'data:')) {
            $base64 = substr($base64, strpos($base64, ',') + 1);
        }
        $decoded = base64_decode($base64, true);
        if ($decoded === false) {
            throw new ValidationException(['Invalid base64 data']);
        }
        if (strlen($decoded) > self::MAX_SIZE_BYTES) {
            throw new ValidationException([sprintf(
                'File exceeds max upload size (%d MB)',
                (int) (self::MAX_SIZE_BYTES / 1024 / 1024)
            )]);
        }
        if (file_put_contents($path, $decoded) === false) {
            throw new \RuntimeException('Could not write decoded data to temp file');
        }
    }

    private function fetchUrlToFile(string $url, string $path): void
    {
        $parsed = parse_url($url);
        if (!is_array($parsed) || !isset($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
            throw new ValidationException(['url must be a http(s) URL']);
        }

        $response = $this->requestFactory->request($url, 'GET', [
            'timeout' => self::FETCH_TIMEOUT_SECONDS,
            'headers' => ['User-Agent' => 'TYPO3-MCP-Server'],
            'sink' => $path,
            'allow_redirects' => ['max' => 3],
        ]);

        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException(sprintf('URL fetch failed: HTTP %d', $response->getStatusCode()));
        }

        $size = is_file($path) ? filesize($path) : false;
        if ($size === false || $size === 0) {
            throw new \RuntimeException('Fetched file is empty');
        }
        if ($size > self::MAX_SIZE_BYTES) {
            throw new ValidationException([sprintf(
                'Downloaded file exceeds max size (%d MB)',
                (int) (self::MAX_SIZE_BYTES / 1024 / 1024)
            )]);
        }
    }

    private function sanitizeFilename(string $name): string
    {
        return trim(basename($name));
    }

    private function getPublicUrl(File $file): ?string
    {
        try {
            $url = $file->getPublicUrl();
            return $url !== null && $url !== '' ? $url : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Apply alt/title/description to sys_file_metadata.
     *
     * sys_file_metadata is workspace-capable (versioningWS=true), so this
     * goes through DataHandler and lands in the active workspace — same as
     * any other WriteTable call.
     */
    private function writeFileMetadata(int $fileUid, array $metadata): void
    {
        $allowed = [];
        foreach (['alternative', 'title', 'description'] as $field) {
            if (array_key_exists($field, $metadata)) {
                $allowed[$field] = (string) $metadata[$field];
            }
        }
        if ($allowed === []) {
            return;
        }

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_metadata');
        $qb = $connection->createQueryBuilder();
        $qb->getRestrictions()->removeAll();
        $metaUid = $qb
            ->select('uid')
            ->from('sys_file_metadata')
            ->where(
                $qb->expr()->eq('file', $qb->createNamedParameter($fileUid, \PDO::PARAM_INT)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(0, \PDO::PARAM_INT)),
                $qb->expr()->eq('t3ver_oid', $qb->createNamedParameter(0, \PDO::PARAM_INT))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        $data = [];
        if ($metaUid !== false) {
            $data['sys_file_metadata'][(int) $metaUid] = $allowed;
        } else {
            $new = 'NEW_' . bin2hex(random_bytes(4));
            $data['sys_file_metadata'][$new] = $allowed + ['file' => $fileUid, 'pid' => 0];
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, []);
        $dataHandler->process_datamap();

        if (!empty($dataHandler->errorLog)) {
            throw new \RuntimeException('sys_file_metadata write failed: ' . implode('; ', $dataHandler->errorLog));
        }
    }
}
