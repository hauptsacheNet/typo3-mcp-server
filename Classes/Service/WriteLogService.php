<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tracks a monotonically increasing write counter per (BE user, table, live UID).
 *
 * The counter is exposed to MCP App widgets so a widget rendered for an older
 * write of the same record can recognise itself as superseded when a newer
 * write occurs, even if BroadcastChannel coordination is unavailable.
 */
class WriteLogService
{
    private const CACHE_IDENTIFIER = 'mcp_write_log';

    /**
     * 30-day TTL keeps the counter alive across long-running conversations
     * without growing the cache indefinitely.
     */
    private const TTL_SECONDS = 60 * 60 * 24 * 30;

    /**
     * Record a new write and return the new writeId.
     */
    public function recordWrite(string $table, int $liveUid): int
    {
        $cache = $this->getCache();
        $key = $this->buildKey($table, $liveUid);

        $current = (int)($cache->get($key) ?: 0);
        $next = $current + 1;
        $cache->set($key, $next, [], self::TTL_SECONDS);

        return $next;
    }

    /**
     * Get the most recent writeId without advancing the counter.
     * Returns 0 when no write for this (user, table, uid) has been recorded.
     */
    public function getCurrentWriteId(string $table, int $liveUid): int
    {
        $cache = $this->getCache();
        $key = $this->buildKey($table, $liveUid);

        return (int)($cache->get($key) ?: 0);
    }

    private function buildKey(string $table, int $liveUid): string
    {
        $beUserUid = (int)($GLOBALS['BE_USER']->user['uid'] ?? 0);
        // Cache identifiers must be limited to [a-zA-Z0-9_-]; table names already qualify.
        return 'mcp_write_' . $beUserUid . '_' . $table . '_' . $liveUid;
    }

    private function getCache(): FrontendInterface
    {
        return GeneralUtility::makeInstance(CacheManager::class)->getCache(self::CACHE_IDENTIFIER);
    }
}
