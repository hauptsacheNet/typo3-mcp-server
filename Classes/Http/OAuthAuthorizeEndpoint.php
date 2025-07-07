<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Hn\McpServer\Service\OAuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * OAuth authorization endpoint
 */
class OAuthAuthorizeEndpoint
{
    

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $queryParams = $request->getQueryParams();
            $postParams = $request->getParsedBody() ?: [];
            
            // Initialize backend user context for eID
            $this->initializeBackendUserContext($request);
            
            // Check if user is authenticated
            if (!$this->isBackendUserAuthenticated()) {
                return $this->redirectToLogin($request);
            }

            $beUser = $GLOBALS['BE_USER'];
            $beUserId = (int)$beUser->user['uid'];

            // Handle authorization approval
            if ($request->getMethod() === 'POST' && isset($postParams['approve'])) {
                return $this->handleApproval($request, $beUserId);
            }

            // Show consent form
            return $this->showConsentForm($request);

        } catch (\Throwable $e) {
            return $this->createErrorResponse('server_error', $e->getMessage());
        }
    }

    private function initializeBackendUserContext(ServerRequestInterface $request): void
    {
        // Initialize backend user context for eID endpoints
        if (!isset($GLOBALS['BE_USER']) || !($GLOBALS['BE_USER'] instanceof BackendUserAuthentication)) {
            $GLOBALS['BE_USER'] = GeneralUtility::makeInstance(BackendUserAuthentication::class);
            $GLOBALS['BE_USER']->start($request);
        }
    }
    
    private function isBackendUserAuthenticated(): bool
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;
        return $beUser instanceof BackendUserAuthentication && 
               is_array($beUser->user) && 
               isset($beUser->user['uid']) && 
               $beUser->user['uid'] > 0;
    }

    /**
     * Resolve client name with proper fallback to hostname from Referer header
     */
    private function resolveClientName(ServerRequestInterface $request): string
    {
        $queryParams = $request->getQueryParams();
        
        // Check query params
        if (!empty($queryParams['client_name'])) {
            return $queryParams['client_name'];
        }
        
        // Fall back to hostname from Referer header
        $referer = $request->getHeaderLine('Referer');
        if (!empty($referer)) {
            $hostname = $this->extractHostnameFromUrl($referer);
            if (!empty($hostname)) {
                return $hostname;
            }
        }
        
        // Ultimate fallback
        return 'MCP Client';
    }

    /**
     * Extract hostname from URL, handling edge cases
     */
    private function extractHostnameFromUrl(string $url): string
    {
        // Handle malformed URLs
        if (empty($url)) {
            return '';
        }
        
        // Add protocol if missing to make parse_url work properly
        if (!preg_match('/^https?:\/\//', $url)) {
            $url = 'http://' . $url;
        }
        
        $parsed = parse_url($url);
        
        // Return hostname or empty string if parsing failed
        return $parsed['host'] ?? $url;
    }


    private function redirectToLogin(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        
        // Store OAuth parameters in cookie
        $oauthData = [
            'client_id' => $queryParams['client_id'] ?? '',
            'client_name' => $this->resolveClientName($request),
            'redirect_uri' => $queryParams['redirect_uri'] ?? '',
            'code_challenge' => $queryParams['code_challenge'] ?? '',
            'code_challenge_method' => $queryParams['code_challenge_method'] ?? '',
            'state' => $queryParams['state'] ?? ''
        ];
        
        $oauthDataEncoded = base64_encode(json_encode($oauthData));
        $loginUrl = '/typo3/index.php?loginProvider=1450629977&login_status=login';
        
        // Build cookie string with environment-aware security flags
        $isHttps = $request->getUri()->getScheme() === 'https';
        $cookieFlags = 'Max-Age=600; Path=/; HttpOnly; SameSite=Lax';
        if ($isHttps) {
            $cookieFlags .= '; Secure';
        }
        
        $stream = new Stream('php://temp', 'rw');
        $stream->write('');
        $stream->rewind();

        return new Response(
            $stream,
            302,
            [
                'Location' => $loginUrl,
                'Set-Cookie' => 'tx_mcpserver_oauth=' . $oauthDataEncoded . '; ' . $cookieFlags
            ]
        );
    }

    private function handleApproval(ServerRequestInterface $request, int $beUserId): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $postParams = $request->getParsedBody() ?: [];

        $clientName = $postParams['client_name'] ?? $this->resolveClientName($request);
        $redirectUri = $queryParams['redirect_uri'] ?? '';
        $pkceChallenge = $queryParams['code_challenge'] ?? '';
        $challengeMethod = $queryParams['code_challenge_method'] ?? 'S256';
        $state = $postParams['state'] ?? $queryParams['state'] ?? '';

        $oauthService = GeneralUtility::makeInstance(OAuthService::class);
        $code = $oauthService->createAuthorizationCode(
            $beUserId,
            $clientName,
            $redirectUri,
            $pkceChallenge,
            $challengeMethod
        );

        // If redirect_uri is provided, redirect there with the code
        if (!empty($redirectUri)) {
            $separator = strpos($redirectUri, '?') !== false ? '&' : '?';
            $redirectUrl = $redirectUri . $separator . 'code=' . urlencode($code);
            
            // Add state parameter if provided
            if (!empty($state)) {
                $redirectUrl .= '&state=' . urlencode($state);
            }
            
            $stream = new Stream('php://temp', 'rw');
            $stream->write('');
            $stream->rewind();

            return new Response(
                $stream,
                302,
                ['Location' => $redirectUrl]
            );
        }

        // Otherwise, show the code to the user
        $html = $this->generateCodeDisplayTemplate($code, $clientName);
        
        $stream = new Stream('php://temp', 'rw');
        $stream->write($html);
        $stream->rewind();

        return new Response(
            $stream,
            200,
            ['Content-Type' => 'text/html; charset=utf-8']
        );
    }

    private function showConsentForm(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        
        $clientId = $queryParams['client_id'] ?? '';
        $clientName = $this->resolveClientName($request);
        $redirectUri = $queryParams['redirect_uri'] ?? '';
        $codeChallenge = $queryParams['code_challenge'] ?? '';
        $challengeMethod = $queryParams['code_challenge_method'] ?? 'S256';
        $state = $queryParams['state'] ?? '';

        // Validate required parameters
        if (empty($clientId) || $clientId !== 'typo3-mcp-server') {
            return $this->createErrorResponse('invalid_client', 'Invalid client_id');
        }

        $beUser = $GLOBALS['BE_USER'];
        $username = $beUser->user['username'] ?? 'Unknown';

        $html = $this->generateConsentTemplate([
            'username' => htmlspecialchars($username),
            'client_name' => htmlspecialchars($clientName),
            'client_id' => htmlspecialchars($clientId),
            'redirect_uri' => htmlspecialchars($redirectUri),
            'code_challenge' => htmlspecialchars($codeChallenge),
            'code_challenge_method' => htmlspecialchars($challengeMethod),
            'state' => htmlspecialchars($state),
            'user_id' => $beUser->user['uid'],
        ]);

        $stream = new Stream('php://temp', 'rw');
        $stream->write($html);
        $stream->rewind();

        // Build cookie cleanup string with environment-aware security flags
        $isHttps = $request->getUri()->getScheme() === 'https';
        $cookieCleanupFlags = 'Max-Age=0; Path=/; HttpOnly; SameSite=Lax';
        if ($isHttps) {
            $cookieCleanupFlags .= '; Secure';
        }

        return new Response(
            $stream,
            200,
            [
                'Content-Type' => 'text/html; charset=utf-8',
                'Set-Cookie' => 'tx_mcpserver_oauth=; ' . $cookieCleanupFlags
            ]
        );
    }


    private function createErrorResponse(string $error, string $description = ''): ResponseInterface
    {
        $errorData = [
            'error' => $error,
            'error_description' => $description
        ];

        $stream = new Stream('php://temp', 'rw');
        $stream->write(json_encode($errorData));
        $stream->rewind();

        return new Response(
            $stream,
            400,
            ['Content-Type' => 'application/json']
        );
    }


    private function generateConsentTemplate(array $data): string
    {
        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authorize MCP Access</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            padding: 40px 20px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 500px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .header h1 {
            color: #333;
            margin: 0 0 10px 0;
            font-size: 24px;
        }
        .info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 4px;
            margin-bottom: 30px;
        }
        .info p {
            margin: 0;
            color: #666;
        }
        .permissions {
            margin-bottom: 30px;
        }
        .permissions h3 {
            color: #333;
            margin: 0 0 15px 0;
        }
        .permissions ul {
            margin: 0;
            padding-left: 20px;
        }
        .permissions li {
            margin-bottom: 8px;
            color: #666;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #333;
        }
        input[type="text"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }
        .buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
        }
        button {
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            font-weight: 500;
        }
        .approve {
            background: #007cba;
            color: white;
        }
        .approve:hover {
            background: #005a87;
        }
        .deny {
            background: #666;
            color: white;
        }
        .deny:hover {
            background: #333;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Authorize MCP Access</h1>
        </div>

        <div class="info">
            <p><strong>User:</strong> ' . $data['username'] . '</p>
            <p><strong>Client:</strong> ' . $data['client_name'] . '</p>
        </div>

        <div class="permissions">
            <h3>This application will be able to:</h3>
            <ul>
                <li>View TYPO3 page structure and content</li>
                <li>Search through TYPO3 records</li>
                <li>Create and modify content (in workspaces)</li>
                <li>Access content with your user permissions</li>
            </ul>
        </div>

        <form method="post">
            <div class="form-group">
                <label for="client_name">Client Name (optional):</label>
                <input type="text" id="client_name" name="client_name" value="' . $data['client_name'] . '" placeholder="My MCP Client">
            </div>

            <input type="hidden" name="client_id" value="' . $data['client_id'] . '">
            <input type="hidden" name="redirect_uri" value="' . $data['redirect_uri'] . '">
            <input type="hidden" name="code_challenge" value="' . $data['code_challenge'] . '">
            <input type="hidden" name="code_challenge_method" value="' . $data['code_challenge_method'] . '">
            <input type="hidden" name="state" value="' . $data['state'] . '">
            <input type="hidden" name="user_id" value="' . $data['user_id'] . '">

            <div class="buttons">
                <button type="submit" name="approve" value="1" class="approve">Authorize Access</button>
                <button type="button" class="deny" onclick="window.close()">Cancel</button>
            </div>
        </form>
    </div>
</body>
</html>';
    }

    private function generateCodeDisplayTemplate(string $code, string $clientName): string
    {
        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authorization Code</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            padding: 40px 20px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 500px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        .success {
            color: #28a745;
            font-size: 24px;
            margin-bottom: 20px;
        }
        .code {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 16px;
            word-break: break-all;
            margin: 20px 0;
            border: 2px solid #007cba;
        }
        .instructions {
            color: #666;
            margin-top: 20px;
        }
        .copy-button {
            background: #007cba;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 10px;
        }
        .copy-button:hover {
            background: #005a87;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="success">✓ Authorization Successful</div>
        
        <p>Authorization code for <strong>' . htmlspecialchars($clientName) . '</strong>:</p>
        
        <div class="code" id="authCode">' . htmlspecialchars($code) . '</div>
        
        <button class="copy-button" onclick="copyCode()">Copy Code</button>
        
        <div class="instructions">
            <p>Copy this code and paste it into your MCP client application.</p>
            <p><strong>Note:</strong> This code expires in 10 minutes.</p>
        </div>
    </div>

    <script>
        function copyCode() {
            const codeElement = document.getElementById("authCode");
            const text = codeElement.textContent;
            
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(() => {
                    alert("Code copied to clipboard!");
                });
            } else {
                // Fallback for older browsers
                const textarea = document.createElement("textarea");
                textarea.value = text;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand("copy");
                document.body.removeChild(textarea);
                alert("Code copied to clipboard!");
            }
        }
    </script>
</body>
</html>';
    }
}