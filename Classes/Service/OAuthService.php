<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use Psr\Http\Message\ServerRequestInterface;

/**
 * OAuth service for MCP server authentication
 */
class OAuthService
{
    public const WELL_KNOWN_CLIENT_ID = 'typo3-mcp-server';
    private const CLIENTS_TABLE = 'tx_mcpserver_oauth_clients';
    private const CODE_EXPIRY_SECONDS = 600; // 10 minutes
    private const TOKEN_EXPIRY_SECONDS = 2592000; // 30 days

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
     * Create authorization code for authenticated user
     */
    public function createAuthorizationCode(int $beUserId, string $clientName, string $redirectUri = '', string $pkceChallenge = '', string $challengeMethod = 'S256'): string
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
     * Exchange authorization code for access token
     */
    public function exchangeCodeForToken(string $code, ?string $codeVerifier = null, ?ServerRequestInterface $request = null, ?string $redirectUri = null): ?array
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

        // Generate access token
        $accessToken = $this->generateSecureToken();
        $expires = time() + self::TOKEN_EXPIRY_SECONDS;

        // Get client IP
        $clientIp = '';
        if ($request !== null) {
            $clientIp = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        }

        // Create access token
        $tokenConnection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens');

        $tokenConnection->insert(
            'tx_mcpserver_access_tokens',
            [
                'pid' => 0,
                'tstamp' => time(),
                'crdate' => time(),
                'token' => $this->hashToken($accessToken),
                'be_user_uid' => $authCode['be_user_uid'],
                'client_name' => $authCode['client_name'],
                'expires' => $expires,
                'last_used' => time(),
                'created_ip' => $clientIp,
                'last_used_ip' => $clientIp,
                'token_version' => 1,
            ]
        );

        // Delete the authorization code (one-time use)
        $connection->delete(
            'tx_mcpserver_oauth_codes',
            ['uid' => $authCode['uid']]
        );

        return [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => self::TOKEN_EXPIRY_SECONDS,
        ];
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
                $queryBuilder->expr()->gt('expires', $queryBuilder->createNamedParameter(time())),
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

        // Clean up expired authorization codes
        $codeConnection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_oauth_codes');

        $codeConnection->delete(
            'tx_mcpserver_oauth_codes',
            ['expires' => $codeConnection->createQueryBuilder()->expr()->lt('expires', $currentTime)]
        );

        // Mark expired tokens as deleted
        $tokenConnection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens');

        $tokenConnection->update(
            'tx_mcpserver_access_tokens',
            ['deleted' => 1, 'tstamp' => $currentTime],
            ['expires' => $tokenConnection->createQueryBuilder()->expr()->lt('expires', $currentTime)]
        );
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
        // Reject the wildcard sentinel that is reserved for the seeded well-known client
        $redirectUris = array_values(array_filter($redirectUris, fn($v) => $v !== '*'));
        if (empty($redirectUris)) {
            $redirectUris = ['http://localhost'];
        }
        foreach ($redirectUris as $uri) {
            if (parse_url($uri) === false) {
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

        $clientName = (string)($clientData['client_name'] ?? 'MCP Client');
        $scope = (string)($clientData['scope'] ?? 'mcp_access');

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
     * Verify a redirect_uri against the URIs registered for a client.
     *
     * Exact match by default. As a transition affordance, the seeded
     * well-known client may use the '*' sentinel to accept any URI;
     * dynamic registrations cannot use it. Loopback URIs (per RFC 8252 §7.3)
     * are matched without comparing the port.
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

        if (in_array('*', $registered, true)) {
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
            $exists = (int)$connection->createQueryBuilder()
                ->count('uid')
                ->from(self::CLIENTS_TABLE)
                ->where('client_id = ' . $connection->quote(self::WELL_KNOWN_CLIENT_ID))
                ->executeQuery()
                ->fetchOne();
            if ($exists > 0) {
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
                    // '*' is the legacy-compatibility sentinel: this client accepts any
                    // redirect_uri, preserving behavior for MCP clients that registered
                    // before dynamic registration was implemented.
                    'redirect_uris' => json_encode(['*']),
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
            'grant_types_supported' => ['authorization_code'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post'],
            'registration_endpoint_auth_methods_supported' => ['none'],
        ];
    }

    /**
     * Create access token directly (bypassing authorization code flow)
     */
    public function createDirectAccessToken(int $beUserId, string $clientName, ?ServerRequestInterface $request = null): string
    {
        $accessToken = $this->generateSecureToken();
        $expires = time() + self::TOKEN_EXPIRY_SECONDS;

        // Get client IP
        $clientIp = '';
        if ($request !== null) {
            $clientIp = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        }

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