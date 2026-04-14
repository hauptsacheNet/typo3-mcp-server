<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Event;

use Hn\McpServer\Event\ModifyAvailableFieldsEvent;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Stateful test listener for ModifyAvailableFieldsEvent
 */
class ModifyAvailableFieldsTestListener
{
    public static bool $dispatched = false;
    public static string $table = '';
    public static string $type = '';
    public static ?string $removeField = null;
    public static ?array $addField = null;
    public static ?array $replaceFields = null;

    public static function reset(): void
    {
        self::$dispatched = false;
        self::$table = '';
        self::$type = '';
        self::$removeField = null;
        self::$addField = null;
        self::$replaceFields = null;
    }

    public function __invoke(ModifyAvailableFieldsEvent $event): void
    {
        self::$dispatched = true;
        self::$table = $event->getTable();
        self::$type = $event->getType();

        if (self::$removeField !== null) {
            $event->removeField(self::$removeField);
        }

        if (self::$addField !== null) {
            $event->addField(self::$addField['name'], self::$addField['config']);
        }

        if (self::$replaceFields !== null) {
            $event->setFields(self::$replaceFields);
        }
    }
}

/**
 * Tests for ModifyAvailableFieldsEvent
 */
class ModifyAvailableFieldsEventTest extends AbstractFunctionalTest
{
    private TableAccessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = GeneralUtility::makeInstance(TableAccessService::class);

        ModifyAvailableFieldsTestListener::reset();

        $container = GeneralUtility::getContainer();
        $container->set(ModifyAvailableFieldsTestListener::class, new ModifyAvailableFieldsTestListener());

        $listenerProvider = $container->get(ListenerProvider::class);
        $listenerProvider->addListener(ModifyAvailableFieldsEvent::class, ModifyAvailableFieldsTestListener::class);
    }

    /**
     * Event is dispatched when getting available fields
     */
    public function testEventIsDispatched(): void
    {
        $this->service->getAvailableFields('pages');

        $this->assertTrue(ModifyAvailableFieldsTestListener::$dispatched);
        $this->assertEquals('pages', ModifyAvailableFieldsTestListener::$table);
    }

    /**
     * Listener can remove fields
     */
    public function testListenerCanRemoveFields(): void
    {
        $fieldsBefore = $this->service->getAvailableFields('pages');
        $this->assertArrayHasKey('title', $fieldsBefore);

        ModifyAvailableFieldsTestListener::$removeField = 'title';

        $fieldsAfter = $this->service->getAvailableFields('pages');
        $this->assertArrayNotHasKey('title', $fieldsAfter);
    }

    /**
     * Listener can add fields
     */
    public function testListenerCanAddFields(): void
    {
        ModifyAvailableFieldsTestListener::$addField = [
            'name' => 'custom_computed',
            'config' => ['config' => ['type' => 'input'], 'label' => 'Custom'],
        ];

        $fields = $this->service->getAvailableFields('pages');
        $this->assertArrayHasKey('custom_computed', $fields);
    }

    /**
     * Event receives correct type parameter
     */
    public function testEventReceivesType(): void
    {
        $this->service->getAvailableFields('tt_content', 'textmedia');

        $this->assertEquals('textmedia', ModifyAvailableFieldsTestListener::$type);
    }

    /**
     * setFields replaces entire field list
     */
    public function testSetFieldsReplacesAll(): void
    {
        ModifyAvailableFieldsTestListener::$replaceFields = [
            'only_field' => ['config' => ['type' => 'input']],
        ];

        $fields = $this->service->getAvailableFields('pages');
        $this->assertCount(1, $fields);
        $this->assertArrayHasKey('only_field', $fields);
    }
}
