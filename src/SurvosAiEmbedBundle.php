<?php

declare(strict_types=1);

namespace Survos\AiEmbedBundle;

use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

#[RequiredBundle(SurvosKitBundle::class)]
// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosAiEmbedBundle extends AbstractSurvosBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()->children()
            ->booleanNode('enabled')->defaultFalse()->end()
            ->scalarNode('cache_path')->defaultValue('%kernel.project_dir%/var/embeddings/cache.duckdb')->end()
            ->scalarNode('model')->defaultValue('nomic-embed-text:v1.5')->end()
            ->scalarNode('revision')->defaultValue('0a109f422b47e3a30ba2b10eca18548e944e8a23073ee3f3e947efcf3c45e59f')->end()
            ->integerNode('dimensions')->min(1)->defaultValue(768)->end()
            ->scalarNode('url')->defaultValue('http://localhost:11434')->end()
        ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);
        if (!$config['enabled']) {
            return;
        }
        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set('ai_embed.http', HttpClientInterface::class)->factory([HttpClient::class, 'create']);
        $services->set(EmbeddingProfile::class)->args([$config['model'], $config['revision'], $config['dimensions']]);
        $services->set(DuckDbEmbeddingCache::class)->args([$config['cache_path']]);
        $services->set(OllamaEmbeddingProvider::class)->args([service('ai_embed.http'), $config['url']]);
        $services->alias(EmbeddingProviderInterface::class, OllamaEmbeddingProvider::class);
        $services->set(EmbeddingService::class);
    }
}
