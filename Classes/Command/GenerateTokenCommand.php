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

/**
 * Generate MCP access tokens for backend users
 */
class GenerateTokenCommand extends Command
{
    protected function configure(): void
    {
        $this->setDescription('Generate MCP access token for a backend user')
            ->setHelp('This command generates an access token that can be used to authenticate with the MCP HTTP endpoint.')
            ->addArgument('username', InputArgument::REQUIRED, 'Backend username')
            ->addOption('expires', null, InputOption::VALUE_OPTIONAL, 'Token expiration in hours (default: 720 = 30 days)', 720)
            ->addOption('regenerate', null, InputOption::VALUE_NONE, 'Regenerate token if user already has one');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = $input->getArgument('username');
        $expiresHours = (int)$input->getOption('expires');
        $regenerate = $input->getOption('regenerate');
        
        try {
            $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable('be_users');
                
            // Find user
            $queryBuilder = $connection->createQueryBuilder();
            $user = $queryBuilder
                ->select('uid', 'username', 'mcp_token', 'mcp_token_expires')
                ->from('be_users')
                ->where(
                    $queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($username)),
                    $queryBuilder->expr()->eq('disable', $queryBuilder->createNamedParameter(0))
                )
                ->executeQuery()
                ->fetchAssociative();

            if (!$user) {
                $output->writeln("<error>User '$username' not found or disabled</error>");
                return Command::FAILURE;
            }

            // Check if user already has a valid token
            $hasValidToken = !empty($user['mcp_token']) && 
                           !empty($user['mcp_token_expires']) && 
                           $user['mcp_token_expires'] > time();

            if ($hasValidToken && !$regenerate) {
                $expires = date('Y-m-d H:i:s', $user['mcp_token_expires']);
                $output->writeln("<comment>User already has a valid token (expires: $expires)</comment>");
                $output->writeln("<comment>Use --regenerate flag to create a new token</comment>");
                $output->writeln("");
                $output->writeln("Current token: <info>{$user['mcp_token']}</info>");
                return Command::SUCCESS;
            }

            // Generate new token
            $token = $this->generateSecureToken();
            $expires = time() + ($expiresHours * 3600);

            // Update user record
            $queryBuilder = $connection->createQueryBuilder();
            $queryBuilder
                ->update('be_users')
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($user['uid'])))
                ->set('mcp_token', $token)
                ->set('mcp_token_expires', $expires)
                ->executeStatement();

            $expiresFormatted = date('Y-m-d H:i:s', $expires);
            $output->writeln("<info>Token generated successfully for user '$username'</info>");
            $output->writeln("Token: <info>$token</info>");
            $output->writeln("Expires: <info>$expiresFormatted</info>");
            $output->writeln("");
            $output->writeln("MCP Endpoint URL:");
            $baseUrl = $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyBaseUrl'] ?? 'https://your-domain.com';
            $url = rtrim($baseUrl, '/') . '/typo3/index.php?eID=mcp_server&token=' . urlencode($token);
            $output->writeln("<info>$url</info>");

            return Command::SUCCESS;
            
        } catch (\Throwable $e) {
            $output->writeln("<error>Error generating token: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }

    private function generateSecureToken(): string
    {
        // Generate a cryptographically secure token
        $randomBytes = random_bytes(32);
        return bin2hex($randomBytes);
    }
}