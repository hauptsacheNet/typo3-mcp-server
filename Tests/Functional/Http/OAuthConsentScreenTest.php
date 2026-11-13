<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\OAuthAuthorizeEndpoint;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The consent screen grants access to the backend with one click, so it has to name
 * what the user decides about and must not be clickable unseen.
 */
class OAuthConsentScreenTest extends AbstractFunctionalTest
{
    private const HOST = 'stage.example.com';

    private OAuthService $service;
    private array $client;
    private mixed $previousRequest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = GeneralUtility::makeInstance(OAuthService::class);
        $this->client = $this->service->registerClient([
            'client_name' => 'Claude',
            'redirect_uris' => ['https://client.example/oauth/backend/callback'],
        ]);
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->previousRequest;
        parent::tearDown();
    }

    /**
     * Anyone can register a client under any name, so "Client: Claude" proves nothing.
     * Where the code is sent is the fact a look-alike client cannot hide.
     */
    public function testConsentScreenNamesWhereAccessIsHandedTo(): void
    {
        $body = (string)$this->showConsentForm()->getBody();

        self::assertStringContainsString('Access is handed to:</strong> client.example', $body);
    }

    public function testConsentScreenNamesALoopbackDestinationAsLocal(): void
    {
        $this->client = $this->service->registerClient([
            'client_name' => 'Native',
            'redirect_uris' => ['http://127.0.0.1/callback'],
        ]);

        $body = (string)$this->showConsentForm('http://127.0.0.1:49152/callback')->getBody();

        self::assertStringContainsString('an application on this computer (127.0.0.1)', $body);
    }

    /**
     * RFC 6749 §10.13: a consent screen that can be embedded can be clicked unseen.
     */
    public function testConsentScreenRefusesToBeFramed(): void
    {
        $response = $this->showConsentForm();

        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertStringContainsString("frame-ancestors 'none'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
    }

    private function showConsentForm(?string $redirectUri = null): ResponseInterface
    {
        $request = (new ServerRequest(new Uri('https://' . self::HOST . '/mcp_oauth/authorize'), 'GET'))
            ->withQueryParams([
                'client_id' => $this->client['client_id'],
                'redirect_uri' => $redirectUri ?? $this->client['redirect_uris'][0],
                'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
                'code_challenge_method' => 'S256',
                'state' => 'abc123',
            ]);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return (new OAuthAuthorizeEndpoint())($request);
    }
}
