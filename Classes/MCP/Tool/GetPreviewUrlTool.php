<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;
use Hn\McpServer\Service\LanguageService as McpLanguageService;
use Hn\McpServer\Service\SiteInformationService;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Workspaces\Preview\PreviewUriBuilder;

/**
 * Tool for getting a frontend URL that shows the draft of a page without a backend login
 */
class GetPreviewUrlTool extends AbstractRecordTool
{
    private const DEFAULT_LIFETIME_HOURS = 48;

    public function __construct(
        protected SiteInformationService $siteInformationService,
        protected McpLanguageService $languageService,
        protected PreviewUriBuilder $previewUriBuilder,
    ) {
        parent::__construct();
    }

    public function getSchema(): array
    {
        $schema = [
            'description' => 'Get a frontend URL that renders a page including all pending changes, so the result of edits can be checked in a browser without a backend login. '
                . 'The link is temporary and grants anonymous read access to all pending changes of the workspace, not just this page, so treat it as a secret and only request it when you are going to open it. '
                . 'Only the first request needs the token in the URL, later requests rely on a cookie: a browser without cookies shows the published page instead. '
                . 'The draft is shown when the page contains a preview notice before </body> (unless config.disablePreviewNotification is set).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'The page ID to get the preview URL for',
                    ],
                ],
                'required' => ['uid'],
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => false,
            ],
        ];

        $availableLanguages = $this->languageService->getAvailableIsoCodes();
        if (count($availableLanguages) > 1) {
            $schema['inputSchema']['properties']['language'] = [
                'type' => 'string',
                'description' => 'Language ISO code of the page version to preview (e.g., "de", "fr"). Defaults to the default language.',
                'enum' => $availableLanguages,
            ];
        }

        return $schema;
    }

    protected function doExecute(array $params): CallToolResult
    {
        $uid = (int)($params['uid'] ?? 0);
        if ($uid <= 0 || !$this->canReadPage($uid)) {
            throw new \InvalidArgumentException('Page not found: ' . $uid);
        }

        $languageId = 0;
        if (isset($params['language'])) {
            $languageId = $this->languageService->getUidFromIsoCode($params['language']);
            if ($languageId === null) {
                throw new \InvalidArgumentException('Unknown language code: ' . $params['language']);
            }
        }

        if ($this->workspaceContextService->getCurrentWorkspace() === 0) {
            $url = $this->siteInformationService->generatePageUrl($uid, $languageId);
            if ($url === null) {
                throw new \RuntimeException('Could not generate a URL for page ' . $uid);
            }

            return $this->createSuccessResult("URL: {$url}\n\nThis is the public URL, there is no draft to preview.");
        }

        $url = $this->siteInformationService->makeAbsoluteUrl(
            $this->previewUriBuilder->buildUriForPage($uid, $languageId)
        );
        $hours = $this->getPreviewLinkLifetimeHours();

        return $this->createSuccessResult(
            "Preview URL: {$url}\n\n"
            . "Valid for {$hours} hours. Shows the page including pending changes, no login required. Anyone with this link can see all pending changes until it expires, so do not share it.\n"
            . 'Keep cookies enabled when opening it: only the first request carries the token, later requests use a cookie. '
            . 'Without cookies the published page is shown. The preview notice before </body> confirms the draft is shown.'
        );
    }

    private function canReadPage(int $uid): bool
    {
        $backendUser = $GLOBALS['BE_USER'];

        return BackendUtility::readPageAccess($uid, $backendUser->getPagePermsClause(Permission::PAGE_SHOW)) !== false;
    }

    /**
     * Mirrors PreviewUriBuilder's TTL fallback chain, which it does not expose.
     */
    private function getPreviewLinkLifetimeHours(): int
    {
        $backendUser = $GLOBALS['BE_USER'];
        $workspace = BackendUtility::getRecord('sys_workspace', (int)$backendUser->workspace, 'previewlink_lifetime');
        if ((int)($workspace['previewlink_lifetime'] ?? 0) > 0) {
            return (int)$workspace['previewlink_lifetime'];
        }

        return (int)($backendUser->getTSConfig()['options.']['workspaces.']['previewLinkTTLHours'] ?? 0) ?: self::DEFAULT_LIFETIME_HOURS;
    }
}
