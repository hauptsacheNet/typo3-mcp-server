<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Hn\McpServer\Exception\InvalidClientMetadataException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use Psr\Http\Message\ServerRequestInterface;

/**
 * OAuth service for MCP server authentication
 */
class OAuthService
{
    public const WELL_KNOWN_CLIENT_ID = 'typo3-mcp-server';
    /**
     * Sentinel used only by the seeded well-known client to mean "accept any
     * loopback URI" (RFC 8252 native app pattern). Dynamic registrations
     * cannot store this value.
     */
    public const REDIRECT_URI_LOOPBACK_SENTINEL = 'loopback:*';
    private const CLIENTS_TABLE = 'tx_mcpserver_oauth_clients';
    private const CODE_EXPIRY_SECONDS = 600; // 10 minutes

    /**
     * Default lifetimes, each overridable in the extension configuration.
     *
     * A client that registered the refresh_token grant gets a short-lived
     * access token and renews it silently, so a token that leaks (it is sent
     * with every request) is useless after an hour. Clients without refresh
     * support get a token for a working day. Tokens created by hand (backend
     * module, CLI) are for tools that cannot do OAuth at all and live longer.
     */
    private const DEFAULT_ACCESS_TOKEN_MINUTES = 60;
    private const DEFAULT_TOKEN_HOURS_WITHOUT_REFRESH = 12;
    private const DEFAULT_REFRESH_TOKEN_DAYS = 30;
    private const DEFAULT_MANUAL_TOKEN_DAYS = 30;

    /**
     * A refresh token that was just replaced is answered with invalid_grant
     * for this long instead of ending the session: clients that refresh twice
     * in parallel or retry after a lost response would otherwise log their
     * user out. Used later than that, it is treated as stolen.
     */
    private const REFRESH_REUSE_GRACE_SECONDS = 60;

    private const REFRESH_HISTORY_TABLE = 'tx_mcpserver_refresh_token_history';

    /**
     * The lifetime settings, with whether 0 is a valid value ("no limit").
     */
    private const LIFETIME_SETTINGS = [
        'oauthAccessTokenMinutes' => false,
        'oauthTokenHoursWithoutRefresh' => false,
        'oauthRefreshTokenDays' => false,
        'oauthMaxSessionHours' => true,
        'manualTokenDays' => false,
    ];

    /**
     * Generate authorization URL for OAuth flow
     */
    public function generateAuthorizationUrl(string $baseUrl, string $clientName = '', string $redirectUri = '', string $codeChallenge = '', string $challengeMethod = 'S256', string $state = ''): string
    {
        $params = [
            'client_id' => self::WELL_KNOWN_CLIENT_ID,
            'response_type' => 'code',
            'client_name' => $clientName,
        ];

        if (!empty($redirectUri)) {
            $params['redirect_uri'] = $redirectUri;
        }

        if (!empty($codeChallenge)) {
            $params['code_challenge'] = $codeChallenge;
            $params['code_challenge_method'] = $challengeMethod;
        }

        if (!empty($state)) {
            $params['state'] = $state;
        }

        return rtrim($baseUrl, '/') . '/mcp_oauth/authorize?' . http_build_query($params);
    }

    /**
     * Create authorization code for authenticated user.
     *
     * The $clientId binds the issued code to the registered OAuth client per
     * RFC 6749 §10.5; the token endpoint must reject any redemption by a
     * different client. $clientName is the free-text display label kept for
     * audit on the access token.
     */
    public function createAuthorizationCode(int $beUserId, string $clientName, string $redirectUri = '', string $pkceChallenge = '', string $challengeMethod = 'S256', string $clientId = ''): string
    {
        $code = $this->generateSecureToken();
        $expires = time() + self::CODE_EXPIRY_SECONDS;

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_oauth_codes');

        $connection->insert(
            'tx_mcpserver_oauth_codes',
            [
                'pid' => 0,
                'tstamp' => time(),
                'crdate' => time(),
                'code' => $code,
                'be_user_uid' => $beUserId,
                'client_id' => $clientId,
                'client_name' => $clientName,
                'pkce_challenge' => $pkceChallenge,
                'pkce_challenge_method' => $challengeMethod,
                'redirect_uri' => $redirectUri,
                'expires' => $expires,
            ]
        );

        return $code;
    }

    /**
     * Exchange authorization code for access token.
     *
     * If $clientId is provided and the code was issued to a specific client,
     * the two MUST match (RFC 6749 §10.5). A code without a stored client_id
     * (legacy data from before code-to-client binding was introduced) is
     * accepted regardless — those expire within {@see self::CODE_EXPIRY_SECONDS}.
     */
    public function exchangeCodeForToken(string $code, ?string $codeVerifier = null, ?ServerRequestInterface $request = null, ?string $redirectUri = null, ?string $clientId = null): ?array
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_oauth_codes');

        $queryBuilder = $connection->createQueryBuilder();
        $authCode = $queryBuilder
            ->select('*')
            ->from('tx_mcpserver_oauth_codes')
            ->where(
                $queryBuilder->expr()->eq('code', $queryBuilder->createNamedParameter($code)),
                $queryBuilder->expr()->gt('expires', $queryBuilder->createNamedParameter(time())),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0))
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!$authCode) {
            return null;
        }

        // RFC 6749 §10.5: a code issued to one client must not be redeemable
        // by a different client. Empty stored client_id means the code pre-dates
        // binding (during the upgrade window) and is accepted.
        if (!empty($authCode['client_id'])) {
            if ($clientId === null || $clientId !== $authCode['client_id']) {
                return null;
            }
        }

        // RFC 6749 §4.1.3: if a redirect_uri was used in the auth request, the token
        // request MUST include the same value. If none was used, accept missing.
        if (!empty($authCode['redirect_uri'])) {
            if ($redirectUri === null || $redirectUri !== $authCode['redirect_uri']) {
                return null;
            }
        }

        // Verify PKCE: if a challenge was set, the verifier is mandatory
        if (!empty($authCode['pkce_challenge'])) {
            if ($codeVerifier === null) {
                return null;
            }
            // Only S256 is supported
            if (($authCode['pkce_challenge_method'] ?? '') !== 'S256') {
                return null;
            }
            $computedChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
            if (!hash_equals($computedChallenge, $authCode['pkce_challenge'])) {
                return null;
            }
        }

        // Get client IP
        $clientIp = '';
        if ($request !== null) {
            $clientIp = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        }

        // Atomically consume the authorization code BEFORE issuing the token, so
        // concurrent redemption attempts with the same code can't both mint a
        // token (RFC 6749 §4.1.2 one-time-use requirement). Only the request
        // that actually removes the row proceeds.
        $consumed = $connection->delete(
            'tx_mcpserver_oauth_codes',
            ['uid' => $authCode['uid']]
        );
        if ($consumed === 0) {
            return null;
        }

        // Resolve the issuing client so the token is linked to it for cascade
        // revocation and observability.
        $clientUid = 0;
        $supportsRefresh = false;
        if (!empty($authCode['client_id'])) {
            $boundClient = $this->getClient((string)$authCode['client_id']);
            if ($boundClient !== null) {
                $clientUid = (int)$boundClient['uid'];
                $supportsRefresh = in_array('refresh_token', $boundClient['grant_types'], true);
                $this->touchClient($clientUid);
            }
        }

        $now = time();
        $accessToken = $this->generateSecureToken();
        $accessLifetime = $supportsRefresh ? $this->getAccessTokenLifetime() : $this->getTokenLifetimeWithoutRefresh();
        $maxSession = $this->getMaxSessionLifetime();
        if ($maxSession > 0) {
            $accessLifetime = min($accessLifetime, $maxSession);
        }
        $row = [
            'pid' => 0,
            'tstamp' => $now,
            'crdate' => $now,
            'token' => $this->hashToken($accessToken),
            'be_user_uid' => $authCode['be_user_uid'],
            'client_uid' => $clientUid,
            'client_name' => $authCode['client_name'],
            'expires' => $now + $accessLifetime,
            'last_used' => $now,
            'created_ip' => $clientIp,
            'last_used_ip' => $clientIp,
            'token_version' => 1,
        ];
        $refreshToken = null;
        if ($supportsRefresh) {
            $refreshToken = $this->generateSecureToken();
            $row['refresh_token'] = $this->hashToken($refreshToken);
            $row['refresh_expires'] = $now + ($maxSession > 0 ? min($this->getRefreshTokenLifetime(), $maxSession) : $this->getRefreshTokenLifetime());
        }

        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens')
            ->insert('tx_mcpserver_access_tokens', $row);

        return $this->buildTokenResponse($accessToken, $accessLifetime, $refreshToken);
    }

    /**
     * Exchange a refresh token for a new access token (RFC 6749 §6).
     *
     * The refresh token is rotated with every use: the response carries a new
     * one and the old one stops working. One row in tx_mcpserver_access_tokens
     * holds the whole grant, so the backend module lists one entry per
     * connection and revoking it ends access and refresh token together.
     *
     * A replaced refresh token presented again after the short grace period
     * means two parties hold it, so the grant is revoked (RFC 9700 §4.14.2).
     *
     * @param array $client The authenticated client (getClient()), whose grant it must be
     */
    public function refreshAccessToken(string $refreshToken, array $client, ?ServerRequestInterface $request = null): ?array
    {
        if ($refreshToken === '') {
            return null;
        }
        $refreshHash = $this->hashToken($refreshToken);
        $clientUid = (int)$client['uid'];
        $now = time();

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens');
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();
        $grant = $queryBuilder
            ->select('*')
            ->from('tx_mcpserver_access_tokens')
            ->where(
                $queryBuilder->expr()->eq('refresh_token', $queryBuilder->createNamedParameter($refreshHash)),
                $queryBuilder->expr()->eq('client_uid', $queryBuilder->createNamedParameter($clientUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt('refresh_expires', $queryBuilder->createNamedParameter($now, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!$grant) {
            $this->handleReplacedRefreshToken($refreshHash, $clientUid, $now);
            return null;
        }
        if (!$this->clientUidIsActive($clientUid) || !$this->isBackendUserActive((int)$grant['be_user_uid'])) {
            return null;
        }

        // The session ends at the configured maximum after the authorization,
        // however actively it is used: the user has to log in again.
        $sessionEnd = PHP_INT_MAX;
        $maxSession = $this->getMaxSessionLifetime();
        if ($maxSession > 0) {
            $sessionEnd = (int)$grant['crdate'] + $maxSession;
            if ($now >= $sessionEnd) {
                return null;
            }
        }

        $accessToken = $this->generateSecureToken();
        $newRefreshToken = $this->generateSecureToken();
        $accessLifetime = min($this->getAccessTokenLifetime(), $sessionEnd - $now);
        $clientIp = $request !== null ? (string)($request->getServerParams()['REMOTE_ADDR'] ?? '') : '';

        // Rotation and history entry commit together: a rotation without its
        // history entry would leave the replaced token unrecognised, and the
        // client without a usable pair if the second write failed.
        $rotated = $connection->transactional(function (Connection $connection) use ($grant, $refreshHash, $accessToken, $newRefreshToken, $now, $accessLifetime, $sessionEnd, $clientIp): bool {
            // Only the request that still finds the old refresh token rotates it:
            // two parallel refreshes cannot both get a new pair.
            $updateBuilder = $connection->createQueryBuilder();
            $affected = $updateBuilder
                ->update('tx_mcpserver_access_tokens')
                ->set('token', $this->hashToken($accessToken))
                ->set('token_version', 1)
                ->set('expires', $now + $accessLifetime)
                ->set('refresh_token', $this->hashToken($newRefreshToken))
                ->set('refresh_expires', min($now + $this->getRefreshTokenLifetime(), $sessionEnd))
                ->set('tstamp', $now)
                ->set('last_used', $now)
                ->set('last_used_ip', $clientIp)
                ->where(
                    $updateBuilder->expr()->eq('uid', $updateBuilder->createNamedParameter((int)$grant['uid'], Connection::PARAM_INT)),
                    $updateBuilder->expr()->eq('refresh_token', $updateBuilder->createNamedParameter($refreshHash))
                )
                ->executeStatement();
            if ($affected === 0) {
                return false;
            }

            // Every replaced refresh token stays known to its grant, not only the
            // last one: whoever refreshes twice with a stolen token must not leave
            // the legitimate client's older token unrecognised.
            $connection->insert(self::REFRESH_HISTORY_TABLE, [
                'refresh_token' => $refreshHash,
                'grant_uid' => (int)$grant['uid'],
                'replaced' => $now,
                // Until then the replaced token would have been valid: as long
                // as it could be used, its reuse must be recognised
                'expires' => (int)$grant['refresh_expires'],
            ]);
            return true;
        });
        if (!$rotated) {
            return null;
        }

        $this->touchClient($clientUid);

        return $this->buildTokenResponse($accessToken, $accessLifetime, $newRefreshToken);
    }

    /**
     * A refresh token that is no longer current: within the grace period
     * after its rotation it is only refused, later the grant is revoked.
     */
    private function handleReplacedRefreshToken(string $refreshHash, int $clientUid, int $now): void
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable(self::REFRESH_HISTORY_TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $replaced = $queryBuilder
            ->select('h.grant_uid', 'h.replaced')
            ->from(self::REFRESH_HISTORY_TABLE, 'h')
            ->join('h', 'tx_mcpserver_access_tokens', 't', $queryBuilder->expr()->eq('t.uid', $queryBuilder->quoteIdentifier('h.grant_uid')))
            ->where(
                $queryBuilder->expr()->eq('h.refresh_token', $queryBuilder->createNamedParameter($refreshHash)),
                $queryBuilder->expr()->eq('t.client_uid', $queryBuilder->createNamedParameter($clientUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t.deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($replaced && $now - (int)$replaced['replaced'] > self::REFRESH_REUSE_GRACE_SECONDS) {
            GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable('tx_mcpserver_access_tokens')
                ->update(
                    'tx_mcpserver_access_tokens',
                    ['deleted' => 1, 'tstamp' => $now],
                    ['uid' => (int)$replaced['grant_uid']]
                );
        }
    }

    /**
     * Token response (RFC 6749 §5.1).
     */
    private function buildTokenResponse(string $accessToken, int $lifetime, ?string $refreshToken): array
    {
        $response = [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $lifetime,
        ];
        if ($refreshToken !== null) {
            $response['refresh_token'] = $refreshToken;
        }
        return $response;
    }

    /**
     * The backend user behind a grant still exists, is enabled and within its
     * start and end time. A refresh must not keep a disabled account alive.
     */
    private function isBackendUserActive(int $beUserUid): bool
    {
        // The default restrictions cover deleted, disable, starttime and endtime
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('be_users');
        return (bool)$queryBuilder
            ->count('uid')
            ->from('be_users')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($beUserUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Validate access token and return user info
     */
    public function validateToken(string $token, ?ServerRequestInterface $request = null): ?array
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens');

        // Try hashed lookup first (token_version=1, post-migration)
        $queryBuilder = $connection->createQueryBuilder();
        $tokenRecord = $queryBuilder
            ->select('*')
            ->from('tx_mcpserver_access_tokens')
            ->where(
                $queryBuilder->expr()->eq('token', $queryBuilder->createNamedParameter($this->hashToken($token))),
                $queryBuilder->expr()->gt('expires', $queryBuilder->createNamedParameter(time())),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0))
            )
            ->executeQuery()
            ->fetchAssociative();

        // Fallback: try plaintext lookup for pre-migration tokens (token_version=0)
        if (!$tokenRecord) {
            $queryBuilder = $connection->createQueryBuilder();
            $tokenRecord = $queryBuilder
                ->select('*')
                ->from('tx_mcpserver_access_tokens')
                ->where(
                    $queryBuilder->expr()->eq('token', $queryBuilder->createNamedParameter($token)),
                    $queryBuilder->expr()->eq('token_version', $queryBuilder->createNamedParameter(0)),
                    $queryBuilder->expr()->gt('expires', $queryBuilder->createNamedParameter(time())),
                    $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0))
                )
                ->executeQuery()
                ->fetchAssociative();
        }

        if (!$tokenRecord) {
            return null;
        }

        // Cascade revocation: a token whose issuing client has been removed
        // is no longer valid. Tokens with client_uid=0 are legacy (pre-binding)
        // and are accepted as before.
        $clientUid = (int)($tokenRecord['client_uid'] ?? 0);
        if ($clientUid > 0 && !$this->clientUidIsActive($clientUid)) {
            return null;
        }

        // Auto-upgrade version-0 (plaintext) tokens to hashed on successful validation.
        // Best-effort: if the upgrade fails, authentication still succeeds and the
        // upgrade will be retried on next validation or handled by the upgrade wizard.
        if ((int)($tokenRecord['token_version'] ?? 0) === 0) {
            try {
                $upgradeBuilder = $connection->createQueryBuilder();
                $upgradeBuilder
                    ->update('tx_mcpserver_access_tokens')
                    ->where(
                        $upgradeBuilder->expr()->eq('uid', $upgradeBuilder->createNamedParameter($tokenRecord['uid'])),
                        $upgradeBuilder->expr()->eq('token_version', $upgradeBuilder->createNamedParameter(0))
                    )
                    ->set('token', $this->hashToken($token))
                    ->set('token_version', 1)
                    ->executeStatement();
            } catch (\Throwable $e) {
                // Non-fatal: token is valid, upgrade will be retried later
            }
        }

        // Update last used timestamp and IP (best-effort, must not block authentication)
        try {
            $clientIp = '';
            if ($request !== null) {
                $clientIp = $request->getServerParams()['REMOTE_ADDR'] ?? '';
            }

            $queryBuilder = $connection->createQueryBuilder();
            $queryBuilder
                ->update('tx_mcpserver_access_tokens')
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($tokenRecord['uid'])))
                ->set('last_used', time())
                ->set('last_used_ip', $clientIp)
                ->executeStatement();
        } catch (\Throwable $e) {
            // Non-fatal: audit trail update failure must not prevent authentication
        }

        return [
            'be_user_uid' => (int)$tokenRecord['be_user_uid'],
            'client_uid' => (int)($tokenRecord['client_uid'] ?? 0),
            'client_name' => $tokenRecord['client_name'],
            'token_uid' => (int)$tokenRecord['uid'],
        ];
    }

    /**
     * Get all active tokens for a user
     */
    public function getUserTokens(int $beUserId): array
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens');

        $queryBuilder = $connection->createQueryBuilder();
        $tokens = $queryBuilder
            ->select('*')
            ->from('tx_mcpserver_access_tokens')
            ->where(
                $queryBuilder->expr()->eq('be_user_uid', $queryBuilder->createNamedParameter($beUserId)),
                // A grant whose access token expired lives on while its refresh token does
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->gt('expires', $queryBuilder->createNamedParameter(time())),
                    $queryBuilder->expr()->gt('refresh_expires', $queryBuilder->createNamedParameter(time()))
                ),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0))
            )
            ->orderBy('crdate', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return $tokens ?: [];
    }

    /**
     * Revoke a specific token
     */
    public function revokeToken(int $tokenUid, int $beUserId): bool
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens');

        $affectedRows = $connection->update(
            'tx_mcpserver_access_tokens',
            ['deleted' => 1, 'tstamp' => time()],
            [
                'uid' => $tokenUid,
                'be_user_uid' => $beUserId,
            ]
        );

        return $affectedRows > 0;
    }

    /**
     * Revoke all tokens for a user
     */
    public function revokeAllUserTokens(int $beUserId): int
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens');

        return $connection->update(
            'tx_mcpserver_access_tokens',
            ['deleted' => 1, 'tstamp' => time()],
            ['be_user_uid' => $beUserId]
        );
    }

    /**
     * Clean up expired codes and tokens
     */
    public function cleanupExpired(): void
    {
        $currentTime = time();

        // Connection::delete()/update() compare their criteria for equality,
        // so a range condition needs the query builder.
        $codeQueryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_mcpserver_oauth_codes');
        $codeQueryBuilder
            ->delete('tx_mcpserver_oauth_codes')
            ->where($codeQueryBuilder->expr()->lt(
                'expires',
                $codeQueryBuilder->createNamedParameter($currentTime, \Doctrine\DBAL\ParameterType::INTEGER)
            ))
            ->executeStatement();

        // Mark expired tokens as deleted
        $tokenQueryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_mcpserver_access_tokens');
        $tokenQueryBuilder
            ->update('tx_mcpserver_access_tokens')
            ->set('deleted', 1)
            ->set('tstamp', $currentTime)
            ->where(
                $tokenQueryBuilder->expr()->eq('deleted', $tokenQueryBuilder->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER)),
                $tokenQueryBuilder->expr()->lt(
                    'expires',
                    $tokenQueryBuilder->createNamedParameter($currentTime, \Doctrine\DBAL\ParameterType::INTEGER)
                ),
                $tokenQueryBuilder->expr()->lt(
                    'refresh_expires',
                    $tokenQueryBuilder->createNamedParameter($currentTime, \Doctrine\DBAL\ParameterType::INTEGER)
                )
            )
            ->executeStatement();

        // A replaced refresh token is remembered for as long as it would have
        // been valid; after that it is refused like any unknown token. Its own
        // expiry counts, not the current setting, which may have been lowered.
        $historyQueryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable(self::REFRESH_HISTORY_TABLE);
        $historyQueryBuilder
            ->delete(self::REFRESH_HISTORY_TABLE)
            ->where($historyQueryBuilder->expr()->lt(
                'expires',
                $historyQueryBuilder->createNamedParameter($currentTime, \Doctrine\DBAL\ParameterType::INTEGER)
            ))
            ->executeStatement();

        $this->cleanupStaleClients($currentTime);
    }

    /**
     * Soft-delete stale dynamically registered clients.
     *
     * Some MCP clients (notably claude.ai) register a NEW client on every fresh
     * connection, so tx_mcpserver_oauth_clients grows unboundedly without cleanup.
     * A client is stale when it has seen no activity for a full token lifetime
     * AND holds no live token — deleting a client cascade-revokes its tokens
     * (see {@see self::validateToken()}), so active clients must be kept.
     * The well-known client is never removed.
     */
    private function cleanupStaleClients(int $currentTime): void
    {
        $threshold = $currentTime - max(
            $this->getRefreshTokenLifetime(),
            $this->getTokenLifetimeWithoutRefresh(),
            $this->getManualTokenLifetime()
        );

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(self::CLIENTS_TABLE);
        $qb = $connection->createQueryBuilder();
        $candidateUids = $qb
            ->select('uid')
            ->from(self::CLIENTS_TABLE)
            ->where(
                $qb->expr()->neq('client_id', $qb->createNamedParameter(self::WELL_KNOWN_CLIENT_ID)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0)),
                $qb->expr()->lt('crdate', $qb->createNamedParameter($threshold)),
                $qb->expr()->lt('last_used', $qb->createNamedParameter($threshold))
            )
            ->executeQuery()
            ->fetchFirstColumn();
        $candidateUids = array_map('intval', $candidateUids);

        if (empty($candidateUids)) {
            return;
        }

        $tokenConnection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens');
        $tqb = $tokenConnection->createQueryBuilder();
        $activeUids = $tqb
            ->select('client_uid')
            ->distinct()
            ->from('tx_mcpserver_access_tokens')
            ->where(
                $tqb->expr()->in('client_uid', $tqb->createNamedParameter($candidateUids, \Doctrine\DBAL\ArrayParameterType::INTEGER)),
                $tqb->expr()->eq('deleted', $tqb->createNamedParameter(0)),
                $tqb->expr()->or(
                    $tqb->expr()->gt('expires', $tqb->createNamedParameter($currentTime)),
                    $tqb->expr()->gt('refresh_expires', $tqb->createNamedParameter($currentTime))
                )
            )
            ->executeQuery()
            ->fetchFirstColumn();
        $activeUids = array_map('intval', $activeUids);

        $staleUids = array_values(array_diff($candidateUids, $activeUids));
        if (empty($staleUids)) {
            return;
        }

        $updateQb = $connection->createQueryBuilder();
        $updateQb
            ->update(self::CLIENTS_TABLE)
            ->where($updateQb->expr()->in('uid', $updateQb->createNamedParameter($staleUids, \Doctrine\DBAL\ArrayParameterType::INTEGER)))
            ->set('deleted', 1)
            ->set('tstamp', $currentTime)
            ->executeStatement();
    }

    /**
     * Record client activity so {@see self::cleanupStaleClients()} does not
     * remove clients that are still in use. Best-effort.
     */
    private function touchClient(int $clientUid): void
    {
        if ($clientUid <= 0) {
            return;
        }
        try {
            GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable(self::CLIENTS_TABLE)
                ->update(
                    self::CLIENTS_TABLE,
                    ['last_used' => time(), 'tstamp' => time()],
                    ['uid' => $clientUid]
                );
        } catch (\Throwable $e) {
            // Non-fatal: activity tracking must not break the token flow
        }
    }

    /**
     * Reject client metadata the client table cannot hold.
     *
     * Registration is open to anyone, so nothing here may be trusted to have a
     * sane size or type: an over-long value would otherwise be truncated or,
     * with a strict SQL mode, fail the insert with a database error.
     *
     * @throws InvalidClientMetadataException
     */
    private function assertClientMetadataFitsStorage(mixed $clientName, mixed $scope, array $grantTypes, array $redirectUris): void
    {
        foreach (['client_name' => $clientName, 'scope' => $scope] as $field => $value) {
            if (!is_string($value)) {
                throw new InvalidClientMetadataException($field . ' must be a string');
            }
            if (mb_strlen($value) > 255) {
                throw new InvalidClientMetadataException($field . ' must not be longer than 255 characters');
            }
        }

        foreach ($grantTypes as $grantType) {
            if (!is_string($grantType)) {
                throw new InvalidClientMetadataException('grant_types must be a list of strings');
            }
        }
        if (strlen((string)json_encode($grantTypes)) > 255) {
            throw new InvalidClientMetadataException('grant_types is too long');
        }

        if (count($redirectUris) > 20) {
            throw new InvalidClientMetadataException('No more than 20 redirect_uris are allowed');
        }
        foreach ($redirectUris as $uri) {
            if (strlen($uri) > 2048) {
                throw new InvalidClientMetadataException('A redirect_uri must not be longer than 2048 characters');
            }
        }
        // Stored JSON-encoded in a text column (64 KiB); json_encode() escapes
        // every "/", so the stored value can be about twice the raw length.
        if (strlen((string)json_encode($redirectUris)) > 65535) {
            throw new InvalidClientMetadataException('redirect_uris is too long');
        }
    }

    /**
     * Register a new OAuth client dynamically (RFC 7591).
     *
     * The plain client_secret (if any) is returned to the caller exactly once
     * and only the SHA-256 hash is persisted. Public clients (the default for
     * MCP, since they use PKCE) do not receive a secret.
     */
    public function registerClient(array $clientData): array
    {
        $authMethod = $clientData['token_endpoint_auth_method'] ?? 'none';
        if (!in_array($authMethod, ['none', 'client_secret_post', 'client_secret_basic'], true)) {
            $authMethod = 'none';
        }

        $redirectUris = $clientData['redirect_uris'] ?? [];
        if (!is_array($redirectUris)) {
            $redirectUris = [];
        }
        $redirectUris = array_values(array_filter(array_map(
            fn($v) => is_string($v) ? trim($v) : '',
            $redirectUris
        )));
        // Reject sentinels reserved for the seeded well-known client
        $reserved = ['*', self::REDIRECT_URI_LOOPBACK_SENTINEL];
        $redirectUris = array_values(array_filter($redirectUris, fn($v) => !in_array($v, $reserved, true)));
        if (empty($redirectUris)) {
            $redirectUris = ['http://localhost'];
        }
        foreach ($redirectUris as $uri) {
            $parts = parse_url($uri);
            if (!is_array($parts)
                || empty($parts['scheme'])
                || empty($parts['host'])
                || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            ) {
                throw new \InvalidArgumentException('Invalid redirect_uri: ' . $uri);
            }
        }

        $grantTypes = $clientData['grant_types'] ?? ['authorization_code'];
        if (!is_array($grantTypes) || empty($grantTypes)) {
            $grantTypes = ['authorization_code'];
        }

        $clientId = 'mcp_' . bin2hex(random_bytes(16));
        $plainSecret = '';
        $storedSecret = '';
        if ($authMethod !== 'none') {
            $plainSecret = bin2hex(random_bytes(32));
            $storedSecret = $this->hashToken($plainSecret);
        }

        $clientName = $clientData['client_name'] ?? 'MCP Client';
        $scope = $clientData['scope'] ?? 'mcp_access';
        $this->assertClientMetadataFitsStorage($clientName, $scope, $grantTypes, $redirectUris);

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(self::CLIENTS_TABLE);

        $connection->insert(
            self::CLIENTS_TABLE,
            [
                'pid' => 0,
                'tstamp' => time(),
                'crdate' => time(),
                'client_id' => $clientId,
                'client_secret' => $storedSecret,
                'client_name' => $clientName,
                'redirect_uris' => json_encode($redirectUris),
                'grant_types' => json_encode($grantTypes),
                'scope' => $scope,
                'token_endpoint_auth_method' => $authMethod,
            ]
        );

        $response = [
            'client_id' => $clientId,
            'client_id_issued_at' => time(),
            'client_name' => $clientName,
            'redirect_uris' => $redirectUris,
            'grant_types' => $grantTypes,
            'response_types' => ['code'],
            'scope' => $scope,
            'token_endpoint_auth_method' => $authMethod,
        ];
        if ($plainSecret !== '') {
            $response['client_secret'] = $plainSecret;
            // RFC 7591 §3.2.1: REQUIRED when a client_secret is issued; 0 = never expires
            $response['client_secret_expires_at'] = 0;
        }
        return $response;
    }

    /**
     * Look up a registered client by its public client_id.
     * Returns a normalized array or null if no matching client is registered.
     */
    public function getClient(string $clientId): ?array
    {
        if ($clientId === '') {
            return null;
        }

        $row = $this->fetchClientRow($clientId);
        if (!$row && $clientId === self::WELL_KNOWN_CLIENT_ID) {
            // Self-heal on installations that pre-date the clients table or upgrade wizard
            $this->ensureWellKnownClient();
            $row = $this->fetchClientRow($clientId);
        }
        if (!$row) {
            return null;
        }

        $redirectUris = json_decode((string)($row['redirect_uris'] ?? ''), true);
        $grantTypes = json_decode((string)($row['grant_types'] ?? ''), true);

        return [
            'uid' => (int)$row['uid'],
            'client_id' => (string)$row['client_id'],
            'client_name' => (string)$row['client_name'],
            'redirect_uris' => is_array($redirectUris) ? $redirectUris : [],
            'grant_types' => is_array($grantTypes) ? $grantTypes : ['authorization_code'],
            'scope' => (string)$row['scope'],
            'token_endpoint_auth_method' => (string)($row['token_endpoint_auth_method'] ?? 'none'),
            'client_secret_hash' => (string)($row['client_secret'] ?? ''),
        ];
    }

    private function clientUidIsActive(int $clientUid): bool
    {
        if ($clientUid <= 0) {
            return false;
        }
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(self::CLIENTS_TABLE);
        $qb = $connection->createQueryBuilder();
        $count = (int)$qb
            ->count('uid')
            ->from(self::CLIENTS_TABLE)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($clientUid)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0))
            )
            ->executeQuery()
            ->fetchOne();
        return $count > 0;
    }

    private function fetchClientRow(string $clientId): ?array
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(self::CLIENTS_TABLE);

        $qb = $connection->createQueryBuilder();
        $row = $qb
            ->select('*')
            ->from(self::CLIENTS_TABLE)
            ->where(
                $qb->expr()->eq('client_id', $qb->createNamedParameter($clientId)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }

    /**
     * Batch-load registered clients by their uids. Returns a map [uid => clientArray]
     * with the same shape as {@see self::getClient()}. Missing or deleted clients
     * are simply absent from the result.
     */
    public function getClientsByUids(array $uids): array
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
        if (empty($uids)) {
            return [];
        }

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(self::CLIENTS_TABLE);
        $qb = $connection->createQueryBuilder();
        $rows = $qb
            ->select('*')
            ->from(self::CLIENTS_TABLE)
            ->where(
                $qb->expr()->in('uid', $qb->createNamedParameter($uids, \Doctrine\DBAL\ArrayParameterType::INTEGER)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $redirectUris = json_decode((string)($row['redirect_uris'] ?? ''), true);
            $grantTypes = json_decode((string)($row['grant_types'] ?? ''), true);
            $result[(int)$row['uid']] = [
                'uid' => (int)$row['uid'],
                'client_id' => (string)$row['client_id'],
                'client_name' => (string)$row['client_name'],
                'redirect_uris' => is_array($redirectUris) ? $redirectUris : [],
                'grant_types' => is_array($grantTypes) ? $grantTypes : ['authorization_code'],
                'scope' => (string)$row['scope'],
                'token_endpoint_auth_method' => (string)($row['token_endpoint_auth_method'] ?? 'none'),
                'client_secret_hash' => (string)($row['client_secret'] ?? ''),
            ];
        }
        return $result;
    }

    /**
     * Verify a redirect_uri against the URIs registered for a client.
     *
     * Exact match by default. The seeded well-known client may carry the
     * {@see self::REDIRECT_URI_LOOPBACK_SENTINEL} sentinel meaning "any
     * loopback URI" (RFC 8252 native-app pattern) — this keeps existing
     * MCP clients working without making the well-known client an open
     * redirector. Dynamic registrations cannot store the sentinel.
     * Registered loopback URIs are additionally matched with port-agnostic
     * comparison per RFC 8252 §7.3.
     */
    public function isRedirectUriAllowed(array $client, string $redirectUri): bool
    {
        if ($redirectUri === '') {
            return false;
        }

        $registered = $client['redirect_uris'] ?? [];
        if (!is_array($registered) || empty($registered)) {
            return false;
        }

        if (in_array(self::REDIRECT_URI_LOOPBACK_SENTINEL, $registered, true)
            && $this->isLoopbackUri($redirectUri)
        ) {
            return true;
        }

        if (in_array($redirectUri, $registered, true)) {
            return true;
        }

        foreach ($registered as $candidate) {
            if (is_string($candidate) && $this->isLoopbackUriMatch($candidate, $redirectUri)) {
                return true;
            }
        }

        return false;
    }

    private function isLoopbackUri(string $uri): bool
    {
        $parts = parse_url($uri);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }
        $loopbackHosts = ['localhost', '127.0.0.1', '::1', '[::1]'];
        return in_array($parts['host'], $loopbackHosts, true);
    }

    private function isLoopbackUriMatch(string $registered, string $requested): bool
    {
        $reg = parse_url($registered);
        $req = parse_url($requested);
        if (!is_array($reg) || !is_array($req)) {
            return false;
        }
        $loopbackHosts = ['localhost', '127.0.0.1', '::1', '[::1]'];
        $regHost = $reg['host'] ?? '';
        $reqHost = $req['host'] ?? '';
        if (!in_array($regHost, $loopbackHosts, true) || !in_array($reqHost, $loopbackHosts, true)) {
            return false;
        }
        if (($reg['scheme'] ?? '') !== ($req['scheme'] ?? '')) {
            return false;
        }
        if ($regHost !== $reqHost) {
            return false;
        }
        if (($reg['path'] ?? '') !== ($req['path'] ?? '')) {
            return false;
        }
        // RFC 8252 §7.3 only relaxes the port; query and fragment must still match
        // exactly so an attacker cannot pass arbitrary parameters via the redirect.
        if (($reg['query'] ?? '') !== ($req['query'] ?? '')) {
            return false;
        }
        if (($reg['fragment'] ?? '') !== ($req['fragment'] ?? '')) {
            return false;
        }
        return true;
    }

    /**
     * Verify the client_secret presented at the token endpoint.
     * Returns true for public clients (no secret stored) — those are
     * authenticated via PKCE, not a shared secret.
     */
    public function verifyClientSecret(array $client, ?string $providedSecret): bool
    {
        if (($client['token_endpoint_auth_method'] ?? 'none') === 'none' || empty($client['client_secret_hash'])) {
            return true;
        }
        if ($providedSecret === null || $providedSecret === '') {
            return false;
        }
        return hash_equals($client['client_secret_hash'], $this->hashToken($providedSecret));
    }

    /**
     * Ensure the well-known 'typo3-mcp-server' client exists in the database.
     * Idempotent and safe under concurrent calls; failures are swallowed because
     * authentication can still proceed via the pre-table hardcoded behavior in
     * older deployments.
     */
    public function ensureWellKnownClient(): void
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(self::CLIENTS_TABLE);

        try {
            // Look up including soft-deleted rows so we can undelete rather than
            // fail to re-insert (client_id is intended to be unique).
            $existing = $connection->createQueryBuilder()
                ->select('uid', 'deleted')
                ->from(self::CLIENTS_TABLE)
                ->where('client_id = ' . $connection->quote(self::WELL_KNOWN_CLIENT_ID))
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            if (is_array($existing)) {
                if ((int)($existing['deleted'] ?? 0) === 1) {
                    $connection->update(
                        self::CLIENTS_TABLE,
                        ['deleted' => 0, 'tstamp' => time()],
                        ['uid' => (int)$existing['uid']]
                    );
                }
                return;
            }

            $connection->insert(
                self::CLIENTS_TABLE,
                [
                    'pid' => 0,
                    'tstamp' => time(),
                    'crdate' => time(),
                    'client_id' => self::WELL_KNOWN_CLIENT_ID,
                    'client_secret' => '',
                    'client_name' => 'TYPO3 MCP Server',
                    // Legacy-compatibility sentinel: this client accepts any LOOPBACK
                    // redirect_uri (any port, any path) — narrower than the previous
                    // "accept any URI" behavior, so it's no longer an open redirector,
                    // while still preserving native MCP clients that pinned to the
                    // pre-DCR client_id.
                    'redirect_uris' => json_encode([self::REDIRECT_URI_LOOPBACK_SENTINEL]),
                    'grant_types' => json_encode(['authorization_code']),
                    'scope' => 'mcp_access',
                    'token_endpoint_auth_method' => 'none',
                ]
            );
        } catch (\Throwable $e) {
            // Non-fatal: lookup will simply return null and the endpoint will reject the request
        }
    }

    /**
     * Get OAuth metadata for discovery
     */
    public function getMetadata(string $baseUrl): array
    {
        $baseUrl = rtrim($baseUrl, '/');

        return [
            'issuer' => $baseUrl,
            'authorization_endpoint' => $baseUrl . '/mcp_oauth/authorize',
            'token_endpoint' => $baseUrl . '/mcp_oauth/token',
            'registration_endpoint' => $baseUrl . '/mcp_oauth/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'registration_endpoint_auth_methods_supported' => ['none'],
        ];
    }

    /**
     * Create access token directly (bypassing authorization code flow).
     *
     * Used by the backend module for admin-issued tokens. These are bound to
     * the well-known {@see self::WELL_KNOWN_CLIENT_ID} public client so they
     * participate in cascade revocation and audit alongside OAuth-flow tokens.
     */
    public function createDirectAccessToken(int $beUserId, string $clientName, ?ServerRequestInterface $request = null): string
    {
        $accessToken = $this->generateSecureToken();
        $expires = time() + $this->getManualTokenLifetime();

        // Get client IP
        $clientIp = '';
        if ($request !== null) {
            $clientIp = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        }

        // Bind to the well-known client (auto-seeded if missing)
        $wellKnown = $this->getClient(self::WELL_KNOWN_CLIENT_ID);
        $clientUid = $wellKnown !== null ? (int)$wellKnown['uid'] : 0;

        // Create access token
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens');

        $connection->insert(
            'tx_mcpserver_access_tokens',
            [
                'pid' => 0,
                'tstamp' => time(),
                'crdate' => time(),
                'token' => $this->hashToken($accessToken),
                'be_user_uid' => $beUserId,
                'client_uid' => $clientUid,
                'client_name' => $clientName,
                'expires' => $expires,
                'last_used' => time(),
                'created_ip' => $clientIp,
                'last_used_ip' => $clientIp,
                'token_version' => 1,
            ]
        );

        return $accessToken;
    }

    /**
     * Create a long-lived access token for a backend user without the
     * interactive OAuth flow. Intended for MCP clients that cannot perform
     * OAuth discovery (e.g. subdirectory installs) and authenticate with a
     * static Authorization header instead. Returns the plaintext token, which
     * is only available at creation time (the database stores a hash).
     */
    public function createToken(int $beUserUid, string $clientName, ?int $ttlSeconds = null): array
    {
        $accessToken = $this->generateSecureToken();
        $ttl = $ttlSeconds ?? $this->getManualTokenLifetime();
        if ($ttl <= 0) {
            throw new \InvalidArgumentException('Token TTL must be greater than zero seconds.');
        }
        $expires = time() + $ttl;

        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens')
            ->insert(
                'tx_mcpserver_access_tokens',
                [
                    'pid' => 0,
                    'tstamp' => time(),
                    'crdate' => time(),
                    'token' => $this->hashToken($accessToken),
                    'be_user_uid' => $beUserUid,
                    'client_name' => $clientName,
                    'expires' => $expires,
                    'last_used' => 0,
                    'created_ip' => '',
                    'last_used_ip' => '',
                    'token_version' => 1,
                ]
            );

        return [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires' => $expires,
            'expires_in' => $ttl,
        ];
    }

    /**
     * Access token lifetime in seconds for clients with a refresh token.
     * Extension setting "oauthAccessTokenMinutes".
     */
    public function getAccessTokenLifetime(): int
    {
        return $this->getLifetimeSetting('oauthAccessTokenMinutes', self::DEFAULT_ACCESS_TOKEN_MINUTES, 60);
    }

    /**
     * Access token lifetime in seconds for clients that did not register the
     * refresh_token grant. Extension setting "oauthTokenHoursWithoutRefresh".
     */
    public function getTokenLifetimeWithoutRefresh(): int
    {
        return $this->getLifetimeSetting('oauthTokenHoursWithoutRefresh', self::DEFAULT_TOKEN_HOURS_WITHOUT_REFRESH, 3600);
    }

    /**
     * How long a refresh token stays valid without being used; each refresh
     * starts it anew. Extension setting "oauthRefreshTokenDays".
     */
    public function getRefreshTokenLifetime(): int
    {
        return $this->getLifetimeSetting('oauthRefreshTokenDays', self::DEFAULT_REFRESH_TOKEN_DAYS, 86400);
    }

    /**
     * Lifetime of tokens created in the backend module or with mcp:oauth create.
     * Extension setting "manualTokenDays".
     */
    public function getManualTokenLifetime(): int
    {
        return $this->getLifetimeSetting('manualTokenDays', self::DEFAULT_MANUAL_TOKEN_DAYS, 86400);
    }

    /**
     * Upper limit for a grant counted from the authorization, in seconds;
     * 0 = none. After it, refreshing is refused and the user has to authorize
     * again, e.g. to pass the SSO login and its group checks again.
     * Extension setting "oauthMaxSessionHours".
     */
    public function getMaxSessionLifetime(): int
    {
        return $this->getLifetimeSetting('oauthMaxSessionHours', 0, 3600, true);
    }

    /**
     * Token lifetime settings that hold a value which is not a valid whole
     * number and therefore fall back to their default. Shown in the backend
     * module, since a typo here changes how long tokens live.
     *
     * @return list<string> setting names
     */
    public function getInvalidLifetimeSettings(): array
    {
        $invalid = [];
        foreach (self::LIFETIME_SETTINGS as $name => $zeroAllowed) {
            if (!$this->isValidLifetimeValue($this->readExtensionSetting($name), $zeroAllowed)) {
                $invalid[] = $name;
            }
        }
        return $invalid;
    }

    /**
     * A whole number from the extension configuration, in seconds. An empty
     * value means the default (configuration never saved). Anything else that
     * is not a valid number also means the default and is logged: the default
     * may be longer than intended, but a token never becomes unlimited.
     */
    private function getLifetimeSetting(string $name, int $default, int $unitSeconds, bool $zeroAllowed = false): int
    {
        $value = $this->readExtensionSetting($name);
        if ($value === null || $value === '') {
            return $default * $unitSeconds;
        }
        if (!$this->isValidLifetimeValue($value, $zeroAllowed)) {
            GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__)->warning(
                sprintf(
                    'The extension setting mcp_server.%s has the invalid value "%s"; using the default %d instead.',
                    $name,
                    is_scalar($value) ? (string)$value : get_debug_type($value),
                    $default
                )
            );
            return $default * $unitSeconds;
        }
        return (int)$value * $unitSeconds;
    }

    private function isValidLifetimeValue(mixed $value, bool $zeroAllowed): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $zeroAllowed ? 0 : 1]]) !== false;
    }

    private function readExtensionSetting(string $name): mixed
    {
        try {
            // The whole array, not the path: a missing path makes core sync
            // all ext_conf_template.txt files on every call
            $settings = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('mcp_server');
        } catch (\Throwable) {
            return null;
        }
        return is_array($settings) ? ($settings[$name] ?? null) : null;
    }

    /**
     * Generate cryptographically secure token
     */
    private function generateSecureToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Hash a token for storage. Uses SHA-256 which is appropriate for
     * high-entropy random tokens (unlike passwords, these don't need bcrypt).
     */
    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}