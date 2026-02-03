<?php

declare(strict_types=1);

namespace Hn\McpServer\Command;

use Mcp\Server\ServerRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Configuration\Tca\TcaFactory;
use Hn\McpServer\MCP\McpServerFactory;

/**
 * MCP Server Command - Uses logiscape/mcp-sdk-php
 */
class McpServerCommand extends Command
{
    public function __construct(
        private readonly McpServerFactory $serverFactory
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Start the MCP server for AI assistants');
        $this->setHelp('This command starts an MCP server that allows AI assistants to interact with TYPO3 via the stdio protocol.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            // Ensure we have admin rights for the backend user
            $this->ensureAdminRights();

            // Ensure TCA is loaded using proper TYPO3 core method
            $tcaFactory = GeneralUtility::getContainer()->get(TcaFactory::class);
            $GLOBALS['TCA'] = $tcaFactory->get();

            // Set up debugging to stderr
            $debug = static function ($message) {
                file_put_contents('php://stderr', '[MCP Server] ' . $message . PHP_EOL);
            };

            $debug('Starting MCP server using logiscape/mcp-sdk-php');

            // Create the MCP server using the factory
            $server = $this->serverFactory->createServer($debug);

            $debug('All handlers registered, starting server...');

            // Create initialization options and run server
            $initOptions = $this->serverFactory->createInitializationOptions($server);
            $runner = new ServerRunner($server, $initOptions);
            $runner->run();

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            // Log the error to stderr, not stdout (to avoid corrupting MCP protocol)
            file_put_contents('php://stderr', 'MCP Server Error: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL);
            return Command::FAILURE;
        }
    }

    /**
     * Ensure we have admin rights for the backend user
     */
    protected function ensureAdminRights(): void
    {
        /** @var BackendUserAuthentication $beUser */
        $beUser = $GLOBALS['BE_USER'];
        if (!$beUser) {
            // Create an admin backend user
            $beUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
            // Set admin flag directly since setTemporaryAdminFlag doesn't exist in TYPO3 v12
            $beUser->user['admin'] = 1;
            $beUser->user['uid'] = 1; // Add a UID for the fake user to prevent DataHandler errors
            $beUser->user['workspace_id'] = 0; // Set workspace ID to live workspace
            $beUser->workspace = 0; // Set workspace to live workspace
            $GLOBALS['BE_USER'] = $beUser;
        } elseif (!$beUser->isAdmin()) {
            // If user exists but is not admin, set admin flag directly
            $beUser->user['admin'] = 1;
            if (!isset($beUser->user['uid'])) {
                $beUser->user['uid'] = 1; // Ensure UID is set
            }
            $beUser->user['workspace_id'] = 0; // Set workspace ID to live workspace
            $beUser->workspace = 0; // Set workspace to live workspace
        }
    }
}
