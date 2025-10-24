<?php


namespace Survos\CoreBundle\Traits;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

trait HasAssetMapperTrait
{
    protected string $extensionPrefix = '';

    public function isAssetMapperAvailable(ContainerBuilder $container): bool
    {
        if (!interface_exists(AssetMapperInterface::class)) {
            return false;
        }

        // check that FrameworkBundle 6.3 or higher is installed
        $bundlesMetadata = $container->getParameter('kernel.bundles_metadata');
        if (!isset($bundlesMetadata['FrameworkBundle'])) {
            assert(false, 'symfony framework 6.3+ required.');
            return false;
        }

        $file = $bundlesMetadata['FrameworkBundle']['path'].'/Resources/config/asset_mapper.php';
        assert(is_file($file), $file);
        return is_file($file);
    }

    public function getPaths(): array
    {
        throw new \Exception("getPaths() must be set, e.g. @survos/code. " . static::class);
        return [];
        // usually something like
        $dir = realpath(__DIR__ . '/../assets/');
        assert(file_exists($dir), $dir);
        return [$dir = '@survos/pwa-extra'];

    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // NOTE: you MUST set keyword: ["symfony-ux"] in composer.json for automatically including the assets

        if (!$this->isAssetMapperAvailable($builder)) {
            return;
        }

        $builder->prependExtensionConfig('framework', $x = [
            'asset_mapper' => [
                'paths' => $this->getPaths(),
            ],
        ]);
    }


}
