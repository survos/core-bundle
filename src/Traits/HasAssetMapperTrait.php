<?php

namespace Survos\CoreBundle\Traits;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

trait HasAssetMapperTrait
{
    // Must be defined in bundle class: const ASSET_NAMESPACE = '@survos/foo';
    // Also ensure "symfony-ux" is in composer.json keywords for auto-registration.

    public function isAssetMapperAvailable(ContainerBuilder $container): bool
    {
        if (!interface_exists(AssetMapperInterface::class)) {
            return false;
        }

        $bundlesMetadata = $container->getParameter('kernel.bundles_metadata');
        if (!isset($bundlesMetadata['FrameworkBundle'])) {
            return false;
        }

        $file = $bundlesMetadata['FrameworkBundle']['path'].'/Resources/config/asset_mapper.php';
        return is_file($file);
    }

    public function getPaths(): array
    {
        $dir = realpath(__DIR__ . '/../assets/');
        assert(file_exists($dir), 'assets path must exist: ' . __DIR__);
        return [$dir => static::ASSET_NAMESPACE];
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
