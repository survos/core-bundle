<?php

declare(strict_types=1);

namespace Survos\CoreBundle\Traits;

use Survos\CoreBundle\Compiler\BundleRouteLoaderCompilerPass;
use Survos\CoreBundle\Routing\BundleRouteLoader;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Adds standardized route registration to a survos bundle:
 *
 *   - routes_enabled: bool (default true) — toggle the bundle's route registration
 *   - route_prefix:   string              — URL prefix for all bundle routes
 *
 * Replaces the legacy `<bundle>/config/routes.yaml` boilerplate
 * (`type: attribute, resource: '@SurvosXxxBundle/src/Controller/'`) and the
 * matching app-level `<app>/config/routes/survos_xxx.yaml` import file. Once
 * a bundle adopts this trait, `composer require` is enough — no recipe, no
 * import, no surprise.
 *
 * Apps disable a bundle's auto-registration with:
 *
 *     # config/packages/survos_xxx.yaml
 *     survos_xxx:
 *         routes_enabled: false
 *
 * Apps override the prefix with:
 *
 *     survos_xxx:
 *         route_prefix: /admin/xxx
 *
 * Bundles that use this trait should call (in this order):
 *
 *   1. `addRouteOptions($children, '/default-prefix')` from `configure()`
 *   2. `captureRouteConfig($config)` at the top of `loadExtension()`
 *   3. `registerRouteLoader($builder)` later in `loadExtension()`
 *   4. `addRouteLoaderCompilerPass($container)` from `build()`
 *
 * Place routed controllers under `<bundle>/Controller/`. Bundles needing
 * exclude lists (meili) or extra programmatic routes can register a custom
 * loader service in place of the default.
 */
trait HasConfigurableRoutes
{
    private bool   $routesEnabled = true;
    private string $routePrefix   = '';

    /**
     * Add the standard `routes_enabled` + `route_prefix` nodes to a bundle's
     * config schema. Call from `configure()`:
     *
     *     $children = $definition->rootNode()->children();
     *     $this->addRouteOptions($children, '/my-prefix');
     *     $children->scalarNode('myOption')->...->end();
     *     $children->end();
     */
    protected function addRouteOptions(NodeBuilder $children, string $defaultPrefix): void
    {
        $children
            ->booleanNode('routes_enabled')->defaultTrue()
                ->info('Auto-register this bundle\'s controllers via attribute scanning. Set false to manage routes manually in your app\'s config/routes/.')
            ->end()
            ->scalarNode('route_prefix')->defaultValue($defaultPrefix)
                ->info('URL prefix applied to this bundle\'s routes.')
            ->end()
        ;
    }

    /**
     * Capture resolved route config from `loadExtension()` into the bundle
     * instance so subsequent helpers can read it.
     *
     * @param array<string, mixed> $config
     */
    protected function captureRouteConfig(array $config): void
    {
        $this->routesEnabled = (bool) ($config['routes_enabled'] ?? true);
        $this->routePrefix   = (string) ($config['route_prefix'] ?? '');
    }

    /**
     * Register the bundle's route loader service (tagged `routing.route_loader`).
     * Call from `loadExtension()` AFTER `captureRouteConfig()`.
     *
     * Service id is derived from `$bundle->getAlias() . '.route_loader'` —
     * `BundleRouteLoaderCompilerPass` references the same id when hijacking
     * `router.resource`.
     */
    protected function registerRouteLoader(ContainerBuilder $builder): void
    {
        if (!$this->routesEnabled) {
            return;
        }

        $controllerDir = $this->controllerDirectory();
        if (!\is_dir($controllerDir)) {
            return;
        }

        $builder->register($this->routeLoaderServiceId(), BundleRouteLoader::class)
            ->setArgument('$originalResource',         '') // overwritten by the compiler pass
            ->setArgument('$controllerDir',            $controllerDir)
            ->setArgument('$routePrefix',              $this->routePrefix)
            ->setArgument('$attributeDirectoryLoader', new Reference('routing.loader.attribute.directory'))
            ->addTag('routing.route_loader');
    }

    /**
     * Add the compiler pass that chains the bundle's loader into router.resource.
     * Call from `build()`:
     *
     *     public function build(ContainerBuilder $container): void
     *     {
     *         parent::build($container);
     *         $this->addRouteLoaderCompilerPass($container);
     *     }
     */
    protected function addRouteLoaderCompilerPass(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new BundleRouteLoaderCompilerPass($this->routeLoaderServiceId()));
    }

    /**
     * Default `<bundle-dir>/Controller/`. Override on the bundle class for
     * non-standard locations.
     */
    protected function controllerDirectory(): string
    {
        return \dirname((new \ReflectionClass($this))->getFileName()) . '/Controller/';
    }

    /**
     * Default `<alias>.route_loader`. Override only when there's a name conflict.
     */
    protected function routeLoaderServiceId(): string
    {
        // AbstractBundle exposes the alias via its lazy-initialized extension
        // (e.g. SurvosStorageBundle → 'survos_storage').
        return $this->getContainerExtension()->getAlias() . '.route_loader';
    }
}
