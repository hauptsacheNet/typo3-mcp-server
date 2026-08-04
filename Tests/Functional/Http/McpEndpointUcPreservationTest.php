<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\McpEndpoint;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The token-authenticated /mcp endpoint impersonates a backend user without
 * going through the regular authentication flow. That flow normally restores
 * the user's stored configuration (uc) via unpack_uc(). If the endpoint skips
 * this, $beUser->uc starts out empty and any writeUC() triggered during
 * request processing (e.g. the update signals fired when the MCP workspace is
 * created) overwrites the user's stored backend preferences with a nearly
 * empty array. Afterwards the backend Setup module crashes with
 * 'Undefined array key "titleLen"' because the defaults are only re-applied
 * when uc is completely empty.
 */
class McpEndpointUcPreservationTest extends AbstractFunctionalTest
{
    private mixed $previousRequest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->previousRequest;
        parent::tearDown();
    }

    public function testStoredUserConfigurationSurvivesMcpRequest(): void
    {
        // Simulate a user who has personal backend settings stored in be_users.uc
        $storedUc = [
            'titleLen' => 77,
            'lang' => 'de',
            'emailMeAtLogin' => 1,
        ];
        $this->getConnectionForTable('be_users')->update(
            'be_users',
            ['uc' => serialize($storedUc)],
            ['uid' => 1]
        );

        $oauthService = GeneralUtility::makeInstance(OAuthService::class);
        $tokenData = $oauthService->createToken(1, 'uc-preservation-test-client');

        $body = new Stream('php://temp', 'rw');
        $body->write(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'id' => 1,
        ]));
        $body->rewind();

        $request = new ServerRequest(
            new Uri('https://example.com/mcp'),
            'POST',
            $body,
            [
                'Authorization' => 'Bearer ' . $tokenData['access_token'],
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]
        );
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $endpoint = new McpEndpoint();
        $response = $endpoint($request);

        // A 401 would mean the token authentication - and with it the
        // impersonation path under test - never ran.
        $this->assertNotSame(401, $response->getStatusCode());

        // The impersonated backend user must carry the stored configuration
        // in memory, exactly like a regularly authenticated user would.
        $this->assertSame(
            77,
            $GLOBALS['BE_USER']->uc['titleLen'] ?? null,
            'The stored uc must be loaded into the impersonated backend user'
        );

        // Simulate any code path that persists the uc during request
        // processing (workspace creation signals, pushModuleData, ...).
        $GLOBALS['BE_USER']->writeUC();

        $persistedUc = unserialize(
            (string)$this->getConnectionForTable('be_users')
                ->select(['uc'], 'be_users', ['uid' => 1])
                ->fetchOne(),
            ['allowed_classes' => false]
        );

        $this->assertIsArray($persistedUc);
        $this->assertSame(
            77,
            $persistedUc['titleLen'] ?? null,
            'Persisting the uc during an MCP request must not wipe the stored backend preferences'
        );
        $this->assertSame('de', $persistedUc['lang'] ?? null);
    }

    public function testDefaultsAreAppliedForUserWithoutStoredConfiguration(): void
    {
        // Simulate a user who never logged into the backend: no stored uc yet.
        // Such a user must get the uc defaults applied during impersonation,
        // exactly like initializeBackendLogin() does on a first regular login.
        // Otherwise the first writeUC() persists a nearly empty uc, and core
        // never re-applies the defaults afterwards (backendSetUC() only fills
        // them in while uc is completely empty).
        $this->getConnectionForTable('be_users')->update(
            'be_users',
            ['uc' => ''],
            ['uid' => 1]
        );

        $oauthService = GeneralUtility::makeInstance(OAuthService::class);
        $tokenData = $oauthService->createToken(1, 'uc-defaults-test-client');

        $request = new ServerRequest(
            new Uri('https://example.com/mcp'),
            'POST',
            'php://input',
            [
                'Authorization' => 'Bearer ' . $tokenData['access_token'],
                'Content-Type' => 'application/json',
            ]
        );
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $endpoint = new McpEndpoint();
        $response = $endpoint($request);
        $this->assertNotSame(401, $response->getStatusCode());

        $this->assertArrayHasKey(
            'titleLen',
            $GLOBALS['BE_USER']->uc,
            'A user without stored settings must get the uc defaults applied'
        );

        $persistedUc = unserialize(
            (string)$this->getConnectionForTable('be_users')
                ->select(['uc'], 'be_users', ['uid' => 1])
                ->fetchOne(),
            ['allowed_classes' => false]
        );
        $this->assertIsArray($persistedUc);
        $this->assertArrayHasKey(
            'titleLen',
            $persistedUc,
            'The persisted uc of a first-time user must contain the defaults, not a nearly empty array'
        );
    }
}
