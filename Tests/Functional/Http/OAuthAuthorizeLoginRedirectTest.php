<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\OAuthAuthorizeEndpoint;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * An unauthenticated OAuth authorization request must be redirected to the
 * backend login page through the backend router.
 *
 * Regression test for #146: the login URL used to be hardcoded as
 * "/typo3/index.php?...", but TYPO3 14 removed that legacy backend entry script,
 * so the redirect produced a "File not found" error there.
 */
class OAuthAuthorizeLoginRedirectTest extends AbstractFunctionalTest
{
    private mixed $previousRequest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;

        // Simulate an unauthenticated backend session so the endpoint redirects
        // to the login page instead of rendering the consent form.
        $GLOBALS['BE_USER'] = GeneralUtility::makeInstance(BackendUserAuthentication::class);
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->previousRequest;
        parent::tearDown();
    }

    public function testRedirectsToBackendLoginRouteForRootInstall(): void
    {
        $request = $this->createAuthorizeRequest('/index.php', '/mcp_oauth/authorize');

        $response = (new OAuthAuthorizeEndpoint())($request);

        self::assertSame(302, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertStringNotContainsString(
            'index.php',
            $location,
            'Login URL must not point at the removed typo3/index.php entry script'
        );
        self::assertStringContainsString('/typo3/login', $location);
        self::assertStringContainsString('loginProvider=1450629977', $location);
    }

    public function testRedirectsToSubdirectoryAwareBackendLoginRoute(): void
    {
        $request = $this->createAuthorizeRequest('/subfolder/index.php', '/subfolder/mcp_oauth/authorize');

        $response = (new OAuthAuthorizeEndpoint())($request);

        self::assertSame(302, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertStringNotContainsString('index.php', $location);
        self::assertStringContainsString('/subfolder/typo3/login', $location);
    }

    public function testBuildsLoginUrlWithoutGlobalRequest(): void
    {
        // On TYPO3 14 this endpoint runs from a PSR-15 middleware before the core
        // request handler populates $GLOBALS['TYPO3_REQUEST']. URL generation must
        // therefore work from the passed request alone.
        $request = $this->createAuthorizeRequest('/index.php', '/mcp_oauth/authorize');
        unset($GLOBALS['TYPO3_REQUEST']);

        $response = (new OAuthAuthorizeEndpoint())($request);

        self::assertSame(302, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertStringNotContainsString('index.php', $location);
        self::assertStringContainsString('/typo3/login', $location);
    }

    private function createAuthorizeRequest(string $scriptName, string $path): ServerRequestInterface
    {
        $serverParams = [
            'HTTP_HOST' => 'example.com',
            'HTTPS' => 'on',
            'SCRIPT_NAME' => $scriptName,
            'SCRIPT_FILENAME' => '/var/www/html' . $scriptName,
            'REQUEST_URI' => $path,
        ];

        $request = new ServerRequest(
            new Uri('https://example.com' . $path),
            'GET',
            'php://input',
            [],
            $serverParams
        );
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromServerParams($serverParams));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }
}
