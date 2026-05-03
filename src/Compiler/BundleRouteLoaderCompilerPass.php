<?php

declare(strict_types=1);

namespace Survos\CoreBundle\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Hijacks `router.resource` to chain a survos bundle's BundleRouteLoader in.
 *
 * Mirrors the pattern endroid/qr-code-bundle 7 uses. Each survos bundle that
 * uses `HasConfigurableRoutes` adds one of these passes (constructed with the
 * bundle's loader service id). The pass:
 *
 *   1. Reads the current value of `router.resource` (the previous link in
 *      the chain — kernel route loader, or another bundle's loader)
 *   2. Sets that value as the bundle loader's `$originalResource` arg
 *   3. Replaces `router.resource` with the bundle loader's id
 *
 * Order of compiler-pass execution determines stack order; the resulting
 * route collection is the same regardless because each loader chains to the
 * previous via `$originalResource`.
 *
 * Skips silently when:
 *   - The bundle's loader service hasn't been registered (routes_enabled=false)
 *   - The router's `resource_type` is not 'service' (older yaml-resource setups)
 */
final class BundleRouteLoaderCompilerPass implements CompilerPassInterface
{
    public function __construct(private string $loaderServiceId) {}

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition($this->loaderServiceId)) {
            return;
        }

        if (!$container->hasParameter('router.resource')) {
            return;
        }

        $router = $container->findDefinition('router.default');
        $options = $router->getArgument(2);
        if (!\is_array($options) || ($options['resource_type'] ?? null) !== 'service') {
            return;
        }

        $originalResource = $container->getParameter('router.resource');
        $container->getDefinition($this->loaderServiceId)
            ->setArgument('$originalResource', $originalResource);

        $container->setParameter('router.resource', $this->loaderServiceId);
    }
}
