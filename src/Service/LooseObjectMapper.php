<?php
declare(strict_types=1);

namespace Survos\CoreBundle\Service;

use DateTimeImmutable;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractorInterface;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\String\UnicodeString;

/**
 * Best-effort array/stdClass → object mapper.
 *
 * KEY IDEAS
 * - We translate incoming keys using CamelCaseToSnakeCaseNameConverter *in reverse*,
 *   then set via PropertyAccessor. This fixes snake_case → camelCase for multi-word props.
 * - We consult PropertyInfo to decide when to coerce strings ("a|b|c") → array.
 * - Unknown fields are skipped; PKs (or any ignored) are never written.
 */
final class LooseObjectMapper
{
    private PropertyAccessorInterface $pa;
    private PropertyInfoExtractorInterface $pi;
    private CamelCaseToSnakeCaseNameConverter $nc;

    public function __construct(
        ?PropertyAccessorInterface $pa = null,
        ?PropertyInfoExtractorInterface $pi = null,
        ?CamelCaseToSnakeCaseNameConverter $nc = null
    ) {
        $this->pa = $pa ?? PropertyAccess::createPropertyAccessorBuilder()
            ->enableExceptionOnInvalidIndex()
            ->getPropertyAccessor();

        $this->pi = $pi ?? new PropertyInfoExtractor(
            typeExtractors: [new ReflectionExtractor()],
        );

        $this->nc = $nc ?? new CamelCaseToSnakeCaseNameConverter();
    }

    /**
     * Map payload into a NEW instance of $class.
     *
     * @param array|object $data
     * @param class-string $class
     */
    public function map(array|object $data, string $class, array $context = []): object
    {
        $object = new $class();
        return $this->mapInto($data, $object, ignored: $context['ignored'] ?? ['id'], context: $context);
    }

    /**
     * Populate an EXISTING object. Primary key and any $ignored fields are untouched.
     *
     * Context options:
     * - list_delimiters: ['cast' => '|', 'genres' => '|']  // per-field preferred delimiters
     * - coerce_scalars:  true|false (default true)
     * - coerce_dates:    true|false (default true)
     */
    public function mapInto(array|object $data, object $object, array $ignored = ['id'], array $context = []): object
    {
        $arr = is_object($data) ? (array) $data : $data;
        $arr = $this->normalizeKeysToSnake($arr);

        $coerceScalars = $context['coerce_scalars'] ?? true;
        $coerceDates   = $context['coerce_dates']   ?? true;
        $perDelim      = $context['list_delimiters'] ?? [];

        foreach ($arr as $snakeKey => $value) {
            // Convert incoming key to the object's property name.
            // NameConverter::denormalize transforms snake_case → camelCase.
            $prop = $this->nc->denormalize((string) $snakeKey);

            if (in_array($prop, $ignored, true)) {
                continue;
            }

            // If the property isn't writable, skip.
            if (!$this->pa->isWritable($object, $prop)) {
                continue;
            }

            // Inspect destination type for smart coercions
            $types = $this->pi->getTypes($object::class, $prop) ?? [];
            $type  = $types[0] ?? null;

            if ($type instanceof Type) {
                $value = $this->coerceValueForType(
                    value: $value,
                    type: $type,
                    field: $prop,
                    perFieldDelimiter: $perDelim,
                    coerceScalars: $coerceScalars,
                    coerceDates: $coerceDates
                );
            }

            try {
                $this->pa->setValue($object, $prop, $value);
            } catch (\Throwable) {
                // best-effort: skip unassignable fields
            }
        }

        return $object;
    }

    /**
     * Coerce $value to fit $type (arrays from delimited strings, ints/floats/bools, common date formats).
     */
    private function coerceValueForType(
        mixed $value,
        Type $type,
        string $field,
        array $perFieldDelimiter,
        bool $coerceScalars,
        bool $coerceDates
    ): mixed {
        // Handle null / empty strings uniformly
        if ($value === '' || $value === null) {
            return null;
        }

        $builtin = $type->getBuiltinType();

        // If destination expects an array and we have a string, split it.
        if ($builtin === Type::BUILTIN_TYPE_ARRAY && is_string($value)) {
            $delim = $perFieldDelimiter[$field]
                ?? (str_contains($value, '|') ? '|' : (str_contains($value, ',') ? ',' : '|'));

            $parts = array_map('trim', explode($delim, $value));
            $parts = array_values(array_filter($parts, static fn($s) => $s !== ''));
            return $parts;
        }

        // Scalars: attempt gentle coercion if requested
        if ($coerceScalars && is_string($value)) {
            $v = trim($value);

            switch ($builtin) {
                case Type::BUILTIN_TYPE_INT:
                    if (preg_match('/^-?\d+$/', $v)) {
                        // avoid converting zero-padded codes unexpectedly: only coerce if it looks numeric AND
                        // the field name suggests numeric or there are no leading zeros
                        $hasLeadingZero = strlen($v) > 1 && $v[0] === '0';
                        if (!$hasLeadingZero || $this->looksNumericField($field)) {
                            return (int) $v;
                        }
                    }
                    break;

                case Type::BUILTIN_TYPE_FLOAT:
                    if (is_numeric($v)) {
                        return (float) $v;
                    }
                    break;

                case Type::BUILTIN_TYPE_BOOL:
                    $low = strtolower($v);
                    if (in_array($low, ['true','false','yes','no','y','n','on','off','1','0'], true)) {
                        return in_array($low, ['true','yes','y','on','1'], true);
                    }
                    break;

                case Type::BUILTIN_TYPE_OBJECT:
                    // Dates: ISO or common US m/d/yy[yy]
                    if ($coerceDates) {
                        // ISO 8601
                        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:\d{2})$/', $v)) {
                            try { return new DateTimeImmutable($v); } catch (\Throwable) {}
                        }
                        // m/d/yy or m/d/yyyy
                        if (preg_match('#^\d{1,2}/\d{1,2}/\d{2,4}$#', $v)) {
                            // Normalize 2-digit year to 20xx heuristic (adjust if you need 19xx)
                            $parts = explode('/', $v);
                            [$m,$d,$y] = array_map('intval', $parts);
                            if ($y < 100) { $y += ($y >= 70 ? 1900 : 2000); }
                            $iso = sprintf('%04d-%02d-%02dT00:00:00Z', $y, $m, $d);
                            try { return new DateTimeImmutable($iso); } catch (\Throwable) {}
                        }
                    }
                    break;
            }
        }

        return $value;
    }

    private function looksNumericField(string $field): bool
    {
        static $hints = [
            'id','count','index','position','rank','duration','size',
            'budget','revenue','popularity','score','rating','price','quantity',
            'voteCount','voteAverage','runtime','year',
        ];
        return in_array($field, $hints, true);
    }

    /**
     * Normalize incoming keys so NameConverter can reliably denormalize them:
     *  - "CREATEDAT" → "createdat"
     *  - "createdAt" → "created_at"
     *  - "created_at" stays "created_at"
     */
    private function normalizeKeysToSnake(array $input): array
    {
        $out = [];
        foreach ($input as $k => $v) {
            $key = (new UnicodeString((string) $k))->snake()->toString();
            $out[$key] = is_array($v) ? $this->normalizeKeysToSnake($v) : $v;
        }
        return $out;
    }

    // add inside the class

    /** Convert field name to snake_case (id => id, accessionNumber => accession_number). */
    public function toSnake(string $name): string
    {
        return (new UnicodeString($name))->snake()->toString();
    }

    /** Convert field name (snake/kebab) to lowerCamelCase (accession_number => accessionNumber). */
    public function toCamel(string $name): string
    {
        $u = new UnicodeString(str_replace('-', '_', $name));
        $parts = array_map(
            fn ($p) => (new UnicodeString($p))->title()->toString(),
            array_filter(explode('_', (string) $u))
        );
        return lcfirst(implode('', $parts));
    }

    /**
     * Generate likely CSV/array key candidates for a given entity field.
     * Order matters: exact, snake, camel, then lowercase fallbacks.
     */
    public function keyCandidates(string $field): array
    {
        $snake = $this->toSnake($field);
        $camel = $this->toCamel($field);

        $cands = [
            $field,
            $snake,
            $camel,
            strtolower($field),
            strtolower($snake),
            strtolower($camel),
        ];

        // de-dup while preserving order
        $seen = [];
        $out = [];
        foreach ($cands as $c) {
            if (!isset($seen[$c])) {
                $seen[$c] = true;
                $out[] = $c;
            }
        }
        return $out;
    }

    /**
     * Find the actual row key for an entity field, tolerating snake/camel and case.
     * Returns the original key from $row, or null if not found.
     */
    public function resolveRowKey(array $row, string $field): ?string
    {
        // fast path: exact and snake/camel variants
        foreach ($this->keyCandidates($field) as $cand) {
            if (array_key_exists($cand, $row)) {
                return $cand;
            }
        }

        // fallback case-insensitive scan once
        $lowerMap = array_change_key_case($row, CASE_LOWER);
        foreach ($this->keyCandidates($field) as $cand) {
            $lc = strtolower($cand);
            if (array_key_exists($lc, $lowerMap)) {
                // recover original key (preserve original casing)
                foreach ($row as $orig => $_) {
                    if (strtolower($orig) === $lc) {
                        return $orig;
                    }
                }
            }
        }

        return null;
    }

}
