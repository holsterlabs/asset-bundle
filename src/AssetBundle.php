<?php

declare(strict_types=1);

namespace Hl\AssetBundle;

use Hl\AssetBundle\Command\CleanupCommand;
use Hl\AssetBundle\Minifier\MatthiasMullieMinifier;
use Hl\AssetBundle\Minifier\MinifierInterface;
use Hl\AssetBundle\Twig\AssetExtension;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class AssetBundle extends AbstractBundle
{
    protected string $extensionAlias = 'hl_assets';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()->children()
            ->scalarNode('public_dir')->defaultValue('%kernel.project_dir%/public')->end()
            ->scalarNode('output_dir')->defaultValue('%kernel.project_dir%/public/processed')->end()
            ->scalarNode('publication_dir')->defaultValue('%kernel.project_dir%/public/_assets')->end()
            ->scalarNode('cache_dir')->defaultValue('%kernel.project_dir%/var/asset-bundle')->end()
            ->scalarNode('base_url')->defaultValue('')->end()
            ->scalarNode('version')->defaultValue('1')->end()
            ->integerNode('retention')->min(7200)->defaultValue(604800)->end()
            ->arrayNode('roots')->useAttributeAsKey('name')->scalarPrototype()->end()->defaultValue([])->end()
            ->arrayNode('minify')->addDefaultsIfNotSet()->children()
                ->booleanNode('css')->defaultFalse()->end()
                ->booleanNode('js')->defaultFalse()->end()
            ->end()->end()
        ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();
        $services->set(MatthiasMullieMinifier::class);
        $services->alias(MinifierInterface::class, MatthiasMullieMinifier::class);
        $services->set(AssetProcessor::class)->args([
            '%kernel.project_dir%', $config['public_dir'], $config['output_dir'], $config['publication_dir'],
            $config['cache_dir'], $config['roots'], $config['base_url'], $config['version'],
            service(MinifierInterface::class), $config['minify'],
        ]);
        $services->set(AssetCollector::class)->args([service(AssetProcessor::class)])
            ->tag('kernel.reset', ['method' => 'reset']);
        $services->set(AssetExtension::class)->args([service(AssetCollector::class)])->tag('twig.extension');
        $services->set(CleanupCommand::class)->args([service(AssetProcessor::class), $config['retention']])->tag('console.command');
    }
}
