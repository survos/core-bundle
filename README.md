# Corebundle

Symfony Bundle with interfaces, traits, models and services needed by more than one Survos components.  For example, Model\Column is used by grid, api-grid and simple-datatables.  RouteParametersInterface is used by tree and the griid bundles.


https://github.com/symfony/symfony/issues/20083


```bash
composer req survos/core-bundle
```

```php
<?php
// src/Entity/Foo.php
namespace App\Entity;

use Survos\CoreBundle\Entity\RouteParametersInterface;
use Survos\CoreBundle\Entity\RouteParametersTrait;

class Foo implements RouteParametersInterface
{
use RouteParametersTrait;

public function getUniqueParams(): array { 
    return ['fooId' => $this->getFooCode()];
}
```

Now use .rp in twig and ->getRp() in php as part of generating a route
```twig
<a href="{{ path('foo_show', foo.rp) }}">Show</a>
```

Combined with survos/maker-bundle, create a param converter

```bash
bin/console survos:make:param-converter Foo
```

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
| `SurvosUtils::entityCode($class)` | Stable admin/browser code for entity classes. `App\Entity\Intake` becomes `app_intake`; `Survos\OutreachBundle\Entity\Contact` becomes `outreach_contact`. Used by api-grid and Tabler admin menus. |
| `SurvosUtils::formatLargeNumber($number)` | Compact counts for badges and summaries, e.g. `1.2k` or `3.4m`. |
| `SurvosUtils::humanFilesize($size)` | Human readable byte sizes. |
| `SurvosUtils::parseQueryString($data)` | Query-string parsing that preserves parameter names Symfony/PHP would otherwise normalize. |
| `SurvosUtils::actualClass($objectOrClass)` | Resolve Doctrine proxy classes back to their real class name. |
| `SurvosUtils::createDir($dir)` | Create a directory if missing and return its real path with a trailing slash. |
| `SurvosUtils::assertKeyExists()` / `assertInArray()` | Assertions with useful “missing key/value” diagnostics. |

Example:

```php
use App\Entity\Intake;
use Survos\CoreBundle\Service\SurvosUtils;

$code = SurvosUtils::entityCode(Intake::class); // app_intake
$route = $code . '_show';                       // app_intake_show
```

Instance helpers are available when the service is injected:

| Helper | Purpose |
| --- | --- |
| `$utils->cleanPath($filename)` | Shorten paths relative to `kernel.project_dir` for logs/debug output. |
| `$utils->flatten($messages)` | Flatten nested message/config arrays into dot paths. |
| `$utils->populateObjectFromData($object, $data)` | Populate an object using Symfony PropertyAccess. |

Guideline for agents and bundle authors: if a helper needs to be shared by
`api-grid-bundle`, `tabler-bundle`, `field-bundle`, maker code, or app code, put
it in core-bundle instead of recreating it locally.
