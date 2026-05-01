<?php

namespace Survos\CoreBundle\Twig;

use Survos\CoreBundle\Service\SurvosUtils;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\TwigTest;

class TwigExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            // If your filter generates SAFE HTML, add ['is_safe' => ['html']]
            // Reference: https://twig.symfony.com/doc/3.x/advanced.html#automatic-escaping
            new TwigFilter('get_class', fn(object $obj) => $obj::class),
            new TwigFilter('format_large_number', fn(int $x) => SurvosUtils::formatLargeNumber($x)),
            new TwigFilter('json_pretty', $this->json_pretty(...), ['is_safe' => ['html']]),

            new TwigFilter('short_class', $this->short_class(...)),
        ];
    }

    public function short_class(string|object $obj): string
    {
        try {
            $name = (new \ReflectionClass($obj))->getShortName();

            } catch (\Exception $exception) {
            $name = is_object($obj) ? get_class($obj) : $obj;
        }
        return $name;
    }

    public function getFunctions(): array
    {
        return [
//            new TwigFunction('function_name', [::class, 'doSomething']),
        ];
    }

    public function getTests(): array
    {
        return [
            new TwigTest('instanceof', fn(mixed $var, string $class) => $var instanceof $class),
        ];
    }

    public function json_pretty(string|object|array|null $value, ?string $wrapper = 'pre', array $wrapperAttributes = []): string
    {
        if (is_string($value) && json_validate($value)) {
            $data = json_decode($value);
        } else {
            $data = $value;
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($wrapper) {
            // @todo: attributes
            $json = sprintf("<%s>%s</%s>", $wrapper, $json, $wrapper);
        }
        return $json;
    }

}
