<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tests for PKCE S256 enforcement
 */
class OAuthPkceTest extends AbstractFunctionalTest
{
    private OAuthService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = GeneralUtility::makeInstance(OAuthService::class);
    }

    public function testPkceMustBeProvidedForCodeExchange(): void
    {
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $code = $this->service->createAuthorizationCode(1, 'test-client', '', $challenge, 'S256');
        $result = $this->service->exchangeCodeForToken($code);

        $this->assertNull($result, 'Exchange without verifier must fail when challenge was set');
    }

    public function testPkceWithCorrectVerifierSucceeds(): void
    {
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $code = $this->service->createAuthorizationCode(1, 'test-client', '', $challenge, 'S256');
        $result = $this->service->exchangeCodeForToken($code, $verifier);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('access_token', $result);
    }

    public function testPkceWithWrongVerifierFails(): void
    {
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $code = $this->service->createAuthorizationCode(1, 'test-client', '', $challenge, 'S256');
        $result = $this->service->exchangeCodeForToken($code, 'wrong-verifier');

        $this->assertNull($result);
    }

    public function testPkceWithEmptyStringVerifierFails(): void
    {
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $code = $this->service->createAuthorizationCode(1, 'test-client', '', $challenge, 'S256');
        $result = $this->service->exchangeCodeForToken($code, '');

        $this->assertNull($result, 'Empty string verifier must fail');
    }

    public function testPkceWithUnsupportedChallengeMethodFails(): void
    {
        $code = $this->service->createAuthorizationCode(1, 'test-client', '', 'some-plain-challenge', 'plain');
        $result = $this->service->exchangeCodeForToken($code, 'some-plain-challenge');

        $this->assertNull($result, 'Unsupported PKCE method must fail');
    }

    public function testCodeExchangeSucceedsWithoutPkceChallenge(): void
    {
        $code = $this->service->createAuthorizationCode(1, 'test-client');
        $result = $this->service->exchangeCodeForToken($code);

        $this->assertNotNull($result, 'Code without PKCE should still exchange');
    }

    public function testMetadataOnlyAdvertisesS256(): void
    {
        $metadata = $this->service->getMetadata('https://example.com');

        $this->assertEquals(['S256'], $metadata['code_challenge_methods_supported']);
    }
}
