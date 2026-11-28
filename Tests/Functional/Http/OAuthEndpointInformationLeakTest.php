<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\OAuthRegisterEndpoint;
use Hn\McpServer\Http\OAuthTokenEndpoint;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;
use TYPO3\CMS\Core\Database\Schema\SqlReader;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The token and registration endpoints answer anyone, so what they say about a
 * failure must not describe the server: no exception messages (file paths, SQL,
 * table names), whatever the caller sends.
 */
class OAuthEndpointInformationLeakTest extends AbstractFunctionalTest
{
    private bool $tableDropped = false;

    protected function tearDown(): void
    {
        // The testing framework restores the schema between tests only on
        // SQLite; on MySQL, MariaDB and PostgreSQL it merely truncates the
        // tables, so a dropped table has to be created again here.
        if ($this->tableDropped) {
            $sqlReader = GeneralUtility::makeInstance(SqlReader::class);
            $this->get(SchemaMigrator::class)->install(
                $sqlReader->getCreateTableStatementArray($sqlReader->getTablesDefinitionString()),
                true
            );
        }
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function requestToken(array $body): ResponseInterface
    {
        $request = (new ServerRequest('https://example.com/mcp_oauth/token', 'POST'))->withParsedBody($body);

        return (new OAuthTokenEndpoint())($request);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function register(array $metadata): ResponseInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write((string)json_encode($metadata));
        $stream->rewind();
        $request = (new ServerRequest('https://example.com/mcp_oauth/register', 'POST'))->withBody($stream);

        return (new OAuthRegisterEndpoint())($request);
    }

    private function dropTable(string $table): void
    {
        $this->tableDropped = true;
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table)
            ->executeStatement('DROP TABLE ' . $table);
    }

    private function assertDescribesNoInternals(ResponseInterface $response): void
    {
        $body = (string)$response->getBody();
        self::assertStringNotContainsString('tx_mcpserver', $body);
        self::assertStringNotContainsString('.php', $body);
        self::assertStringNotContainsString('Classes', $body);
        self::assertStringNotContainsString('must be of type', $body);
    }

    public function testTokenEndpointRefusesArrayParametersWithoutATypeError(): void
    {
        $response = $this->requestToken([
            'grant_type' => 'authorization_code',
            'code' => ['x'],
            'client_id' => OAuthService::WELL_KNOWN_CLIENT_ID,
        ]);

        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('invalid_request', json_decode((string)$response->getBody(), true)['error']);
        $this->assertDescribesNoInternals($response);
    }

    public function testTokenEndpointKeepsAServerFailureToItself(): void
    {
        $this->dropTable('tx_mcpserver_oauth_codes');

        $response = $this->requestToken([
            'grant_type' => 'authorization_code',
            'code' => 'anything',
            'client_id' => OAuthService::WELL_KNOWN_CLIENT_ID,
        ]);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('server_error', json_decode((string)$response->getBody(), true)['error']);
        $this->assertDescribesNoInternals($response);
    }

    public function testTokenResponseMustNotBeCached(): void
    {
        $service = GeneralUtility::makeInstance(OAuthService::class);
        $client = $service->registerClient(['client_name' => 'Cache', 'token_endpoint_auth_method' => 'client_secret_post']);
        $code = $service->createAuthorizationCode(1, 'Cache', '', '', 'S256', $client['client_id']);

        $response = $this->requestToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $client['client_id'],
            'client_secret' => $client['client_secret'],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testRegistrationKeepsAServerFailureToItself(): void
    {
        $this->dropTable('tx_mcpserver_oauth_clients');

        $response = $this->register(['client_name' => 'Leak', 'redirect_uris' => ['https://client.example/cb']]);

        self::assertSame(500, $response->getStatusCode());
        $this->assertDescribesNoInternals($response);
    }

    public function testRegistrationRefusesANameThatIsNotAString(): void
    {
        $response = $this->register(['client_name' => ['Claude'], 'redirect_uris' => ['https://client.example/cb']]);

        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('invalid_client_metadata', json_decode((string)$response->getBody(), true)['error']);
    }

    public function testRegistrationRefusesANameThatDoesNotFitItsColumn(): void
    {
        $response = $this->register(['client_name' => str_repeat('a', 256), 'redirect_uris' => ['https://client.example/cb']]);

        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('invalid_client_metadata', json_decode((string)$response->getBody(), true)['error']);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function metadataThatIsNotAStringProvider(): array
    {
        return [
            'client_name false' => ['client_name', false],
            'client_name 0' => ['client_name', 0],
            'client_name empty list' => ['client_name', []],
            'client_name null' => ['client_name', null],
            'scope null' => ['scope', null],
            'scope number' => ['scope', 1],
            'scope list' => ['scope', ['mcp_access']],
        ];
    }

    #[DataProvider('metadataThatIsNotAStringProvider')]
    public function testRegistrationRefusesSuppliedMetadataThatIsNotAString(string $field, mixed $value): void
    {
        $response = $this->register([$field => $value, 'redirect_uris' => ['https://client.example/cb']]);

        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('invalid_client_metadata', json_decode((string)$response->getBody(), true)['error']);
    }

    public function testRegistrationFillsInOmittedNameAndScope(): void
    {
        $response = $this->register(['redirect_uris' => ['https://client.example/cb']]);

        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        $client = json_decode((string)$response->getBody(), true);
        self::assertSame('MCP Client', $client['client_name']);
        self::assertSame('mcp_access', $client['scope']);
    }
}
