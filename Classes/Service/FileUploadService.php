<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\DefaultUploadFolderResolver;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\IllegalFileExtensionException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\Index\FileIndexRepository;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Shared logic for adding files to TYPO3 file storages, used by the
 * UploadFile MCP tool and the pre-signed upload HTTP endpoint.
 *
 * Files are deliberately create-only through MCP: physical files are not
 * workspace-versioned in TYPO3, so overwriting or deleting them would be
 * immediately live and irreversible. Name conflicts are auto-renamed and
 * identical content is deduplicated instead.
 */
class FileUploadService implements SingletonInterface
{
    protected const DEFAULT_MAX_FILE_SIZE_MB = 500;
    protected const UPLOAD_TOKEN_LIFETIME = 900; // 15 minutes

    /**
     * Formats a browser would execute in the site's origin (stored XSS).
     * TYPO3's fileDenyPattern does not cover these - it only guards against
     * server-side execution.
     */
    protected const BROWSER_EXECUTABLE_EXTENSIONS = ['htm', 'html', 'xhtml', 'js', 'mjs', 'svgz', 'swf', 'hta'];

    /**
     * Formats the server itself could execute. TYPO3's fileDenyPattern already
     * blocks these, but it is a configurable setting an integrator can loosen,
     * so uploads coming in through MCP refuse them independently.
     */
    protected const SERVER_EXECUTABLE_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phps', 'phpsh', 'phtml', 'phtm', 'pht', 'phar',
        'shtml', 'shtm', 'cgi', 'pl', 'py', 'rb', 'sh', 'htaccess',
    ];

    /**
     * Exact file names that reconfigure the server rather than being executed
     * themselves. ".user.ini" is the notable one: with PHP running as CGI/FPM it
     * sets per-directory PHP options such as auto_prepend_file, and unlike
     * ".htaccess" it is NOT part of TYPO3's fileDenyPattern. Today it is stopped
     * only incidentally, because the extension "ini" has no matching mime type -
     * too accidental a defense to rely on.
     */
    protected const DENIED_FILE_NAMES = ['.user.ini', '.htaccess', '.htpasswd', 'web.config'];

    /**
     * Combined identifiers of folders created by resolveTargetFolder() in this
     * request, so a failed upload can clean up after itself.
     *
     * @var string[]
     */
    protected array $createdFolderIdentifiers = [];

    /**
     * Resolve the target folder, creating missing folders along the way.
     * Accepts "" (default upload folder), "/path/in/default/storage" and
     * combined identifiers like "2:/path".
     */
    public function resolveTargetFolder(string $targetFolder): Folder
    {
        $storageRepository = GeneralUtility::makeInstance(StorageRepository::class);

        if ($targetFolder === '') {
            $folder = GeneralUtility::makeInstance(DefaultUploadFolderResolver::class)->resolve($GLOBALS['BE_USER']);
            if (!$folder instanceof Folder) {
                throw new \InvalidArgumentException(
                    'No default upload folder available for this user. Pass an explicit "targetFolder".'
                );
            }
            $this->applyUserPermissionsToStorage($folder->getStorage());
            return $folder;
        }

        if (preg_match('/^(\d+):(.*)$/', $targetFolder, $matches)) {
            $storage = $storageRepository->getStorageObject((int)$matches[1]);
            $path = $matches[2];
        } else {
            // Prefer the storage flagged is_default; installations without that
            // flag but a single storage are common, so fall back to that one.
            $storage = $storageRepository->getDefaultStorage();
            if ($storage === null) {
                $allStorages = $storageRepository->findAll();
                $storage = count($allStorages) === 1 ? reset($allStorages) : null;
            }
            $path = $targetFolder;
        }

        if ($storage === null || !$storage->isOnline()) {
            throw new \InvalidArgumentException('No usable file storage found for target folder "' . $targetFolder . '".');
        }
        $this->applyUserPermissionsToStorage($storage);

        $segments = GeneralUtility::trimExplode('/', $path, true);
        if (empty($segments)) {
            return $storage->getRootLevelFolder();
        }

        // Walk down the existing part of the path, then create the remainder relative
        // to the deepest existing folder (permission checks apply where creation starts,
        // which keeps this working for users whose file mount is not the storage root).
        $existingIdentifier = '/';
        while (!empty($segments) && $storage->hasFolder($existingIdentifier . $segments[0] . '/')) {
            $existingIdentifier .= array_shift($segments) . '/';
        }
        $folder = $storage->getFolder($existingIdentifier);
        if (!empty($segments)) {
            try {
                $folder = $storage->createFolder(implode('/', $segments), $folder);
                $this->createdFolderIdentifiers[] = $folder->getCombinedIdentifier();
            } catch (ExistingTargetFolderException) {
                $folder = $storage->getFolder($existingIdentifier . implode('/', $segments) . '/');
            }
        }
        return $folder;
    }

    /**
     * Undo a folder this request created, when the upload it was created for
     * failed and left it empty. Silently does nothing for pre-existing folders,
     * non-empty ones, or when the user may not delete folders.
     */
    public function removeFolderIfCreatedAndEmpty(Folder $folder): void
    {
        if (!in_array($folder->getCombinedIdentifier(), $this->createdFolderIdentifiers, true)) {
            return;
        }
        try {
            if ($folder->getFileCount() > 0 || count($folder->getSubfolders()) > 0) {
                return;
            }
            $folder->getStorage()->deleteFolder($folder);
            $this->createdFolderIdentifiers = array_values(array_diff(
                $this->createdFolderIdentifiers,
                [$folder->getCombinedIdentifier()]
            ));
        } catch (\Exception) {
            // Leaving an empty folder behind is preferable to failing the
            // request with a secondary error.
        }
    }

    /**
     * The default upload folder shown in tool schemas, e.g. "1:/user_upload/".
     * Returns null when it cannot be resolved (no storage configured yet).
     */
    public function getDefaultUploadFolderIdentifier(): ?string
    {
        try {
            $user = $GLOBALS['BE_USER'] ?? null;
            if (!$user instanceof BackendUserAuthentication) {
                return null;
            }
            $folder = GeneralUtility::makeInstance(DefaultUploadFolderResolver::class)->resolve($user);
            return $folder instanceof Folder ? $folder->getCombinedIdentifier() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Enforce the user's file mounts and file permissions on the storage.
     *
     * TYPO3's StoragePermissionsAspect does this only for backend HTTP requests;
     * MCP tools also run via CLI/stdio where $GLOBALS['TYPO3_REQUEST'] is not a
     * backend request, so the storage would otherwise be unrestricted for
     * non-admin users (mirrors the read-side SysFileMountRestrictionListener).
     */
    public function applyUserPermissionsToStorage(ResourceStorage $storage): void
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            // Fail closed: uploads without an authenticated backend user must
            // never end up on an unrestricted storage.
            throw new \RuntimeException('No authenticated backend user in the upload context.', 1753887000);
        }
        if ($user->isAdmin()
            || $storage->isFallbackStorage()
            || $storage->getEvaluatePermissions()
        ) {
            return;
        }

        $storage->setEvaluatePermissions(true);
        // getFilePermissions() merged with per-storage TSconfig overrides,
        // same as the core aspect's getFilePermissionsForStorage()
        $permissions = $user->getFilePermissions();
        $storageOverrides = $user->getTSConfig()['permissions.']['file.']['storage.'][$storage->getUid() . '.'] ?? [];
        foreach ($storageOverrides as $permission => $value) {
            $permissions[$permission] = (bool)$value;
        }
        $storage->setUserPermissions($permissions);
        foreach ($user->getFileMountRecords() as $fileMountRow) {
            if (!str_contains($fileMountRow['identifier'] ?? '', ':')) {
                continue;
            }
            [$base, $path] = GeneralUtility::trimExplode(':', $fileMountRow['identifier'], false, 2);
            if ((int)$base === $storage->getUid()) {
                try {
                    $storage->addFileMount($path, $fileMountRow);
                } catch (FolderDoesNotExistException) {
                    // Invalid mount, skip silently (same as the core aspect)
                }
            }
        }
    }

    /**
     * Store a local (temp) file in the given folder: deduplicates identical
     * content, auto-renames on name conflicts, never overwrites.
     *
     * @return array{file: File, deduplicated: bool}
     * @throws \InvalidArgumentException on validation problems (bad extension, content mismatch, missing permission)
     */
    public function storeFile(string $tempPath, string $fileName, Folder $folder): array
    {
        $fileName = basename($fileName);
        if ($fileName === '' || !str_contains($fileName, '.')) {
            throw new \InvalidArgumentException(
                'Could not determine a file name with an extension. Pass an explicit "fileName" like "image.jpg".'
            );
        }

        $this->assertFileNameIsAllowed($fileName);

        // Identical content in this storage? Return the existing file instead of
        // creating a copy - this also makes retries after timeouts idempotent.
        $existingFile = $this->findIdenticalFile($folder->getStorage(), sha1_file($tempPath));
        if ($existingFile !== null) {
            return ['file' => $existingFile, 'deduplicated' => true];
        }

        try {
            $file = $folder->getStorage()->addFile($tempPath, $folder, $fileName, DuplicationBehavior::RENAME);
        } catch (IllegalFileExtensionException $e) {
            throw new \InvalidArgumentException('This file extension is not allowed: ' . $e->getMessage(), 0, $e);
        } catch (\TYPO3\CMS\Core\Validation\ResultException $e) {
            // TYPO3 >= 14: ResourceConsistencyService rejects files whose content
            // does not match their extension/mime type.
            throw new \InvalidArgumentException(
                'The file was rejected because its content does not match the file extension "'
                . pathinfo($fileName, PATHINFO_EXTENSION) . '". '
                . 'Make sure the URL/content actually delivers this file type.',
                0,
                $e
            );
        }

        // Uploads can be rewritten while being stored (e.g. the core SVG
        // sanitizer), so the pre-upload hash above misses duplicates of such
        // files. Re-check with the hash of the stored content and drop the
        // fresh copy when it turns out to be one.
        $existingFile = $this->findIdenticalFile($folder->getStorage(), $file->getSha1(), $file->getUid());
        if ($existingFile !== null) {
            try {
                $folder->getStorage()->deleteFile($file);
                return ['file' => $existingFile, 'deduplicated' => true];
            } catch (\Exception) {
                // The user may create but not delete files; keep the copy then.
            }
        }

        return ['file' => $file, 'deduplicated' => false];
    }

    /**
     * Refuse files that could be executed - by the server or by a visitor's
     * browser - or that reconfigure the server. Deliberately independent of
     * TYPO3's configurable fileDenyPattern and of the mime-consistency check,
     * so the guarantee holds no matter how an installation is configured.
     */
    public function assertFileNameIsAllowed(string $fileName): void
    {
        $lowerName = strtolower($fileName);
        if (in_array($lowerName, self::DENIED_FILE_NAMES, true)) {
            throw new \InvalidArgumentException(
                'The file name "' . $fileName . '" is not allowed because a file with that name reconfigures '
                . 'the web server or PHP.'
            );
        }

        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (in_array($extension, self::SERVER_EXECUTABLE_EXTENSIONS, true)) {
            throw new \InvalidArgumentException(
                'Files with the extension ".' . $extension . '" are not allowed because they could be executed '
                . 'on the server.'
            );
        }
        if (in_array($extension, self::BROWSER_EXECUTABLE_EXTENSIONS, true)) {
            throw new \InvalidArgumentException(
                'Files with the extension ".' . $extension . '" are not allowed because they could run '
                . 'script code in the browser when served from the file storage.'
            );
        }

        // "evil.php.jpg" and friends: on servers that map by any extension in
        // the name, an inner executable extension is enough.
        foreach (explode('.', $lowerName) as $part) {
            if (in_array($part, self::SERVER_EXECUTABLE_EXTENSIONS, true)) {
                throw new \InvalidArgumentException(
                    'The file name "' . $fileName . '" is not allowed because it contains the potentially '
                    . 'executable extension ".' . $part . '".'
                );
            }
        }
    }

    /**
     * Basic response data for an uploaded file, shared between the MCP tool
     * result and the upload endpoint's JSON response.
     */
    public function describeFile(File $file): array
    {
        $siteInformation = GeneralUtility::makeInstance(SiteInformationService::class);
        return [
            'uid' => $file->getUid(),
            'fileName' => $file->getName(),
            'identifier' => $file->getCombinedIdentifier(),
            'publicUrl' => $siteInformation->makeAbsoluteUrl($file->getPublicUrl()),
            'mimeType' => $file->getMimeType(),
            'size' => $file->getSize(),
        ];
    }

    /**
     * Maximum accepted file size in bytes for downloads and pre-signed uploads,
     * configurable via the extension setting "maxFileSizeMb".
     */
    public function getMaxFileBytes(): int
    {
        try {
            $configured = (int)GeneralUtility::makeInstance(ExtensionConfiguration::class)
                ->get('mcp_server', 'maxFileSizeMb');
        } catch (\Throwable) {
            $configured = 0;
        }
        return ($configured > 0 ? $configured : self::DEFAULT_MAX_FILE_SIZE_MB) * 1024 * 1024;
    }

    /**
     * Find a non-missing file with identical content (same SHA1) in the storage
     * via TYPO3's file index. Non-admin users only get matches within their file
     * mounts, otherwise the dedupe would leak (and hand out) files they cannot
     * access.
     */
    protected function findIdenticalFile(ResourceStorage $storage, string $sha1, int $excludeFileUid = 0): ?File
    {
        $rows = GeneralUtility::makeInstance(FileIndexRepository::class)->findByContentHash($sha1);
        $tableAccessService = GeneralUtility::makeInstance(TableAccessService::class);
        $user = $GLOBALS['BE_USER'] ?? null;
        $isAdmin = $user instanceof BackendUserAuthentication && $user->isAdmin();

        foreach ($rows as $row) {
            if ((int)($row['storage'] ?? 0) !== $storage->getUid() || !empty($row['missing'])) {
                continue;
            }
            $uid = (int)$row['uid'];
            if ($uid === $excludeFileUid) {
                continue;
            }
            if (!$isAdmin && !$tableAccessService->canAccessFileUid($uid)) {
                continue;
            }
            try {
                return GeneralUtility::makeInstance(ResourceFactory::class)->getFileObject($uid, $row);
            } catch (\Exception) {
                continue;
            }
        }
        return null;
    }

    /**
     * Parse a file name from a Content-Disposition header, supporting both the
     * RFC 5987 filename*= form and the plain filename= form.
     */
    public function extractFileNameFromContentDisposition(string $header): ?string
    {
        if ($header === '') {
            return null;
        }
        if (preg_match("/filename\\*\\s*=\\s*utf-8''([^;]+)/i", $header, $matches)) {
            $name = basename(rawurldecode(trim($matches[1], " \t\"")));
            return $name !== '' ? $name : null;
        }
        if (preg_match('/filename\s*=\s*"([^"]*)"/', $header, $matches)
            || preg_match('/filename\s*=\s*([^;]+)/', $header, $matches)
        ) {
            $name = basename(trim($matches[1], " \t\""));
            return $name !== '' ? $name : null;
        }
        return null;
    }

    // -----------------------------------------------------------------
    // Pre-signed upload tokens
    // -----------------------------------------------------------------

    /**
     * Create a single-use upload token bound to the current user and a target
     * folder. Returns the plain token (only the hash is stored) and expiry.
     *
     * @return array{token: string, validUntil: int}
     */
    public function createUploadToken(Folder $folder, string $fileName = ''): array
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        $userUid = (int)($user->user['uid'] ?? 0);
        if ($userUid <= 0) {
            throw new \InvalidArgumentException('No authenticated backend user for upload token creation.');
        }

        $token = bin2hex(random_bytes(32));
        $validUntil = time() + self::UPLOAD_TOKEN_LIFETIME;

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_upload_tokens');

        // Lazy garbage collection: drop tokens that expired more than a day ago
        $gcQuery = $connection->createQueryBuilder();
        $gcQuery->delete('tx_mcpserver_upload_tokens')
            ->where($gcQuery->expr()->lt('expires', $gcQuery->createNamedParameter(time() - 86400, \Doctrine\DBAL\ParameterType::INTEGER)))
            ->executeStatement();

        $connection->insert('tx_mcpserver_upload_tokens', [
                'token' => hash('sha256', $token),
                'be_user_uid' => $userUid,
                'target_folder' => $folder->getCombinedIdentifier(),
                'file_name' => basename($fileName),
                'expires' => $validUntil,
                'used' => 0,
                'tstamp' => time(),
                'crdate' => time(),
            ]);

        return ['token' => $token, 'validUntil' => $validUntil];
    }

    /**
     * Validate and atomically consume a plain upload token. Returns the token
     * row exactly once: parallel or repeated requests with the same token get
     * null, as do unknown, expired, or already used tokens.
     *
     * The token is consumed BEFORE the upload runs, so a failed upload burns
     * it too - a leaked token is a single attempt, not a 15-minute upload
     * permit. Clients simply request a fresh URL to retry.
     */
    public function consumeUploadToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_upload_tokens');
        $row = $connection->select(
            ['*'],
            'tx_mcpserver_upload_tokens',
            ['token' => hash('sha256', $token)]
        )->fetchAssociative();

        if (!$row || (int)$row['used'] !== 0 || (int)$row['expires'] < time()) {
            return null;
        }

        $affected = $connection->executeStatement(
            'UPDATE tx_mcpserver_upload_tokens SET used = ?, tstamp = ? WHERE uid = ? AND used = 0',
            [time(), time(), (int)$row['uid']]
        );
        return $affected === 1 ? $row : null;
    }
}
