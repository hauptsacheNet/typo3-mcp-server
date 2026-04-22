<?php

declare(strict_types=1);

use Hn\McpServer\MCP\Tool\GetSystemStatusTool;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Reports\Registry\StatusRegistry;

return static function (ContainerConfigurator $configurator, ContainerBuilder $containerBuilder): void {
    // GetSystemStatusTool taps into EXT:reports' StatusRegistry. It is only
    // useful when EXT:reports is actually loaded in TYPO3. We register the
    // tool unconditionally here, then drop it again via a compiler pass if
    // the StatusRegistry service turns out to be missing. That way the tool
    // is silently absent from the MCP tool list whenever EXT:reports is not
    // installed.
    $services = $configurator->services();
    $services->set(GetSystemStatusTool::class)
        ->autowire()
        ->autoconfigure();

    $containerBuilder->addCompilerPass(new class () implements CompilerPassInterface {
        public function process(ContainerBuilder $container): void
        {
            if (!$container->has(StatusRegistry::class)) {
                $container->removeDefinition(GetSystemStatusTool::class);
            }
        }
    });
};
