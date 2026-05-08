<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Updates;

use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Updates\HashExistingTokensUpgradeWizard;

/**
 * Functional tests for the HashExistingTokensUpgradeWizard
 *
 * Tests the upgrade wizard that hashes existing plain-text OAuth tokens
 * and sets token_version=1 for migrated records.
 */
class HashExistingTokensUpgradeWizardTest extends AbstractFunctionalTest
{
    private const TABLE = 'tx_mcpserver_access_tokens';

    private HashExistingTokensUpgradeWizard $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new HashExistingTokensUpgradeWizard();
    }

    /**
     * @test
     */
    public function testUpdateNecessaryReturnsTrueWhenPlainTokensExist(): void
    {
        $this->insertToken('aabbccdd00112233aabbccdd00112233aabbccdd00112233aabbccdd00112233', 0);

        self::assertTrue($this->subject->updateNecessary());
    }

    /**
     * @test
     */
    public function testUpdateNecessaryReturnsFalseWhenAllTokensHashed(): void
    {
        $this->insertToken('aabbccdd00112233aabbccdd00112233aabbccdd00112233aabbccdd00112233', 1);

        self::assertFalse($this->subject->updateNecessary());
    }

    /**
     * @test
     */
    public function testUpdateNecessaryReturnsFalseWhenNoTokensExist(): void
    {
        self::assertFalse($this->subject->updateNecessary());
    }

    /**
     * @test
     */
    public function testExecuteUpdateHashesPlainTokens(): void
    {
        $plainToken = 'aabbccdd00112233aabbccdd00112233aabbccdd00112233aabbccdd00112233';
        $expectedHash = hash('sha256', $plainToken);

        $this->insertToken($plainToken, 0);

        $this->subject->executeUpdate();

        $row = $this->fetchToken(1);
        self::assertSame($expectedHash, $row['token'], 'Token should be hashed with SHA-256');
        self::assertSame(1, (int)$row['token_version'], 'Token version should be set to 1');
    }

    /**
     * @test
     */
    public function testExecuteUpdateSkipsAlreadyHashedTokens(): void
    {
        $hashedToken = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
        $this->insertToken($hashedToken, 1);

        $this->subject->executeUpdate();

        $row = $this->fetchToken(1);
        self::assertSame($hashedToken, $row['token'], 'Already-hashed token should remain unchanged');
        self::assertSame(1, (int)$row['token_version'], 'Token version should remain 1');
    }

    /**
     * @test
     */
    public function testExecuteUpdateHandlesMultipleTokens(): void
    {
        $plainToken1 = 'aaaa000011112222333344445555666677778888999900001111222233334444';
        $plainToken2 = 'bbbb000011112222333344445555666677778888999900001111222233334444';
        $hashedToken = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

        $this->insertToken($plainToken1, 0);
        $this->insertToken($plainToken2, 0);
        $this->insertToken($hashedToken, 1);

        $this->subject->executeUpdate();

        $row1 = $this->fetchToken(1);
        self::assertSame(hash('sha256', $plainToken1), $row1['token']);
        self::assertSame(1, (int)$row1['token_version']);

        $row2 = $this->fetchToken(2);
        self::assertSame(hash('sha256', $plainToken2), $row2['token']);
        self::assertSame(1, (int)$row2['token_version']);

        $row3 = $this->fetchToken(3);
        self::assertSame($hashedToken, $row3['token'], 'Already-hashed token should remain unchanged');
        self::assertSame(1, (int)$row3['token_version']);
    }

    /**
     * @test
     */
    public function testAfterExecutionUpdateNecessaryReturnsFalse(): void
    {
        $this->insertToken('aabbccdd00112233aabbccdd00112233aabbccdd00112233aabbccdd00112233', 0);

        self::assertTrue($this->subject->updateNecessary(), 'Should need update before execution');

        $this->subject->executeUpdate();

        self::assertFalse($this->subject->updateNecessary(), 'Should not need update after execution');
    }

    /**
     * @test
     */
    public function testGetTitleReturnsString(): void
    {
        $title = $this->subject->getTitle();

        self::assertIsString($title);
        self::assertNotEmpty($title);
    }

    /**
     * @test
     */
    public function testGetDescriptionReturnsString(): void
    {
        $description = $this->subject->getDescription();

        self::assertIsString($description);
        self::assertNotEmpty($description);
    }

    /**
     * Insert a token record into the access tokens table
     */
    private function insertToken(string $token, int $tokenVersion, int $beUserUid = 1): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'pid' => 0,
            'tstamp' => time(),
            'crdate' => time(),
            'deleted' => 0,
            'token' => $token,
            'be_user_uid' => $beUserUid,
            'client_name' => 'test-client',
            'expires' => time() + 3600,
            'last_used' => 0,
            'created_ip' => '127.0.0.1',
            'last_used_ip' => '',
            'token_version' => $tokenVersion,
        ]);
    }

    /**
     * Fetch a token record by uid
     *
     * @return array<string, mixed>
     */
    private function fetchToken(int $uid): array
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $row = $connection->select(['token', 'token_version'], self::TABLE, ['uid' => $uid])->fetchAssociative();
        self::assertIsArray($row, 'Token record with uid=' . $uid . ' should exist');
        return $row;
    }
}
