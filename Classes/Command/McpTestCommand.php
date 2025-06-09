<?php

declare(strict_types=1);

namespace Hn\McpServer\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;

/**
 * MCP Test Command - For testing MCP tools directly
 */
class McpTestCommand extends Command
{
    /**
     * @var ToolRegistry
     */
    protected ToolRegistry $toolRegistry;

    /**
     * Constructor
     */
    public function __construct(ToolRegistry $toolRegistry)
    {
        $this->toolRegistry = $toolRegistry;
        parent::__construct();
    }

    /**
     * Configure the command
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Test MCP tools directly')
            ->setHelp('This command allows you to test MCP tools directly without starting a server.')
            ->addArgument(
                'tool',
                InputArgument::REQUIRED,
                'The tool to test (e.g., "record/schema")'
            )
            ->addArgument(
                'params',
                InputArgument::OPTIONAL,
                'JSON-encoded parameters for the tool',
                '{}'
            );
    }

    /**
     * Execute the command
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            // Ensure we have admin rights for the backend user
            $this->ensureAdminRights();
            
            // Ensure TCA is loaded
            $this->ensureTcaLoaded();
            
            // Get command arguments
            $toolName = $input->getArgument('tool');
            $paramsJson = $input->getArgument('params');
            
            // Parse parameters
            $params = json_decode($paramsJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $output->writeln('<error>Invalid JSON parameters: ' . json_last_error_msg() . '</error>');
                return Command::FAILURE;
            }
            
            // List all available tools if requested
            if ($toolName === 'list') {
                $output->writeln('<info>Available MCP tools:</info>');
                foreach ($this->toolRegistry->getTools() as $name => $tool) {
                    $output->writeln('- ' . $name);
                }
                return Command::SUCCESS;
            }
            
            // Find the tool
            $tool = $this->toolRegistry->getTool($toolName);
            if (!$tool) {
                $output->writeln('<error>Tool not found: ' . $toolName . '</error>');
                $output->writeln('<info>Available tools:</info>');
                foreach ($this->toolRegistry->getTools() as $name => $tool) {
                    $output->writeln('- ' . $name);
                }
                return Command::FAILURE;
            }
            
            // Execute the tool
            $output->writeln('<info>Executing tool: ' . $toolName . '</info>');
            $output->writeln('<info>Parameters: ' . $paramsJson . '</info>');
            $output->writeln('');
            
            $result = $tool->execute($params);
            
            // Display the result
            $output->writeln('<info>Result:</info>');
            
            // Check if the result is an error
            $isError = $result->isError ?? false;
            
            if ($isError) {
                $output->writeln('<error>Error: ' . $this->getResultText($result) . '</error>');
                return Command::FAILURE;
            }
            
            $output->writeln($this->getResultText($result));
            
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            if ($output->isVerbose()) {
                $output->writeln('<error>' . $e->getTraceAsString() . '</error>');
            }
            return Command::FAILURE;
        }
    }
    
    /**
     * Extract text content from a CallToolResult
     */
    protected function getResultText(CallToolResult $result): string
    {
        $text = '';
        
        foreach ($result->content as $item) {
            if ($item instanceof TextContent) {
                $text .= $item->text;
            } else {
                $text .= json_encode($item, JSON_PRETTY_PRINT);
            }
        }
        
        return $text;
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
            $GLOBALS['BE_USER'] = $beUser;
            
            // Set up workspace context
            $workspaceService = GeneralUtility::makeInstance(WorkspaceContextService::class);
            $workspaceId = $workspaceService->switchToOptimalWorkspace($beUser);
        } else if (!$beUser->isAdmin()) {
            // If user exists but is not admin, set admin flag directly
            $beUser->user['admin'] = 1;
            if (!isset($beUser->user['uid'])) {
                $beUser->user['uid'] = 1; // Ensure UID is set
            }
            
            // Set up workspace context
            $workspaceService = GeneralUtility::makeInstance(WorkspaceContextService::class);
            $workspaceId = $workspaceService->switchToOptimalWorkspace($beUser);
        } else {
            // User exists and is admin, still set up workspace context
            $workspaceService = GeneralUtility::makeInstance(WorkspaceContextService::class);
            $workspaceId = $workspaceService->switchToOptimalWorkspace($beUser);
        }
    }
    
    /**
     * Ensure TCA is loaded
     */
    protected function ensureTcaLoaded(): void
    {
        // Check if TCA is already loaded
        if (empty($GLOBALS['TCA']) || empty($GLOBALS['TCA']['tt_content']['columns']['pi_flexform'])) {
            // Load the TCA directly
            $tcaPath = \TYPO3\CMS\Core\Core\Environment::getPublicPath() . '/typo3/sysext/core/Configuration/TCA/';
            if (is_dir($tcaPath)) {
                $files = glob($tcaPath . '*.php');
                foreach ($files as $file) {
                    require_once $file;
                }
            }
            
            // Load extension TCA
            $extTcaPath = \TYPO3\CMS\Core\Core\Environment::getPublicPath() . '/typo3conf/ext/*/Configuration/TCA/';
            $extFiles = glob($extTcaPath . '*.php');
            if (is_array($extFiles)) {
                foreach ($extFiles as $file) {
                    require_once $file;
                }
            }
            
            // Load TCA overrides
            $overridePath = \TYPO3\CMS\Core\Core\Environment::getPublicPath() . '/typo3conf/ext/*/Configuration/TCA/Overrides/';
            $overrideFiles = glob($overridePath . '*.php');
            if (is_array($overrideFiles)) {
                foreach ($overrideFiles as $file) {
                    require_once $file;
                }
            }
        }
    }
}
