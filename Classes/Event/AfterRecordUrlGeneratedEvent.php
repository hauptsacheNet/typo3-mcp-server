<?php

declare(strict_types=1);

namespace Hn\McpServer\Event;

use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * PSR-14 event dispatched after RecordUrlService generated the URL of a page or
 * record (frontend_url and preview_url), before a preview token is appended.
 *
 * The URL comes from the site router, so it points at the TYPO3 site base.
 * Listeners can rewrite it where visitors see the site somewhere else, for
 * example a decoupled frontend. HeadlessFrontendUrlListener does this for
 * EXT:headless; other setups can register their own listener.
 */
final class AfterRecordUrlGeneratedEvent
{
    /**
     * @param string $url Absolute URL without fragment and without preview token
     * @param bool $preview True for the workspace preview URL, false for the live URL
     */
    public function __construct(
        private string $url,
        private readonly Site $site,
        private readonly SiteLanguage $language,
        private readonly int $pageId,
        private readonly bool $preview,
    ) {}

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): void
    {
        $this->url = $url;
    }

    public function getSite(): Site
    {
        return $this->site;
    }

    public function getLanguage(): SiteLanguage
    {
        return $this->language;
    }

    public function getPageId(): int
    {
        return $this->pageId;
    }

    public function isPreview(): bool
    {
        return $this->preview;
    }
}
