<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * OAuth service for MCP server authentication
 */
class OAuthService
{
    private const CLIENT_ID = 'typo3-mcp-server';
    private const CODE_EXPIRY_SECONDS = 600; // 10 minutes
    private const TOKEN_EXPIRY_SECONDS = 2592000; // 30 days

    /**
     * Generate authorization URL for OAuth flow
     */
    public function generateAuthorizationUrl(string $baseUrl, string $clientName = '', string $redirectUri = '', string $codeChallenge = '', string $challengeMethod = 'S256', string $state = ''): string
    {
        $params = [
            'client_id' => self::CLIENT_ID,
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
    public function exchangeCodeForToken(string $code, ?string $codeVerifier = null): ?array
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

        // Verify PKCE challenge if provided
        if (!empty($authCode['pkce_challenge']) && $codeVerifier !== null) {
            $computedChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
            if ($computedChallenge !== $authCode['pkce_challenge']) {
                return null;
            }
        }

        // Generate access token
        $accessToken = $this->generateSecureToken();
        $expires = time() + self::TOKEN_EXPIRY_SECONDS;

        // Get client IP
        $request = $GLOBALS['TYPO3_REQUEST'] ?? ServerRequest::fromGlobals();
        $clientIp = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        // Create access token
        $tokenConnection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens');

        $tokenConnection->insert(
            'tx_mcpserver_access_tokens',
            [
                'pid' => 0,
                'tstamp' => time(),
                'crdate' => time(),
                'token' => $accessToken,
                'be_user_uid' => $authCode['be_user_uid'],
                'client_name' => $authCode['client_name'],
                'expires' => $expires,
                'last_used' => time(),
                'created_ip' => $clientIp,
                'last_used_ip' => $clientIp,
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
    public function validateToken(string $token): ?array
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens');

        $queryBuilder = $connection->createQueryBuilder();
        $tokenRecord = $queryBuilder
            ->select('*')
            ->from('tx_mcpserver_access_tokens')
            ->where(
                $queryBuilder->expr()->eq('token', $queryBuilder->createNamedParameter($token)),
                $queryBuilder->expr()->gt('expires', $queryBuilder->createNamedParameter(time())),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0))
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!$tokenRecord) {
            return null;
        }

        // Update last used timestamp and IP
        $request = $GLOBALS['TYPO3_REQUEST'] ?? ServerRequest::fromGlobals();
        $clientIp = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->update('tx_mcpserver_access_tokens')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($tokenRecord['uid'])))
            ->set('last_used', time())
            ->set('last_used_ip', $clientIp)
            ->executeStatement();

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
     * Register a new OAuth client dynamically
     */
    public function registerClient(array $clientData): array
    {
        // Generate client credentials
        $clientId = 'mcp_client_' . bin2hex(random_bytes(16));
        $clientSecret = bin2hex(random_bytes(32));
        
        // For now, store in database (could be enhanced later)
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_oauth_clients');

        // Check if table exists, if not create it on the fly
        try {
            $connection->insert(
                'tx_mcpserver_oauth_clients',
                [
                    'pid' => 0,
                    'tstamp' => time(),
                    'crdate' => time(),
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'client_name' => $clientData['client_name'] ?? 'MCP Client',
                    'redirect_uris' => json_encode($clientData['redirect_uris'] ?? []),
                    'grant_types' => json_encode($clientData['grant_types'] ?? ['authorization_code']),
                    'scope' => $clientData['scope'] ?? 'mcp_access',
                ]
            );
        } catch (\Exception $e) {
            // If table doesn't exist, we'll use the fixed client approach for now
            return [
                'client_id' => self::CLIENT_ID,
                'client_name' => $clientData['client_name'] ?? 'MCP Client',
                'grant_types' => ['authorization_code'],
                'response_types' => ['code'],
                'scope' => 'mcp_access',
                'redirect_uris' => $clientData['redirect_uris'] ?? ['http://localhost'],
            ];
        }

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'client_name' => $clientData['client_name'] ?? 'MCP Client',
            'grant_types' => $clientData['grant_types'] ?? ['authorization_code'],
            'response_types' => ['code'],
            'scope' => $clientData['scope'] ?? 'mcp_access',
            'redirect_uris' => $clientData['redirect_uris'] ?? ['http://localhost'],
        ];
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
            'code_challenge_methods_supported' => ['S256', 'plain'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post'],
            'registration_endpoint_auth_methods_supported' => ['none'],
        ];
    }

    /**
     * Create access token directly (bypassing authorization code flow)
     */
    public function createDirectAccessToken(int $beUserId, string $clientName): string
    {
        $accessToken = $this->generateSecureToken();
        $expires = time() + self::TOKEN_EXPIRY_SECONDS;

        // Get client IP
        $request = $GLOBALS['TYPO3_REQUEST'] ?? ServerRequest::fromGlobals();
        $clientIp = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        // Create access token
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens');

        $connection->insert(
            'tx_mcpserver_access_tokens',
            [
                'pid' => 0,
                'tstamp' => time(),
                'crdate' => time(),
                'token' => $accessToken,
                'be_user_uid' => $beUserId,
                'client_name' => $clientName,
                'expires' => $expires,
                'last_used' => time(),
                'created_ip' => $clientIp,
                'last_used_ip' => $clientIp,
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
}