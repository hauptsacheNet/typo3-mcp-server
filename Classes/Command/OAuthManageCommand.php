<?php

declare(strict_types=1);

namespace Hn\McpServer\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Hn\McpServer\Service\OAuthService;

/**
 * OAuth token management for MCP server
 */
class OAuthManageCommand extends Command
{
    protected function configure(): void
    {
        $this->setDescription('Manage OAuth tokens for MCP server')
            ->setHelp('This command helps manage OAuth tokens and provides authorization URLs for MCP clients.')
            ->addArgument('action', InputArgument::REQUIRED, 'Action to perform: url, list, revoke, cleanup')
            ->addArgument('username', InputArgument::OPTIONAL, 'Backend username (required for url, list, revoke actions)')
            ->addOption('client-name', 'c', InputOption::VALUE_OPTIONAL, 'Client name for authorization URL', 'MCP Client')
            ->addOption('token-id', 't', InputOption::VALUE_OPTIONAL, 'Token ID to revoke (for revoke action)')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Revoke all tokens for user (for revoke action)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getArgument('action');
        $username = $input->getArgument('username');
        
        try {
            switch ($action) {
                case 'url':
                    return $this->generateAuthUrl($input, $output, $username);
                case 'list':
                    return $this->listTokens($input, $output, $username);
                case 'revoke':
                    return $this->revokeTokens($input, $output, $username);
                case 'cleanup':
                    return $this->cleanupTokens($input, $output);
                default:
                    $output->writeln("<error>Invalid action. Use: url, list, revoke, or cleanup</error>");
                    return Command::FAILURE;
            }
        } catch (\Throwable $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }

    private function generateAuthUrl(InputInterface $input, OutputInterface $output, ?string $username): int
    {
        if (empty($username)) {
            $output->writeln("<error>Username is required for URL generation</error>");
            return Command::FAILURE;
        }

        // Verify user exists
        $user = $this->findUser($username);
        if (!$user) {
            $output->writeln("<error>User '$username' not found or disabled</error>");
            return Command::FAILURE;
        }

        $clientName = $input->getOption('client-name');
        $baseUrl = $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyBaseUrl'] ?? 'https://your-domain.com';

        $oauthService = GeneralUtility::makeInstance(OAuthService::class);
        $authUrl = $oauthService->generateAuthorizationUrl($baseUrl, $clientName);

        $output->writeln("<info>OAuth Authorization URL for user '$username':</info>");
        $output->writeln("<info>$authUrl</info>");
        $output->writeln("");
        $output->writeln("Instructions:");
        $output->writeln("1. Open this URL in your browser");
        $output->writeln("2. Log in to TYPO3 backend if not already logged in");
        $output->writeln("3. Authorize the MCP client access");
        $output->writeln("4. Use the generated token in your MCP client configuration");

        return Command::SUCCESS;
    }

    private function listTokens(InputInterface $input, OutputInterface $output, ?string $username): int
    {
        if (empty($username)) {
            $output->writeln("<error>Username is required for token listing</error>");
            return Command::FAILURE;
        }

        $user = $this->findUser($username);
        if (!$user) {
            $output->writeln("<error>User '$username' not found or disabled</error>");
            return Command::FAILURE;
        }

        $oauthService = GeneralUtility::makeInstance(OAuthService::class);
        $tokens = $oauthService->getUserTokens($user['uid']);

        if (empty($tokens)) {
            $output->writeln("<info>No active tokens found for user '$username'</info>");
            return Command::SUCCESS;
        }

        $output->writeln("<info>Active tokens for user '$username':</info>");
        $output->writeln("");

        foreach ($tokens as $token) {
            $created = date('Y-m-d H:i:s', $token['crdate']);
            $expires = date('Y-m-d H:i:s', $token['expires']);
            $lastUsed = $token['last_used'] > 0 ? date('Y-m-d H:i:s', $token['last_used']) : 'Never';

            $output->writeln("Token ID: <info>{$token['uid']}</info>");
            $output->writeln("Client: <info>{$token['client_name']}</info>");
            $output->writeln("Created: <info>$created</info>");
            $output->writeln("Expires: <info>$expires</info>");
            $output->writeln("Last Used: <info>$lastUsed</info>");
            $output->writeln("Token: <comment>" . substr($token['token'], 0, 20) . "...</comment>");
            $output->writeln("");
        }

        return Command::SUCCESS;
    }

    private function revokeTokens(InputInterface $input, OutputInterface $output, ?string $username): int
    {
        if (empty($username)) {
            $output->writeln("<error>Username is required for token revocation</error>");
            return Command::FAILURE;
        }

        $user = $this->findUser($username);
        if (!$user) {
            $output->writeln("<error>User '$username' not found or disabled</error>");
            return Command::FAILURE;
        }

        $oauthService = GeneralUtility::makeInstance(OAuthService::class);
        $revokeAll = $input->getOption('all');
        $tokenId = $input->getOption('token-id');

        if ($revokeAll) {
            $count = $oauthService->revokeAllUserTokens($user['uid']);
            $output->writeln("<info>Revoked $count tokens for user '$username'</info>");
        } elseif ($tokenId) {
            $success = $oauthService->revokeToken((int)$tokenId, $user['uid']);
            if ($success) {
                $output->writeln("<info>Token $tokenId revoked successfully</info>");
            } else {
                $output->writeln("<error>Token $tokenId not found or not owned by user</error>");
                return Command::FAILURE;
            }
        } else {
            $output->writeln("<error>Either --token-id or --all option is required for revocation</error>");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function cleanupTokens(InputInterface $input, OutputInterface $output): int
    {
        $oauthService = GeneralUtility::makeInstance(OAuthService::class);
        $oauthService->cleanupExpired();
        
        $output->writeln("<info>Cleanup completed - expired tokens and authorization codes removed</info>");
        return Command::SUCCESS;
    }

    private function findUser(string $username): ?array
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('be_users');
            
        $queryBuilder = $connection->createQueryBuilder();
        $user = $queryBuilder
            ->select('uid', 'username')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($username)),
                $queryBuilder->expr()->eq('disable', $queryBuilder->createNamedParameter(0))
            )
            ->executeQuery()
            ->fetchAssociative();

        return $user ?: null;
    }
}