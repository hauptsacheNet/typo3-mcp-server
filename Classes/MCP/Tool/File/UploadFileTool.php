<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;
use Hn\McpServer\Service\FileUploadService;
use Hn\McpServer\Service\SiteInformationService;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderWritePermissionsException;
use TYPO3\CMS\Core\Resource\Exception\OnlineMediaAlreadyExistsException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\MimeTypeDetector;
use TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\OnlineMediaHelperRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tool for uploading new files into TYPO3 file storages (FAL).
 *
 * Three modes:
 *  - url:      the server downloads the file (YouTube/Vimeo URLs become online media assets)
 *  - content:  raw text is stored as a file (svg, csv, vtt, ...)
 *  - neither:  a pre-signed, single-use upload URL is returned so the client
 *              can HTTP-PUT a local file directly to the TYPO3 instance
 *
 * Files are create-only; see FileUploadService for the rename/dedupe rules.
 */
class UploadFileTool extends AbstractRecordTool
{
    protected const MAX_REDIRECTS = 5;

    /**
     * Internal/special-purpose IPv4 ranges that PHP's NO_PRIV_RANGE/NO_RES_RANGE
     * filter flags do NOT cover. The documentation ranges (TEST-NET-1..3) stay
     * allowed on purpose: they are not routable, and the functional tests use
     * them as safe "public" IP literals.
     */
    protected const BLOCKED_IPV4_CIDRS = [
        '100.64.0.0/10', // CGNAT - cloud-internal, e.g. Alibaba metadata 100.100.100.200
        '192.0.0.0/24',  // IETF protocol assignments
        '198.18.0.0/15', // benchmarking
        '224.0.0.0/4',   // multicast
    ];

    /**
     * Online media helpers that threw during the last tryCreateOnlineMedia()
     * call, keyed by file extension. See withSkippedOnlineMediaHelperFailures().
     *
     * @var array<string, string>
     */
    protected array $skippedOnlineMediaHelperFailures = [];

    public function getSchema(): array
    {
        $uploadService = GeneralUtility::makeInstance(FileUploadService::class);

        $targetFolderProperty = [
            'type' => 'string',
            'description' => 'Folder path inside the storage, e.g. "/user_upload/campaign/" or "2:/some/folder/" to address '
                . 'a specific storage. Missing folders are created.',
        ];
        $defaultFolder = $uploadService->getDefaultUploadFolderIdentifier();
        if ($defaultFolder !== null) {
            $targetFolderProperty['default'] = $defaultFolder;
        }

        return [
            'description' => 'Upload a new file into the TYPO3 file storage (e.g. fileadmin). '
                . 'Provide "url" (the server downloads the file; YouTube/Vimeo URLs create an online media asset) '
                . 'or "content" (raw text for text-based formats like svg, csv or vtt). '
                . 'With NEITHER of them, a pre-signed single-use upload URL is returned to which a local file '
                . 'can be sent directly via HTTP PUT (e.g. with curl) - use this to upload files from the local machine. '
                . 'This tool never overwrites anything: name conflicts are resolved by renaming (image.jpg -> image_01.jpg) '
                . 'and re-uploading identical content returns the already existing file. '
                . 'The file is stored immediately (files are not workspace-versioned), but it is not visible on the website '
                . 'until a record references it - create the reference with WriteTable (e.g. tt_content.assets = [{"uid_local": <uid>}]).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'url' => [
                        'type' => 'string',
                        'description' => 'HTTP(S) URL to download the file from. '
                            . 'YouTube and Vimeo video URLs are recognized and stored as TYPO3 online media assets '
                            . '(a small placeholder file referencing the video - the video itself is not downloaded).',
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'Raw text content to store as a file. Only for text-based formats (svg, txt, csv, vtt, ...). '
                            . 'Binary formats cannot be uploaded this way - use "url" or the pre-signed upload URL instead.',
                    ],
                    'fileName' => [
                        'type' => 'string',
                        'description' => 'Target file name including extension (e.g. "team-photo.jpg"). '
                            . 'Required with "content"; optional otherwise (derived from the URL, Content-Disposition header, '
                            . 'or provided during the pre-signed upload).',
                    ],
                    'targetFolder' => $targetFolderProperty,
                ],
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => true,
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $url = trim((string)($params['url'] ?? ''));
        $content = $params['content'] ?? null;
        $hasContent = is_string($content) && $content !== '';
        $requestedFileName = trim((string)($params['fileName'] ?? ''));
        $uploadService = GeneralUtility::makeInstance(FileUploadService::class);

        if ($url !== '' && $hasContent) {
            return $this->createErrorResult('Provide only one of "url" or "content" (or neither for a pre-signed upload URL).');
        }

        // Check the requested name before touching the storage: resolving the
        // target folder creates missing directories, and a rejected upload
        // should not leave an empty folder behind.
        if ($requestedFileName !== '') {
            $uploadService->assertFileNameIsAllowed($requestedFileName);
        }

        $targetFolderPath = trim((string)($params['targetFolder'] ?? ''));
        try {
            $folder = $uploadService->resolveTargetFolder($targetFolderPath);
        } catch (InsufficientFolderAccessPermissionsException | InsufficientFolderWritePermissionsException $e) {
            return $this->createErrorResult('No permission for the target folder: ' . $e->getMessage());
        }

        // No source at all: hand out a pre-signed upload URL instead
        if ($url === '' && !$hasContent) {
            return $this->createPresignedUploadResult($uploadService, $folder, $requestedFileName);
        }

        if ($url !== '') {
            // Online media (YouTube/Vimeo) never hits the download path: the URL is
            // recognized by its pattern and stored as a placeholder file via the core API.
            $onlineMediaResult = $this->tryCreateOnlineMedia($url, $folder, $uploadService);
            if ($onlineMediaResult !== null) {
                return $onlineMediaResult;
            }
            try {
                [$tempPath, $fileName] = $this->downloadFile($url, $requestedFileName, $uploadService->getMaxFileBytes());
            } catch (\Throwable $e) {
                // A failed download must not leave a freshly created folder behind
                $uploadService->removeFolderIfCreatedAndEmpty($folder);
                throw $this->withSkippedOnlineMediaHelperFailures($e);
            }
        } else {
            if ($requestedFileName === '') {
                return $this->createErrorResult('"fileName" is required when uploading "content".');
            }
            $maxBytes = $uploadService->getMaxFileBytes();
            if (strlen($content) > $maxBytes) {
                return $this->createErrorResult(
                    'The content exceeds the maximum size of ' . round($maxBytes / 1048576) . ' MiB '
                    . '(configurable via the extension setting "maxFileSizeMb").'
                );
            }
            $tempPath = GeneralUtility::tempnam('mcp_upload_');
            if (@file_put_contents($tempPath, $content) === false) {
                @unlink($tempPath);
                return $this->createErrorResult('Failed to buffer the uploaded content on the server.');
            }
            $fileName = $requestedFileName;
        }

        try {
            try {
                $stored = $uploadService->storeFile($tempPath, $fileName, $folder);
            } catch (InsufficientFolderWritePermissionsException $e) {
                return $this->createErrorResult('No permission to add files to this folder: ' . $e->getMessage());
            }
            return $this->buildResult($uploadService, $stored['file'], $fileName, $stored['deduplicated']);
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Create a single-use upload URL the MCP client can PUT a local file to.
     */
    protected function createPresignedUploadResult(FileUploadService $uploadService, Folder $folder, string $fileName): CallToolResult
    {
        // Resolve the endpoint URL before minting a token: a relative upload
        // URL is unusable for the client, so fail hard instead (typically
        // stdio mode without a fully qualified site base).
        $siteInformation = GeneralUtility::makeInstance(SiteInformationService::class);
        $endpointUrl = (string)$siteInformation->makeAbsoluteUrl('/mcp_upload');
        if (!str_starts_with($endpointUrl, 'http://') && !str_starts_with($endpointUrl, 'https://')) {
            return $this->createErrorResult(
                'Cannot build an absolute upload URL because no public base URL of this TYPO3 instance is known '
                . '(no HTTP request context and no site with a fully qualified base URL). '
                . 'Configure a site base URL including scheme and domain, or upload via "url" or "content" instead.'
            );
        }

        $tokenData = $uploadService->createUploadToken($folder, $fileName);

        // The token travels as an Authorization header, not in the URL: query
        // strings end up in webserver and proxy logs.
        $curlFileName = $fileName !== '' ? $fileName : 'photo.jpg';
        $curlUrl = $endpointUrl . ($fileName === '' ? '?fileName=' . rawurlencode($curlFileName) : '');

        return $this->createJsonResult([
            'uploadUrl' => $endpointUrl,
            'uploadToken' => $tokenData['token'],
            'targetFolder' => $folder->getCombinedIdentifier(),
            'fileName' => $fileName !== '' ? $fileName : null,
            'validUntil' => date('c', $tokenData['validUntil']),
            'instructions' => 'Send the raw file bytes via HTTP PUT (or POST) to the uploadUrl, '
                . 'passing the uploadToken as bearer token, e.g.: '
                . "curl -sS -T '" . $curlFileName . "' -H 'Authorization: Bearer " . $tokenData['token'] . "' '" . $curlUrl . "'"
                . ($fileName === '' ? ' - replace the fileName query parameter with the actual file name including extension.' : '')
                . ' The token is single-use and expires at validUntil. '
                . 'The endpoint responds with JSON containing the created file (uid, fileName, publicUrl, ...); '
                . 'use that uid with WriteTable to reference the file from a record.',
        ]);
    }

    /**
     * Store a YouTube/Vimeo URL as an online media asset via the core helper.
     * Returns null when the URL is not an online media URL.
     */
    protected function tryCreateOnlineMedia(string $url, Folder $folder, FileUploadService $uploadService): ?CallToolResult
    {
        $registry = GeneralUtility::makeInstance(OnlineMediaHelperRegistry::class);
        $this->skippedOnlineMediaHelperFailures = [];

        // Ask the helpers one by one instead of letting the registry loop over
        // all of them: the interface says "return null for URLs you don't
        // handle", but third-party helpers that only support other creation
        // paths sometimes throw instead. In the registry loop such a helper
        // aborts the whole upload for every URL that is not YouTube/Vimeo;
        // here it is logged and skipped, and the URL falls through to the
        // regular download.
        $extensions = array_keys($GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['onlineMediaHelpers'] ?? []);
        foreach ($extensions as $extension) {
            try {
                $file = $registry->transformUrlToFile($url, $folder, [$extension]);
            } catch (OnlineMediaAlreadyExistsException $e) {
                return $this->buildResult($uploadService, $e->getOnlineMedia(), '', deduplicated: true, onlineMedia: true);
            } catch (\Throwable $e) {
                $this->getLogger()->warning(
                    'Online media helper for ".{extension}" threw for URL "{url}" and was skipped: {message}',
                    ['extension' => $extension, 'url' => $url, 'message' => $e->getMessage(), 'exception' => $e]
                );
                $this->skippedOnlineMediaHelperFailures[$extension] = $e->getMessage();
                continue;
            }
            if ($file !== null) {
                return $this->buildResult($uploadService, $file, $file->getName(), deduplicated: false, onlineMedia: true);
            }
        }
        return null;
    }

    /**
     * A helper that threw may have been the one responsible for this URL (a
     * YouTube helper whose API call failed, say). We cannot tell that apart
     * from a helper that merely misbehaves on foreign URLs, so the failure is
     * not swallowed: if the fall-back download fails as well, the helper
     * failures are attached to the error the caller sees.
     */
    protected function withSkippedOnlineMediaHelperFailures(\Throwable $e): \Throwable
    {
        if ($this->skippedOnlineMediaHelperFailures === []) {
            return $e;
        }
        $notes = [];
        foreach ($this->skippedOnlineMediaHelperFailures as $extension => $message) {
            $notes[] = '".' . $extension . '": ' . $message;
        }
        return new \InvalidArgumentException(
            $e->getMessage()
            . ' Note: before falling back to a plain download, the online media helper(s) for '
            . implode('; ', $notes)
            . ' failed and were skipped. If the URL is meant to become an online media asset, that failure is the actual problem.',
            0,
            $e
        );
    }

    /**
     * Download a remote file to a temp path. Redirects are followed manually
     * so that EVERY hop is both validated against the SSRF rules and pinned
     * to the IP that was validated (DNS rebinding would otherwise allow a
     * second lookup between the check and the actual connect).
     * Returns [tempPath, fileName].
     *
     * @return array{0: string, 1: string}
     */
    protected function downloadFile(string $url, string $requestedFileName, int $maxBytes): array
    {
        $currentUrl = $url;
        $response = null;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $resolvedIp = $this->assertPublicHttpUrl($currentUrl);

            $options = [
                'allow_redirects' => false,
                'http_errors' => false,
                'stream' => true,
                'connect_timeout' => 10,
                'timeout' => 300,
                'headers' => ['User-Agent' => 'TYPO3-MCP-Server'],
            ];
            // Pin the vetted IP so the actual connect cannot be re-routed to an
            // internal address via DNS rebinding (honored by the curl handler;
            // harmless elsewhere). Skipped when TYPO3 routes HTTP through a
            // proxy: the proxy resolves the host itself, pinning would break it.
            $parts = parse_url($currentUrl);
            $host = $parts['host'] ?? '';
            $proxyConfigured = !empty($GLOBALS['TYPO3_CONF_VARS']['HTTP']['proxy']);
            if (!$proxyConfigured && $resolvedIp !== null && !filter_var($host, FILTER_VALIDATE_IP) && defined('CURLOPT_RESOLVE')) {
                $port = $parts['port'] ?? (($parts['scheme'] ?? 'https') === 'https' ? 443 : 80);
                // CURLOPT_RESOLVE wants IPv6 addresses in brackets; a bare one
                // makes the entry malformed, and curl then silently ignores the
                // pin - which would drop the rebinding protection for
                // IPv6-only hosts.
                $pinnedIp = filter_var($resolvedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                    ? '[' . $resolvedIp . ']'
                    : $resolvedIp;
                $options['curl'] = [\CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $pinnedIp]];
            }

            try {
                $response = GeneralUtility::makeInstance(RequestFactory::class)->request($currentUrl, 'GET', $options);
            } catch (GuzzleException $e) {
                throw new \InvalidArgumentException('Downloading the URL failed: ' . $e->getMessage(), 0, $e);
            }

            if (!in_array($response->getStatusCode(), [301, 302, 303, 307, 308], true)) {
                break;
            }

            $location = $response->getHeaderLine('Location');
            if ($location === '') {
                throw new \InvalidArgumentException('The URL redirected without a Location header.');
            }
            $currentUrl = (string)UriResolver::resolve(Utils::uriFor($currentUrl), Utils::uriFor($location));
            $response = null;
        }
        if ($response === null) {
            throw new \InvalidArgumentException('The URL redirected more than ' . self::MAX_REDIRECTS . ' times.');
        }

        if ($response->getStatusCode() !== 200) {
            throw new \InvalidArgumentException('Downloading the URL failed with HTTP status ' . $response->getStatusCode() . '.');
        }

        $finalUrl = $currentUrl;

        $tempPath = GeneralUtility::tempnam('mcp_upload_');
        $handle = fopen($tempPath, 'wb');
        $body = $response->getBody();
        $bytes = 0;
        while (!$body->eof()) {
            $chunk = $body->read(65536);
            if ($chunk === '') {
                break;
            }
            $bytes += strlen($chunk);
            if ($bytes > $maxBytes) {
                fclose($handle);
                @unlink($tempPath);
                throw new \InvalidArgumentException(
                    'The file exceeds the maximum size of ' . round($maxBytes / 1048576) . ' MiB '
                    . '(configurable via the extension setting "maxFileSizeMb").'
                );
            }
            fwrite($handle, $chunk);
        }
        fclose($handle);

        if ($bytes === 0) {
            @unlink($tempPath);
            throw new \InvalidArgumentException('The URL returned an empty response body.');
        }

        // A web page is not a file. Without this, the download would be rejected
        // further down with a message about file extensions or mismatching
        // content, which sends the caller hunting for the wrong problem.
        if ($this->looksLikeHtmlDocument($tempPath)) {
            @unlink($tempPath);
            throw new \InvalidArgumentException(
                'The URL returned a web page (HTML), not a file. Pass a direct link to the file itself '
                . '(e.g. the image address from the page, usually ending in .jpg/.png/.pdf). '
                . 'YouTube and Vimeo page URLs are the exception - those are recognized and embedded as videos.'
            );
        }

        if ($requestedFileName !== '') {
            return [$tempPath, $requestedFileName];
        }

        try {
            $fileName = $this->deriveFileName(
                $finalUrl,
                $response->getHeaderLine('Content-Disposition'),
                $response->getHeaderLine('Content-Type')
            );
        } catch (\Throwable $e) {
            @unlink($tempPath);
            throw $e;
        }

        return [$tempPath, $fileName];
    }

    /**
     * Sniff the downloaded bytes for an HTML document. Deliberately content-based
     * rather than header-based: servers mislabel real files as text/html often
     * enough that a Content-Type check alone would reject valid downloads.
     */
    protected function looksLikeHtmlDocument(string $tempPath): bool
    {
        $handle = @fopen($tempPath, 'rb');
        if ($handle === false) {
            return false;
        }
        $head = (string)fread($handle, 1024);
        fclose($handle);

        // Skip a UTF-8 BOM and leading whitespace, then look for the markers a
        // browser would use to decide it is dealing with a document.
        $head = strtolower(ltrim(preg_replace('/^\xEF\xBB\xBF/', '', $head) ?? ''));
        foreach (['<!doctype html', '<html', '<head', '<body'] as $marker) {
            if (str_starts_with($head, $marker)) {
                return true;
            }
        }

        // Documents that open with a comment or an XML declaration
        return (bool)preg_match('/^(<\?xml[^>]*\?>|<!--.{0,200}?-->)\s*(<!doctype html|<html)/s', $head);
    }

    /**
     * Validate that a URL is http(s) and does not point at a private or
     * reserved address. Returns the vetted IP (null for URLs whose host is
     * already an IP literal).
     */
    protected function assertPublicHttpUrl(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \InvalidArgumentException('Only http:// and https:// URLs can be downloaded. Got: ' . $url);
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
            $isLiteral = true;
        } else {
            $ips = @gethostbynamel($host) ?: [];
            // AAAA lookups fail or time out in plenty of environments (IPv6-less
            // Docker networks, filtering resolvers). Suppress that so a broken
            // AAAA lookup cannot kill a download that has usable A records - but
            // never skip validation of records we did get.
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
            if (empty($ips)) {
                throw new \InvalidArgumentException('Could not resolve host "' . $host . '".');
            }
            $isLiteral = false;
        }

        foreach ($ips as $ip) {
            $this->assertPublicIp($ip);
        }

        return $isLiteral ? null : $ips[0];
    }

    /**
     * Reject private, reserved, and cloud-internal addresses, including IPv4
     * addresses embedded in IPv6 (mapped, NAT64, 6to4), which would otherwise
     * smuggle e.g. 127.0.0.1 past the IPv4 checks.
     */
    protected function assertPublicIp(string $ip): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new \InvalidArgumentException(
                'The URL points to a private or reserved network address and cannot be downloaded.'
            );
        }

        $binary = inet_pton($ip);
        if ($binary === false) {
            throw new \InvalidArgumentException('The URL resolves to an invalid IP address.');
        }

        if (strlen($binary) === 4) {
            foreach (self::BLOCKED_IPV4_CIDRS as $cidr) {
                [$net, $bits] = explode('/', $cidr);
                $mask = -1 << (32 - (int)$bits);
                if ((ip2long($ip) & $mask) === (ip2long($net) & $mask)) {
                    throw new \InvalidArgumentException(
                        'The URL points to a private or reserved network address and cannot be downloaded.'
                    );
                }
            }
            return;
        }

        // IPv6: validate any embedded IPv4 address as well
        $embedded = null;
        if (str_starts_with($binary, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff")) {
            $embedded = substr($binary, 12, 4); // ::ffff:a.b.c.d (IPv4-mapped)
        } elseif (str_starts_with($binary, "\x00\x64\xff\x9b")) {
            $embedded = substr($binary, 12, 4); // 64:ff9b::/96 (NAT64)
        } elseif (str_starts_with($binary, "\x20\x02")) {
            $embedded = substr($binary, 2, 4);  // 2002::/16 (6to4)
        }
        if ($embedded !== null) {
            $this->assertPublicIp(inet_ntop($embedded));
        }
    }

    /**
     * Derive the file name: Content-Disposition beats the URL path, the
     * Content-Type based extension is the last resort.
     */
    protected function deriveFileName(string $url, string $contentDisposition, string $contentType): string
    {
        $fileName = GeneralUtility::makeInstance(FileUploadService::class)
            ->extractFileNameFromContentDisposition($contentDisposition);

        if ($fileName === null) {
            $path = parse_url($url, PHP_URL_PATH) ?: '';
            $fileName = basename(rawurldecode($path));
        }

        if ($fileName !== '' && str_contains($fileName, '.')) {
            return $fileName;
        }

        $mimeType = strtolower(trim(explode(';', $contentType)[0]));
        $extension = GeneralUtility::makeInstance(MimeTypeDetector::class)
            ->getFileExtensionsForMimeType($mimeType)[0] ?? null;
        if ($extension === null) {
            throw new \InvalidArgumentException(
                'Could not derive a file name from the URL (content type "' . $contentType . '"). Pass an explicit "fileName".'
            );
        }
        return ($fileName !== '' ? $fileName : 'download') . '.' . $extension;
    }

    protected function buildResult(
        FileUploadService $uploadService,
        File $file,
        string $requestedFileName,
        bool $deduplicated,
        bool $onlineMedia = false
    ): CallToolResult {
        $data = $uploadService->describeFile($file);

        if ($onlineMedia) {
            $data['onlineMedia'] = true;
        }
        if ($deduplicated) {
            $data['deduplicated'] = true;
            $data['note'] = 'A file with identical content already exists in this storage at "' . $file->getCombinedIdentifier()
                . '" (possibly in a different folder than requested); it is returned instead of creating a duplicate.';
        } else {
            if ($requestedFileName !== '' && $file->getName() !== $requestedFileName) {
                $data['renamedFrom'] = $requestedFileName;
                $data['note'] = 'A different file with this name already existed, so the upload was stored under a new name.';
            }
            $data['nextStep'] = 'The file is stored but not yet used anywhere. Reference it from a record via WriteTable, '
                . 'e.g. tt_content field "assets": [{"uid_local": ' . $file->getUid() . ', "alternative": "<alt text>"}].';
        }

        return $this->createJsonResult($data);
    }
}
