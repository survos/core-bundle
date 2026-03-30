<?php

declare(strict_types=1);

namespace Survos\CoreBundle\Bundle;

use Survos\CoreBundle\HasAssetMapperInterface;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

abstract class AssetMapperBundle extends AbstractBundle implements HasAssetMapperInterface
{
    // IMPORTANT: bundle composer.json must include the "symfony-ux" keyword,
    // otherwise Symfony UX won't auto-discover controllers from assets/package.json.
    public function isAssetMapperAvailable(ContainerBuilder $container): bool
    {
        return interface_exists(AssetMapperInterface::class)
            && $container->hasExtension('framework');
    }

    public function getPaths(): array
    {
        $dir = realpath($this->getPath().'/assets');
        assert($dir && file_exists($dir), 'assets path must exist: '.$this->getPath());

        return [$dir => $this->getAssetNamespace()];
    }

    public function getAssetNamespace(): string
    {
        if (defined('static::ASSET_NAMESPACE')) {
            /** @var string $namespace */
            $namespace = static::ASSET_NAMESPACE;

            return $namespace;
        }

        if (defined('static::ASSET_PACKAGE')) {
            /** @var string $package */
            $package = static::ASSET_PACKAGE;

            if (str_starts_with($package, '@')) {
                return $package;
            }

            $package = preg_replace('#^survos/#', '', $package) ?? $package;

            return '@survos/'.trim($package, '/');
        }

        $shortName = (new \ReflectionClass($this))->getShortName();
        $shortName = preg_replace('/^Survos/', '', $shortName) ?? $shortName;
        $shortName = preg_replace('/Bundle$/', '', $shortName) ?? $shortName;
        $slug = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $shortName));

        return '@survos/'.$slug;
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$this->isAssetMapperAvailable($builder)) {
            return;
        }

        $builder->prependExtensionConfig('framework', [
            'asset_mapper' => [
                'paths' => $this->getPaths(),
            ],
        ]);
    }
}
