<?php

declare(strict_types=1);

namespace Survos\CoreBundle\Traits;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

trait HasAssetMapperTrait
{
    // Backward compatibility for bundles not yet migrated to AssetMapperBundle.
    // Preferred in bundle class: const ASSET_PACKAGE = 'foo';
    // Optional override: const ASSET_NAMESPACE = '@survos/foo';
    // Also ensure "symfony-ux" is in composer.json keywords for auto-registration.

    public function isAssetMapperAvailable(ContainerBuilder $container): bool
    {
        return interface_exists(AssetMapperInterface::class)
            && $container->hasExtension('framework');
    }

    public function getPaths(): array
    {
        $bundlePath = method_exists($this, 'getPath')
            ? $this->getPath()
            : dirname((new \ReflectionClass($this))->getFileName(), 2);
        $dir = realpath($bundlePath.'/assets');
        assert($dir && file_exists($dir), 'assets path must exist: '.$bundlePath);

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
