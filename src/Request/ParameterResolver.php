<?php

namespace Survos\CoreBundle\Request;

use Doctrine\ORM\EntityManagerInterface;
use Survos\CoreBundle\Entity\RouteParametersInterface;
use Survos\FieldBundle\Attribute\RouteIdentity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

/**
 * Resolves controller arguments typed as a RouteParametersInterface entity
 * by looking up the entity by its URL identity.
 *
 * Two sources of truth, checked in order:
 *
 *   1. #[Survos\FieldBundle\Attribute\RouteIdentity] (new, preferred)
 *      Supports the `parents:` chain — given /{tenantId}/{accCode}/...
 *      and Acc's `parents: ['tenant']`, the resolver walks the chain by
 *      recursively resolving Tenant first, then includes it in the
 *      Doctrine `findOneBy` criteria for Acc.
 *
 *   2. const UNIQUE_PARAMETERS = ['accCode' => 'code'] (legacy, single-field only)
 *
 * Both are supported during the migration window so apps can flip entities
 * one PR at a time. Once everything is on RouteIdentity, the legacy branch
 * can be deleted.
 */
class ParameterResolver implements ValueResolverInterface
{
    public function __construct(
        // core-bundle may be installed in projects with no entity manager
        private ?EntityManagerInterface $entityManager = null,
    ) {}

    /**
     * @return array<mixed>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        if (!$this->entityManager) {
            return [];
        }

        $type = $argument->getType();
        if (!\is_string($type) || !is_subclass_of($type, RouteParametersInterface::class)) {
            return [];
        }

        $entity = $this->lookup($type, $request);
        return $entity === null ? [] : [$entity];
    }

    /**
     * Returns the resolved entity, or null when criteria can't be determined
     * from the request (caller falls through to other resolvers / typehint).
     * Throws when criteria ARE determined but no row matches — that's a real
     * data inconsistency, not a "try again" case.
     */
    private function lookup(string $entityClass, Request $request): ?object
    {
        $identity = class_exists(\Survos\FieldBundle\Service\RouteIdentityResolver::class)
            ? \Survos\FieldBundle\Service\RouteIdentityResolver::lookup($entityClass)
            : null;

        if ($identity !== null) {
            $criteria = $this->criteriaFromRouteIdentity($entityClass, $identity, $request);
        } else {
            $criteria = $this->criteriaFromLegacyConst($entityClass, $request);
        }

        if ($criteria === null) {
            return null;
        }

        return $this->find($entityClass, $criteria);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function criteriaFromRouteIdentity(string $entityClass, RouteIdentity $identity, Request $request): ?array
    {
        $key   = $identity->key ?? lcfirst((new \ReflectionClass($entityClass))->getShortName()) . 'Id';
        $value = $request->attributes->get($key);
        if ($value === null || \is_object($value)) {
            return null;
        }

        $criteria = [$identity->field => $value];

        foreach ($identity->parents as $parentProp) {
            $parentClass = $this->parentEntityClass($entityClass, $parentProp);
            if ($parentClass === null) {
                continue;
            }
            $parent = $this->lookup($parentClass, $request);
            if ($parent !== null) {
                $criteria[$parentProp] = $parent;
            }
        }

        return $criteria;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function criteriaFromLegacyConst(string $entityClass, Request $request): ?array
    {
        $const = $entityClass . '::UNIQUE_PARAMETERS';
        if (!defined($const)) {
            return null;
        }

        $criteria = [];
        foreach (constant($const) as $param => $getter) {
            if (class_exists($getter)) {
                continue; // unsupported legacy shape (parent-by-class), out of scope
            }
            $value = $request->attributes->get($param);
            if ($value !== null && !\is_object($value)) {
                $criteria[$getter] = $value;
            }
        }

        return $criteria === [] ? null : $criteria;
    }

    private function parentEntityClass(string $entityClass, string $prop): ?string
    {
        try {
            $meta = $this->entityManager->getClassMetadata($entityClass);
        } catch (\Throwable) {
            return null;
        }
        return $meta->hasAssociation($prop) ? $meta->getAssociationTargetClass($prop) : null;
    }

    /**
     * @param  array<string, mixed> $criteria
     */
    private function find(string $entityClass, array $criteria): object
    {
        $entity = $this->entityManager->getRepository($entityClass)->findOneBy($criteria);
        if ($entity === null) {
            throw new \RuntimeException(sprintf(
                'Could not resolve %s from %s.',
                $entityClass,
                json_encode($criteria, \JSON_UNESCAPED_SLASHES),
            ));
        }
        return $entity;
    }
}
