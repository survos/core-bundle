<?php

declare(strict_types=1);

namespace Survos\CoreBundle\Routing;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Routing\RouteCollection;

/**
 * Generic route loader injected by `HasConfigurableRoutes`.
 *
 * Each survos bundle that uses the trait registers one of these (its service
 * id derived from `$bundle->getAlias() . '.route_loader'`). The loader is
 * tagged `routing.route_loader` so Symfony's router knows to dispatch through
 * it when its turn comes in the `router.resource` chain.
 *
 * `BundleRouteLoaderCompilerPass` hijacks `router.resource` to put this
 * loader in the chain. At route-load time the loader does:
 *
 *   1. Loads the previous resource (chain → the kernel's own routes)
 *   2. Imports the bundle's `Controller/` via the dedicated attribute loader
 *      (the $loader passed to __invoke is a service-only ContainerLoader, so
 *      we can't dispatch attribute-type loads through it — we inject the
 *      `routing.loader.attribute.directory` service explicitly)
 *   3. Applies the bundle's configured prefix
 *
 * Multiple bundles stack cleanly — each pass captures whatever the previous
 * one set as `router.resource`.
 */
final class BundleRouteLoader
{
    public function __construct(
        private string $originalResource,
        private string $controllerDir,
        private string $routePrefix,
        private LoaderInterface $attributeDirectoryLoader,
    ) {}

    public function __invoke(LoaderInterface $loader, ?string $_env): RouteCollection
    {
        /** @var RouteCollection $collection */
        $collection = $loader->load($this->originalResource);

        if (!\is_dir($this->controllerDir)) {
            return $collection;
        }

        /** @var RouteCollection $bundleRoutes */
        $bundleRoutes = $this->attributeDirectoryLoader->load($this->controllerDir, 'attribute');

        if ($this->routePrefix !== '') {
            $bundleRoutes->addPrefix($this->routePrefix);
        }

        $collection->addCollection($bundleRoutes);

        return $collection;
    }
}
