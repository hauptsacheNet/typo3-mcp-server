<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm;

use Hn\McpServer\Tests\Llm\Client\LlmResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Can an LLM find and edit the right language version of a page?
 *
 * The site speaks German by default and has an English translation. Editors
 * ask for changes to "the English page"; the LLM has to navigate from the
 * default-language records to the translation records, edit those, and leave
 * the German originals alone. Assertions check the resulting records, not the
 * exact tool sequence, because there are several valid ways to get there.
 *
 * Fixture (Tests/Llm/Fixtures/multilanguage_pages.csv):
 *   1 Startseite   ← EN 10 "Home"       content 100 ← EN 110
 *   2 Über uns     ← EN 20 "About us"   content 200 ← EN 210
 *   3 Kontakt      ← EN 30 "Contact"    content 300 ← EN 310, 301 untranslated
 *   4 Leistungen   (no EN translation)  content 400, 401 untranslated
 *
 * @group llm
 */
class LanguageNavigationTest extends LlmTestCase
{
    private const MAX_ROUNDS = 15;

    /** @var string[] Tool calls of the last conversation, for failure messages */
    private array $transcriptLines = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->writeSiteConfiguration();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/multilanguage_pages.csv');
    }

    private function writeSiteConfiguration(): void
    {
        $language = static fn(int $id, string $title, string $base, string $locale, string $iso): array => [
            'title' => $title,
            'enabled' => true,
            'languageId' => $id,
            'base' => $base,
            'locale' => $locale,
            'iso-639-1' => $iso,
            'hreflang' => str_replace('_', '-', strtolower(substr($locale, 0, 5))),
            'navigationTitle' => $title,
            'flag' => $iso,
        ];
        $configuration = [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'websiteTitle' => 'Beispiel GmbH',
            'languages' => [
                $language(0, 'Deutsch', '/', 'de_DE.UTF-8', 'de'),
                $language(1, 'English', '/en/', 'en_US.UTF-8', 'en'),
            ],
            'routes' => [],
            'errorHandling' => [],
        ];
        $path = $this->instancePath . '/typo3conf/sites/main';
        GeneralUtility::mkdir_deep($path);
        GeneralUtility::writeFile($path . '/config.yaml', Yaml::dump($configuration, 99, 2), true);
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] "Change the title of the English contact page" → updates EN page 30, German page 3 keeps its title')]
    public function testChangesTitleOfEnglishPage(string $modelKey): void
    {
        $this->setModel($modelKey);
        $this->runConversation('Ändere den Seitentitel der englischen Kontaktseite in „Get in touch“.');

        $this->assertSame('Kontakt', $this->field('pages', 3, 'title'), 'German page title was changed' . $this->transcript());
        $this->assertSame('Get in touch', $this->field('pages', 30, 'title'), 'English page title was not changed' . $this->transcript());
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] "Change the title of https://example.com/en/contact" → updates EN page 30, German page 3 keeps its title')]
    public function testChangesTitleOfEnglishPageGivenByUrl(string $modelKey): void
    {
        $this->setModel($modelKey);
        $this->runConversation('Ändere den Seitentitel von https://example.com/en/contact in „Get in touch“.');

        $this->assertSame('Kontakt', $this->field('pages', 3, 'title'), 'German page title was changed' . $this->transcript());
        $this->assertSame('Get in touch', $this->field('pages', 30, 'title'), 'English page title was not changed' . $this->transcript());
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] "Translate the opening hours on the English contact page" → creates an EN translation of 301, German 301 untouched')]
    public function testTranslatesUntranslatedContentElement(string $modelKey): void
    {
        $this->setModel($modelKey);
        $this->runConversation(
            'Auf der englischen Kontaktseite steht der Abschnitt mit den Öffnungszeiten noch auf Deutsch. Bitte übersetze ihn ins Englische.'
        );

        $this->assertSame('Öffnungszeiten', $this->field('tt_content', 301, 'header'), 'German original was changed' . $this->transcript());
        $this->assertStringContainsString('Montag', (string)$this->field('tt_content', 301, 'bodytext'), 'German original was changed' . $this->transcript());

        $translation = $this->findTranslation('tt_content', 301);
        $this->assertNotNull($translation, 'No English translation of content element 301 was created' . $this->transcript());
        $this->assertSame(3, (int)$translation['pid'], 'Translation is not on the contact page' . $this->transcript());
        $this->assertTranslated('tt_content', 301, $translation, ['header', 'bodytext']);
        $this->assertNoNewDefaultLanguageRecords();
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] "Create the English version of the services page" → translates page 4 and its content, no new German records')]
    public function testCreatesEnglishVersionOfUntranslatedPage(string $modelKey): void
    {
        $this->setModel($modelKey);
        $this->runConversation('Lege die englische Version der Seite „Leistungen“ an und übersetze dabei den Titel und alle Inhalte.');

        $this->assertSame('Leistungen', $this->field('pages', 4, 'title'), 'German page title was changed' . $this->transcript());
        $this->assertSame('Webentwicklung', $this->field('tt_content', 400, 'header'), 'German content was changed' . $this->transcript());
        $this->assertSame('Beratung', $this->field('tt_content', 401, 'header'), 'German content was changed' . $this->transcript());

        $pageTranslation = $this->findTranslation('pages', 4);
        $this->assertNotNull($pageTranslation, 'No English translation of page 4 was created' . $this->transcript());
        $this->assertNotSame('Leistungen', $pageTranslation['title'], 'Page translation still has the German title' . $this->transcript());

        foreach ([400, 401] as $uid) {
            $translation = $this->findTranslation('tt_content', $uid);
            $this->assertNotNull($translation, "No English translation of content element $uid was created" . $this->transcript());
            $this->assertSame(4, (int)$translation['pid'], "Translation of $uid is not on the services page" . $this->transcript());
            $this->assertTranslated('tt_content', $uid, $translation, ['header', 'bodytext']);
        }
        $this->assertNoNewDefaultLanguageRecords();
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] "On the English about page, change the heading" → updates EN element 210, German 200 untouched')]
    public function testEditsTranslatedContentElement(string $modelKey): void
    {
        $this->setModel($modelKey);
        $this->runConversation('Auf der englischen „Über uns“-Seite soll die Überschrift „Who we are“ heißen: „About our agency“.');

        $this->assertSame('Wer wir sind', $this->field('tt_content', 200, 'header'), 'German original was changed' . $this->transcript());
        $this->assertSame('About our agency', $this->field('tt_content', 210, 'header'), 'English element was not changed' . $this->transcript());
    }

    /**
     * Let the LLM work until it stops calling tools, executing every call.
     */
    private function runConversation(string $prompt): LlmResponse
    {
        $this->transcriptLines = [];
        $response = $this->callLlm($prompt);
        for ($round = 0; $round < self::MAX_ROUNDS && $response->hasToolCalls(); $round++) {
            $results = [];
            foreach ($response->getToolCalls() as $toolCall) {
                $result = $this->executeToolCall($toolCall);
                $results[] = $result;
                $this->transcriptLines[] = sprintf(
                    "%s %s%s\n%s",
                    $toolCall['name'],
                    json_encode($toolCall['arguments'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ($result['isError'] ?? false) || isset($result['error']) ? ' → ERROR' : '',
                    $result['content'] ?? ''
                );
            }
            $response = $this->continueWithToolResult($response, $results);
        }
        $this->transcriptLines[] = 'FINAL: ' . $response->getContent();
        $this->writeTranscript();
        return $response;
    }

    /**
     * Full conversation for later analysis, one file per test, model and attempt.
     */
    private function writeTranscript(): void
    {
        $dir = __DIR__ . '/../../.Build/llm-transcripts';
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return;
        }
        $file = sprintf('%s/%s-%s-%d.txt', $dir, $this->name(), (string)$this->dataName(), $this->attemptCount);
        file_put_contents($file, "PROMPT: {$this->lastPrompt}\n\n" . implode("\n\n---\n\n", $this->transcriptLines) . "\n");
    }

    /**
     * Tool calls of the conversation (without results) for failure messages.
     */
    private function transcript(): string
    {
        $calls = array_map(static fn(string $line): string => strtok($line, "\n"), $this->transcriptLines);
        return "\n[Model: {$this->llmModel}]\nPrompt: {$this->lastPrompt}\n" . implode("\n", $calls);
    }

    /**
     * Field value as the current workspace sees it.
     */
    private function field(string $table, int $uid, string $field): mixed
    {
        $record = BackendUtility::getRecordWSOL($table, $uid);
        return $record[$field] ?? null;
    }

    /**
     * English translation of a record as the current workspace sees it, or null.
     */
    private function findTranslation(string $table, int $originalUid): ?array
    {
        $pointerField = $GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'];
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder->select('uid', 't3ver_oid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq($pointerField, $originalUid),
                $queryBuilder->expr()->eq('sys_language_uid', 1),
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->executeQuery()
            ->fetchAllAssociative();
        foreach ($rows as $row) {
            $record = BackendUtility::getRecordWSOL($table, (int)($row['t3ver_oid'] ?: $row['uid']));
            if (is_array($record)) {
                return $record;
            }
        }
        return null;
    }

    /**
     * The translation's fields differ from the German original, so the text was translated
     * and not just copied into an English record
     */
    private function assertTranslated(string $table, int $originalUid, array $translation, array $fields): void
    {
        $original = BackendUtility::getRecordWSOL($table, $originalUid);
        foreach ($fields as $field) {
            $translated = trim(strip_tags((string)($translation[$field] ?? '')));
            $this->assertNotSame('', $translated, "Translation of $table:$originalUid has an empty $field" . $this->transcript());
            $this->assertNotSame(
                trim(strip_tags((string)$original[$field])),
                $translated,
                "Translation of $table:$originalUid still has the German $field" . $this->transcript()
            );
        }
    }

    /**
     * Translating must not create German copies (e.g. "create" instead of "translate").
     */
    private function assertNoNewDefaultLanguageRecords(): void
    {
        foreach (['pages' => [1, 2, 3, 4], 'tt_content' => [100, 200, 300, 301, 400, 401]] as $table => $fixtureUids) {
            $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();
            $uids = $queryBuilder->select('uid')
                ->from($table)
                ->where(
                    $queryBuilder->expr()->eq('sys_language_uid', 0),
                    $queryBuilder->expr()->eq('deleted', 0),
                    $queryBuilder->expr()->eq('t3ver_oid', 0),
                    $queryBuilder->expr()->notIn('uid', $fixtureUids)
                )
                ->executeQuery()
                ->fetchFirstColumn();
            $this->assertSame([], $uids, "New default-language $table records were created instead of translations" . $this->transcript());
        }
    }
}
