<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\Tca\TcaFactory;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Impersonates a backend user outside the regular backend authentication flow.
 *
 * MCP reaches TYPO3 through three doors that all bypass the
 * BackendUserAuthenticator middleware: the /mcp endpoint (token auth), the
 * pre-signed upload endpoint (upload token) and the CLI commands (stdio). Each
 * of them needs the same delicate setup, and getting one step wrong has caused
 * real damage before - a missing uc restore wiped users' backend preferences
 * (#107). Hence one place instead of four copies.
 */
class BackendUserContextService implements SingletonInterface
{
    /**
     * Build the backend user context for the given uid: loads the user record,
     * restores its stored configuration, computes group permissions, and wires
     * up $GLOBALS['BE_USER'], $GLOBALS['LANG'], the TCA and the Context API.
     *
     * The workspace aspect is set from the user's persisted workspace; callers
     * that switch workspaces afterwards must update it via
     * {@see updateWorkspaceAspect()}.
     *
     * @throws \InvalidArgumentException when the user does not exist or may not act
     */
    public function impersonate(int $userId): BackendUserAuthentication
    {
        $userData = $this->loadUserRecord($userId);
        if ($userData === null) {
            throw new \InvalidArgumentException(
                'Backend user ' . $userId . ' does not exist, is deleted, disabled, or outside its validity period.',
                1753900000
            );
        }

        $beUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $beUser->user = $userData;
        $this->restoreStoredUserConfiguration($beUser);
        $GLOBALS['BE_USER'] = $beUser;

        // Normal requests go through the BackendUserAuthenticator middleware,
        // which wires up a real UserSession. Without one, DataHandler write paths
        // that touch setAndSaveSessionData() (FlashMessageQueue,
        // BackendFormProtection) crash with "Call to a member function set() on
        // null". An anonymous in-memory session is discarded at request end,
        // which is all a stateless MCP request needs.
        $beUser->initializeUserSessionManager();

        // Computes tables_select, tables_modify, non_exclude_fields, webmounts,
        // ... - without it non-admin users have no permissions at all.
        $beUser->fetchGroupData();

        // Apply uc defaults and TSconfig overrides exactly like
        // initializeBackendLogin() does after fetchGroupData(). Covers users who
        // never logged into the backend: their stored uc is empty, and core only
        // fills in defaults while uc is completely empty, so without this the
        // first writeUC() would persist a nearly empty uc for good.
        $beUser->backendSetUC();

        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)
            ->createFromUserPreferences($beUser);

        $this->ensureTcaIsLoaded();

        $context = GeneralUtility::makeInstance(Context::class);
        $context->setAspect('backend.user', new UserAspect($beUser));
        $context->setAspect('workspace', new WorkspaceAspect((int)$beUser->workspace));

        return $beUser;
    }

    /**
     * Keep the workspace aspect in sync after a workspace switch.
     */
    public function updateWorkspaceAspect(int $workspaceId): void
    {
        GeneralUtility::makeInstance(Context::class)
            ->setAspect('workspace', new WorkspaceAspect($workspaceId));
    }

    /**
     * Restore the user's stored configuration (uc), which the regular
     * authentication flow unserializes via unpack_uc().
     *
     * Without this, $beUser->uc starts out empty and any writeUC() during
     * request processing - the update signals fired when the MCP workspace is
     * created, for instance - overwrites the user's backend preferences with a
     * nearly empty array. Core never repairs that, because backendSetUC() only
     * fills in defaults while uc is completely empty, so the backend Setup
     * module stays broken ("Undefined array key titleLen").
     *
     * Public because the CLI commands build their user object themselves.
     */
    public function restoreStoredUserConfiguration(BackendUserAuthentication $beUser): void
    {
        $userId = (int)($beUser->user['uid'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        $storedUc = $beUser->user['uc'] ?? null;
        if (!is_string($storedUc) || $storedUc === '') {
            $storedUc = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable('be_users')
                ->select(['uc'], 'be_users', ['uid' => $userId])
                ->fetchOne();
        }

        if (is_string($storedUc) && $storedUc !== '') {
            $uc = unserialize($storedUc, ['allowed_classes' => false]);
            if (is_array($uc)) {
                $beUser->uc = $uc;
            }
        }
    }

    /**
     * Load a backend user record, but only when the user may currently act.
     */
    protected function loadUserRecord(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $userData = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('be_users')
            ->select(['*'], 'be_users', ['uid' => $userId])
            ->fetchAssociative();

        if (!$userData || !$this->isUserAvailable($userData)) {
            return null;
        }
        return $userData;
    }

    /**
     * Deleted, disabled, and not-yet/no-longer-valid users must not act, no
     * matter how valid the token they present is.
     */
    protected function isUserAvailable(array $userData): bool
    {
        $now = time();
        if (!empty($userData['deleted']) || !empty($userData['disable'])) {
            return false;
        }
        $startTime = (int)($userData['starttime'] ?? 0);
        $endTime = (int)($userData['endtime'] ?? 0);

        return ($startTime === 0 || $startTime <= $now) && ($endTime === 0 || $endTime > $now);
    }

    /**
     * The MCP entry points run before the usual TCA bootstrap.
     */
    protected function ensureTcaIsLoaded(): void
    {
        if (empty($GLOBALS['TCA'])) {
            $GLOBALS['TCA'] = GeneralUtility::getContainer()->get(TcaFactory::class)->get();
        }
    }
}
