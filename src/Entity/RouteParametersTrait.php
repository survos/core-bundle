<?php

namespace Survos\CoreBundle\Entity;

use Symfony\Component\Serializer\Attribute\Groups;

use function Symfony\Component\String\u;

/**
 * @deprecated Use #[Survos\FieldBundle\Attribute\RouteIdentity] +
 *             Survos\FieldBundle\Entity\RouteIdentityTrait instead. The new
 *             pair is attribute-driven, walks the parent chain automatically,
 *             and is the same `getRp()` contract — drop-in replacement.
 *
 *             Existing entities using this trait keep working; migrate at
 *             your own pace, one entity per PR.
 */
trait RouteParametersTrait
{
//    public function getId(): int|string
//    {
//        throw new \Exception("you must implement getId() to use a default unique parameters in " . $this::class);
//    }

//    private const array UNIQUE_PARAMETERS = ['id' => 'id'];


    public function getUniqueIdentifiers(): array
    {
        if (defined('static::UNIQUE_PARAMETERS')) {
            $x = [];
            foreach (constant('static::UNIQUE_PARAMETERS') as $parameter => $getter) {
                if (class_exists($getter)) {
                    dd($parameter, $getter);
                }
                if (method_exists($this, $methodGetter = 'get' . $getter)) {
                    $x[$parameter] = $this->{'get' . $getter}();
                } else {
                    $x[$parameter] = $this->$getter;
                }
            }
            return $x;
        }

        if (!method_exists($this, 'getId')) {
            throw new \Exception("you must implement getId() to use a default unique parameters in " . $this::class);
        }
        $uniqueIds =  [strtolower((new \ReflectionClass($this))->getShortName()) . 'Id' => $this->getId()];
        return $uniqueIds;
    }

    #[Groups(['rp', 'transitions', 'searchable'])]
    public function getRp(?array $addlParams = []): array
    {
        return array_merge($this->getUniqueIdentifiers(), $addlParams);
    }

    /** e.g. MemberDirectory to member_directory */
    public static function getClassnamePrefix(string|null $class = null): string
    {
        if (! $class) {
            $class = get_called_class();
        }
        $shortName = strtolower(u($x = (new \ReflectionClass($class))->getShortName())->snake()->lower()->ascii());

        //        $shortName = strtolower( (new \ReflectionClass(get_called_class()))->getShortName() );
        return $shortName;
    }

    public function erp(): array
    {
        // hack, the first value
        $rp = $this->getUniqueIdentifiers();
        $pk = array_key_first($rp);
        return ['entityId' => $rp[$pk]];

    }
}
