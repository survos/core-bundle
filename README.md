# Corebundle

Symfony Bundle with interfaces, traits, models and services needed by more than one Survos component. For example, Model\Column is used by grid, api-grid and simple-datatables.

```bash
composer req survos/core-bundle
```

## Route identity (deprecated here — use field-bundle)

`RouteParametersInterface` / `RouteParametersTrait` (the `UNIQUE_PARAMETERS`
const pattern) are deprecated and will be removed in the next major release.
Declare route identity with field-bundle instead:

```php
<?php
// src/Entity/Foo.php
namespace App\Entity;

use Survos\FieldBundle\Attribute\RouteIdentity;
use Survos\FieldBundle\Entity\RouteIdentityTrait;
use Survos\FieldBundle\Entity\RouteParametersInterface;

#[RouteIdentity(field: 'code')]
class Foo implements RouteParametersInterface
{
    use RouteIdentityTrait;
}
```

`foo.rp` in twig and `$foo->getRp()` in php still work exactly as before, and
controllers can type-hint `Foo` directly — field-bundle's
`RouteIdentityValueResolver` looks the entity up from the `fooId` route
parameter. See `showcase/CONVENTIONS.md` ("Entity injection") for the full
convention.

## Helper Tasks

echo "SYMFONY_DEPRECATIONS_HELPER=weak" >> .env

## Shared Utilities

`Survos\CoreBundle\Service\SurvosUtils` is the home for small, shared helpers
that are useful across multiple Survos bundles and applications. Before adding
local string/path/entity helper methods in another bundle, check here first.

Common static helpers:

| Helper | Purpose |
| --- | --- |
| `SurvosUtils::slugify($code, separator: '_')` | Stable ASCII slugs for route codes, index names, file-safe identifiers, and bundle metadata. |
| `SurvosUtils::formatLargeNumber($number)` | Compact counts for badges and summaries, e.g. `1.2k` or `3.4m`. |
| `SurvosUtils::humanFilesize($size)` | Human readable byte sizes. |
| `SurvosUtils::parseQueryString($data)` | Query-string parsing that preserves parameter names Symfony/PHP would otherwise normalize. |
| `SurvosUtils::actualClass($objectOrClass)` | Resolve Doctrine proxy classes back to their real class name. |
| `SurvosUtils::createDir($dir)` | Create a directory if missing and return its real path with a trailing slash. |

Entity codes (`app_intake` from `App\Entity\Intake`) now come from
`Survos\FieldBundle\Compiler\EntityMetaPass::entityCode()`; assertions with
"missing key/value" diagnostics live in `Survos\DebugUtils\Assert`.

Instance helpers are available when the service is injected:

| Helper | Purpose |
| --- | --- |
| `$utils->cleanPath($filename)` | Shorten paths relative to `kernel.project_dir` for logs/debug output. |
| `$utils->flatten($messages)` | Flatten nested message/config arrays into dot paths. |
| `$utils->populateObjectFromData($object, $data)` | Populate an object using Symfony PropertyAccess. |

Guideline for agents and bundle authors: if a helper needs to be shared by
`api-grid-bundle`, `tabler-bundle`, `field-bundle`, maker code, or app code, put
it in core-bundle instead of recreating it locally.
