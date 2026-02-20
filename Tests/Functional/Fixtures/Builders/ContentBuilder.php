<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Fixtures\Builders;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Builder for creating content element records in tests
 */
class ContentBuilder
{
    private ConnectionPool $connectionPool;
    
    private array $data = [
        'pid' => 0,
        'CType' => 'textmedia',
        'header' => 'Test Content',
        'header_layout' => '0',
        'header_position' => '',
        'header_link' => '',
        'bodytext' => '',
        'colPos' => 0,
        'sorting' => 256,
        'hidden' => 0,
        'deleted' => 0,
    ];
    
    public function __construct(ConnectionPool $connectionPool)
    {
        $this->connectionPool = $connectionPool;
    }
    
    /**
     * Set the page ID where content should be placed
     */
    public function onPage(int $pid): self
    {
        $this->data['pid'] = $pid;
        return $this;
    }
    
    /**
     * Set the content type
     */
    public function withType(string $cType): self
    {
        $this->data['CType'] = $cType;
        return $this;
    }
    
    /**
     * Set the header
     */
    public function withHeader(string $header): self
    {
        $this->data['header'] = $header;
        return $this;
    }
    
    /**
     * Set the bodytext
     */
    public function withBodytext(string $bodytext): self
    {
        $this->data['bodytext'] = $bodytext;
        return $this;
    }
    
    /**
     * Set as textmedia type with content
     */
    public function asTextMedia(string $header, string $bodytext): self
    {
        $this->data['CType'] = 'textmedia';
        $this->data['header'] = $header;
        $this->data['bodytext'] = $bodytext;
        return $this;
    }
    
    /**
     * Set as text type
     */
    public function asText(string $header, string $bodytext): self
    {
        $this->data['CType'] = 'text';
        $this->data['header'] = $header;
        $this->data['bodytext'] = $bodytext;
        return $this;
    }
    
    /**
     * Set as header type
     */
    public function asHeader(string $header, int $layout = 1): self
    {
        $this->data['CType'] = 'header';
        $this->data['header'] = $header;
        $this->data['header_layout'] = (string)$layout;
        return $this;
    }
    
    /**
     * Set as plugin type.
     * In TYPO3 14+, plugins have their own CType (e.g., 'news_pi1').
     * In TYPO3 13, plugins use CType='list' with list_type field.
     */
    public function asPlugin(string $listType, string $header = ''): self
    {
        $this->data['header'] = $header ?: $listType;
        $typo3Version = GeneralUtility::makeInstance(Typo3Version::class);
        if ($typo3Version->getMajorVersion() >= 14) {
            // In TYPO3 14+, each plugin has its own CType
            $this->data['CType'] = $listType;
        } else {
            // In TYPO3 13, plugins use the generic 'list' CType with a list_type selector
            $this->data['CType'] = 'list';
            $this->with('list_type', $listType);
        }
        return $this;
    }
    
    /**
     * Set column position
     */
    public function inColumn(int $colPos): self
    {
        $this->data['colPos'] = $colPos;
        return $this;
    }
    
    /**
     * Set sorting value
     */
    public function withSorting(int $sorting): self
    {
        $this->data['sorting'] = $sorting;
        return $this;
    }
    
    /**
     * Set as hidden
     */
    public function hidden(): self
    {
        $this->data['hidden'] = 1;
        return $this;
    }
    
    /**
     * Set as visible
     */
    public function visible(): self
    {
        $this->data['hidden'] = 0;
        return $this;
    }
    
    /**
     * Set language UID
     */
    public function withLanguage(int $languageUid): self
    {
        return $this->with('sys_language_uid', $languageUid);
    }
    
    /**
     * Set l10n parent for translations
     */
    public function withL10nParent(int $parentUid): self
    {
        return $this->with('l10n_parent', $parentUid)
            ->with('l10n_source', $parentUid);
    }
    
    /**
     * Set FlexForm data
     */
    public function withFlexForm(string $flexFormXml): self
    {
        return $this->with('pi_flexform', $flexFormXml);
    }
    
    /**
     * Set pages for list types
     */
    public function withPages(string $pages): self
    {
        return $this->with('pages', $pages);
    }
    
    /**
     * Set recursive level
     */
    public function withRecursive(int $recursive): self
    {
        return $this->with('recursive', $recursive);
    }
    
    /**
     * Set frame class
     */
    public function withFrameClass(string $frameClass): self
    {
        return $this->with('frame_class', $frameClass);
    }
    
    /**
     * Set layout
     */
    public function withLayout(string $layout): self
    {
        return $this->with('layout', $layout);
    }
    
    /**
     * Set custom data field
     */
    public function with(string $field, $value): self
    {
        $this->data[$field] = $value;
        return $this;
    }
    
    /**
     * Create the content record and return its UID
     */
    public function create(): int
    {
        // Set timestamps
        $this->data['tstamp'] = time();
        $this->data['crdate'] = time();
        
        $connection = $this->connectionPool->getConnectionForTable('tt_content');
        $connection->insert('tt_content', $this->data);
        
        return (int)$connection->lastInsertId();
    }
    
    /**
     * Create multiple content elements with incremented headers
     * 
     * @param int $count Number of elements to create
     * @return array Array of created UIDs
     */
    public function createMultiple(int $count): array
    {
        $uids = [];
        $baseHeader = $this->data['header'];
        $baseSorting = $this->data['sorting'];
        
        for ($i = 1; $i <= $count; $i++) {
            $this->data['header'] = $baseHeader . ' ' . $i;
            $this->data['sorting'] = $baseSorting + ($i * 256);
            $uids[] = $this->create();
        }
        
        // Reset values
        $this->data['header'] = $baseHeader;
        $this->data['sorting'] = $baseSorting;
        
        return $uids;
    }
}