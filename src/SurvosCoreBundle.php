<?php

namespace Survos\CoreBundle;

use Survos\CoreBundle\Request\ParameterResolver;
use Survos\CoreBundle\Service\ChunkDownloader;
use Survos\CoreBundle\Service\SurvosUtils;
use Survos\CoreBundle\Twig\TwigExtension;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class SurvosCoreBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {

        // twig classes
        $builder
            ->autowire('survos.core_twig', TwigExtension::class)
            ->addTag('twig.extension');

        $builder
            ->autowire(SurvosUtils::class)
            ->setAutoconfigured(true)
            ->setAutowired(true);

        $builder
            ->autowire(ChunkDownloader::class)
            ->setPublic(true)
            ->setAutoconfigured(true)
            ->setAutowired(true);

        $builder
            ->autowire(ParameterResolver::class)
            ->setAutoconfigured(true)
            ->setAutowired(true)
            ->addTag('controller.argument_value_resolver', [
                'name' => 'ParameterResolver',
                'priority' => 200,
            ]);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            // enable the twig extension?
                ->booleanNode('enabled')->defaultTrue()->end()
                ->booleanNode('dd')->defaultTrue()->end()
            ->end();
    }
}
