<?php

namespace Survos\CoreBundle\Entity;

/**
 * @deprecated Use #[Survos\FieldBundle\Attribute\RouteIdentity] + Survos\FieldBundle\Entity\RouteIdentityTrait instead.
 *             The interface contract (getRp, getUniqueIdentifiers, getClassnamePrefix) is fulfilled by RouteIdentityTrait.
 *             Keep `implements RouteParametersInterface` on existing entities until field-bundle fully replaces this interface.
 */
interface RouteParametersInterface
{
    public function getUniqueIdentifiers(): array;

    public function getRp(?array $addlParams = []): array;

    public static function getClassnamePrefix(string|null $class = null): string;
}
