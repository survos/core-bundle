<?php

declare(strict_types=1);

namespace Survos\CoreBundle\Entity;

/**
 * @deprecated implement RouteParametersInterface instead
 */
abstract class SurvosCoreEntity implements RouteParametersInterface
{
    use RouteParametersTrait;
}
