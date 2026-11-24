<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A client sending invalid input is not a server error. Expected exceptions
 * are logged as warning, so that log-based alerting on "error" (Sentry,
 * file monitors, a production log threshold of ERROR) is not triggered by
 * every mistake of an AI client. Unexpected exceptions stay critical.
 */
class ExceptionLogLevelTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    private RecordingLogger $logger;

    private WriteTableTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $this->logger = new RecordingLogger();
        $this->tool = new class ($this->logger) extends WriteTableTool {
            public function __construct(private readonly LoggerInterface $recordingLogger)
            {
                parent::__construct();
            }

            protected function getLogger(): LoggerInterface
            {
                return $this->recordingLogger;
            }

            public function logForTest(\Throwable $e): void
            {
                $this->logException($e, 'test');
            }
        };
    }

    public function testInvalidToolInputIsLoggedAsWarning(): void
    {
        $result = $this->tool->execute([
            'action' => 'undelete',
            'table' => 'pages',
            'uid' => 1,
        ]);

        $this->assertTrue($result->isError, 'Result should be an error');
        $this->assertSame([LogLevel::WARNING], $this->logger->levels);
    }

    public function testValidationExceptionIsLoggedAsWarning(): void
    {
        $this->tool->logForTest(new ValidationException(['field "uid_foreign" is not readable']));

        $this->assertSame([LogLevel::WARNING], $this->logger->levels);
    }

    public function testUnexpectedExceptionIsLoggedAsCritical(): void
    {
        $this->tool->logForTest(new \RuntimeException('database went away'));

        $this->assertSame([LogLevel::CRITICAL], $this->logger->levels);
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $levels = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->levels[] = (string)$level;
    }
}
