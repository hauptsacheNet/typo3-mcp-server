<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * TYPO3 14 introduced label references in translation domain syntax
 * (e.g. `frontend.db.tt_content:bodytext`) and uses them throughout the core
 * TCA. They must be resolved like `LLL:` references instead of being passed
 * through raw.
 */
class TableAccessServiceTranslateLabelTest extends AbstractFunctionalTest
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('en');
    }

    private static function supportsDomainSyntax(): bool
    {
        return GeneralUtility::makeInstance(Typo3Version::class)->getMajorVersion() >= 14;
    }

    public static function domainReferenceProvider(): array
    {
        return [
            'tt_content field label' => ['frontend.db.tt_content:bodytext', 'Text'],
            'form tab label' => ['core.form.tabs:general', 'General'],
        ];
    }

    #[DataProvider('domainReferenceProvider')]
    public function testDomainReferenceIsResolved(string $label, string $expected): void
    {
        if (!self::supportsDomainSyntax()) {
            // TYPO3 13 has no domain syntax: the label is returned unchanged
            $this->assertSame($label, TableAccessService::translateLabel($label));
            return;
        }

        $this->assertSame($expected, TableAccessService::translateLabel($label));
    }

    public static function unchangedLabelProvider(): array
    {
        return [
            'plain label' => ['Header'],
            'label containing a colon' => ['Note: read carefully'],
            'foreign type notation' => ['uid_local:type'],
            'unknown domain' => ['unknown_ext.some.file:key'],
            'unknown key in known domain' => ['core.form.tabs:does_not_exist'],
        ];
    }

    #[DataProvider('unchangedLabelProvider')]
    public function testNonReferenceOrUnresolvableLabelIsReturnedUnchanged(string $label): void
    {
        $this->assertSame($label, TableAccessService::translateLabel($label));
    }

    public function testLllReferenceIsStillResolved(): void
    {
        $this->assertSame(
            'This page',
            TableAccessService::translateLabel('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.depth_0')
        );
    }

    public function testTableSchemaContainsNoRawDomainReferences(): void
    {
        if (!self::supportsDomainSyntax()) {
            $this->markTestSkipped('Translation domain syntax exists since TYPO3 14');
        }

        $tool = GeneralUtility::makeInstance(GetTableSchemaTool::class);
        $result = $tool->execute(['table' => 'tt_content', 'type' => 'textmedia']);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $output = $result->content[0]->text;

        $this->assertStringContainsString('- bodytext (Text):', $output);
        $this->assertStringNotContainsString('frontend.db.tt_content:', $output);
        $this->assertStringNotContainsString('core.form.tabs:', $output);
        $this->assertStringNotContainsString('core.db.general:', $output);
    }
}
