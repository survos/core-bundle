<?php

namespace Survos\CoreBundle\Service;

use Doctrine\Common\Util\ClassUtils;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\String\Slugger\SluggerInterface;

use function Symfony\Component\String\u;
use \SplFileObject as SplFileObject;

class SurvosUtils
{
    public function __construct(
        private ParameterBagInterface $bag,
        private ?PropertyAccessorInterface $accessor = null,
        private ?SluggerInterface $asciiSlugger = null,
    ) {
    }

    public static function formatLargeNumber(int|float $number, int $precision = 1): string
    {
        if ($number >= 1_000_000) {
            return round($number / 1_000_000, $precision) . 'm';
        }

        if ($number >= 1_000) {
            return round($number / 1_000, $precision) . 'k';
        }

        return (string) $number;
    }
    /**
     *
     * Remove projectDir from path, for easier reading.
     *
     * @param $filename
     * @return array|bool|float|int|mixed|string|string[]|\UnitEnum|null
     */
    public function cleanPath($filename)
    {
        $projectDir = $this->bag->get('kernel.project_dir');
        if (str_contains($filename, $projectDir)) {
            $filename = str_replace($projectDir, '', $filename);
        };
        $projectParent = pathinfo($projectDir, PATHINFO_DIRNAME);
        if (str_contains($filename, $projectParent)) {
            $filename = '..' . str_replace($projectParent, '', $filename);
        }
        return $filename;
    }

    // see https://www.php.net/manual/en/class.recursiveiteratoriterator.php
    // and https://stackoverflow.com/questions/12077177/how-does-recursiveiteratoriterator-work-in-php
    // and https://github.com/tacman/PhpMetrics/commit/7bebaba683dad4710b720f4f63ed52c971cc06cb afor an example
    public function flatten(array &$messages, array|null $subnode = null, string|null $path = null)
    {
        if (null === $subnode) {
            $subnode = &$messages;
        }
        foreach ($subnode as $key => $value) {
            if (is_array($value)) {
                $pathKey = $key;
                if (is_numeric($key)) {
                    self::assertKeyExists('code', $value);
                    $pathKey = $value['code'];
//                    unset($messages[$key]);
//                    dd($key, $value);
                }
                if (array_is_list($value)) {
//                    dd($value, $key, $subnode);
//                    $pathKey = $value[$key]['code'];
                }
                $nodePath = $path ? $path . '.' . $pathKey : $key;
                $this->flatten($messages, $value, $nodePath);
                if (is_numeric($key)) {
                    // remove code, not to be translated
//                    dd($messages, $path, $key, $pathKey, $subnode);
//                    unset($messages[$key]);
                }

                if (null === $path) {
                    unset($messages[$key]);
                }
            } elseif (null !== $path) {
                if (!in_array($key, ['code', 'id', 'icon'])) {
                    $messages[$path . '.' . $key] = $value;
                }
            }
        }
    }

    public function populateObjectFromData(mixed $object, array $data, bool $throwErrorIfMissingProperty = true)
    {
        foreach ($data as $var => $value) {
                $this->accessor->setValue($object, $var, $value);
//            try {
//            } catch (\Exception $exception) {
//                if ($throwErrorIfMissingProperty) {
//                    assert(false, "Invalid property: $var");
//                }
//            }
        }
        return $object;
    }

    public static function slugify(string $code, int $maxLength = 64, bool $forceLower = true, string $separator = '_'): string
    {
//        $code = str_replace(':', '', $code);
//        $slug = $this->asciiSlugger->slug($code, separator: $separator)->slice(0, $maxLength);
//        if ($forceLower) {
//            $slug->lower();
//        }
//        // because meili can't have periods
////        $slug = u($slug)->replace('.','-');
//        return strtolower($slug->toString());

        $code = str_replace(':','',$code);
        $slug = (new AsciiSlugger())->slug($code, separator: $separator)->slice(0, $maxLength);
        if ($forceLower) {
            $slug->lower();
        }
        // because meili can't have periods
//        $slug = u($slug)->replace('.','-');
        return strtolower($slug->toString());

    }


    public static function humanFilesize($size, $precision = 2): string
    {
        $units = array('B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
        $step = 1024;
        $i = 0;
        while (($size / $step) > 0.9) {
            $size = $size / $step;
            $i++;
        }
        return round($size, $precision) . $units[$i];
    }

    static function createProgressBar(OutputInterface $io, ?int $count=null): ProgressBar
    {
        $progressBar = new ProgressBar($io, $count);
        $progressBar->setFormat(
            "<fg=white;bg=cyan> %status:-45s%</>\n%current%/%max% [%bar%] %percent:3s%%\n🏁  %estimated:-21s% %memory:21s%"
        );
        return $progressBar;
    }


    public static function parseQueryString($data): array
    {
        $data = preg_replace_callback('/(?:^|(?<=&))[^=[]+/', function ($match) {
            return bin2hex(urldecode($match[0]));
        }, $data);

        parse_str((string)$data, $values);

        return array_combine(array_map('hex2bin', array_keys($values)), $values);
    }

    public static function ArrayMapKv(callable $callback, array $keyedData)
    {
        return array_map($callback, array_keys($keyedData), $keyedData);
    }

    public static function createDir($dir): string
    {
        if (!file_exists($dir)) {
            mkdir($dir, recursive: true);
        }
        return realpath($dir) . '/';
    }


    public static function actualClass(string|object $classOrEntity)
    {
        return ClassUtils::getRealClass(is_string($classOrEntity) ? $classOrEntity : $classOrEntity::class);
    }


    public static function trimmer(string $label): string
    {
        return trim($label, " \n\r\t\v\x00.;,/");
    }

    public static function missingKey($key, $array): string
    {
        $keys = array_keys($array);
        return self::missingElement($key, $keys);
    }

    public static function missingElement($key, $keys): string
    {
        sort($keys, SORT_STRING);
        return sprintf("Missing [%s]:\n%s", $key, join("\n", $keys));
    }


    public static function assertKeyExists($key, array|object $array, string $message = '')
    {
        if (is_object($array)) {
            $array = (array)$array;
        }
        assert(array_key_exists($key, $array), self::missingKey($key, $array) . "\n$message");
    }

    public static function assertInArray($key, array $array, string $message = '')
    {
        assert(in_array($key, $array), self::missingElement($key, $array) . "\n$message");
    }


    public function validate(null|iterable|object $obj, $msg = '')
    {
        return; // hack, problem with DictionaryValidator!
//        if (!$obj) {
//            return;
//        }
//        if (is_iterable($obj)) {
//            foreach ($obj as $item) {
//                $this->validate($item);
//            }
//        } else {
//            $errors = $this->validator->validate($obj);
//            if ($errors->count()) {
//                foreach ($errors as $error) {
////                    dd( $msg . "\n" . (string) $error);
//                    assert(false, (string)$msg . "\n" . (string)$error);
//                }
//                assert(!$errors->count(), (string)$msg . "\n" . (string)$errors);
//            }
//        }
    }

    public static function shortClass(string|object $class): string
    {
        return (new \ReflectionClass($class))->getShortName();
    }

    public static function dd($values)
    {
        dd($values);
    }

    public static function createAcronym(string $string, $onlyCapitals = false): ?string
    {
        $output = null;
        $token = strtok($string, ' ');
        while ($token !== false) {
            $character = mb_substr($token, 0, 1);
            if ($onlyCapitals and mb_strtoupper($character) !== $character) {
                $token = strtok(' ');
                continue;
            }
            $output .= $character;
            $token = strtok(' ');
        }
        return $output;
    }

    // https://stackoverflow.com/questions/4352203/any-php-function-that-will-strip-properties-of-an-object-that-are-null
    public static function cleanNullsOfObject(&$object): void {
        foreach ($object as $property => &$value) {
            if (is_object($value)) {
                self::cleanNullsOfObject($value);
                if (empty(get_object_vars($value))) {
                    unset($object->$property);
                }
            }
            // check for array of objects
            if (is_array($value) && array_is_list($value)) {
                foreach ($value as $val) {
                    if (is_object($val)) {
                        self::cleanNullsOfObject($val);
                    }
                }

            }
//            if (is_array($value) && is_object($value[0])) {
//                foreach ($value as $val) {
//                    self::cleanNullsOfObject($val);
//                }
//            }
            if (is_null($value) || ( (is_string($value) && $value === '') || is_array($value) && empty($value))) {
                unset($object->$property);
            }
        }
    }

    /**
     * Recursively remove all nulls and empty arrays from an object or array.
     *
     * @param mixed $data  An object (stdClass) or array (or scalar)
     * @return mixed       The cleaned object/array, or the original scalar
     */
    static function removeNullsAndEmptyArrays($data): object|array
    {
        $isObject = is_object($data);
        if ($isObject) {
            $data = (array) $data;
        }

        if (is_array($data)) {
            $clean = [];

            foreach ($data as $key => $value) {
                if (is_array($value) || is_object($value)) {
                    $value = self::removeNullsAndEmptyArrays($value);
                }

                if ($value === null) continue;
                if (is_string($value) && $value === '') continue;
                if (is_array($value) && $value === []) continue;
                if (is_object($value) && (array) $value === []) continue;

                $clean[$key] = $value;
            }

            return $isObject ? (object) $clean : $clean;
        }

        return $data;
    }



    public static function getConfigDirectory(string $appName = ''): string
    {
        $baseDir = match (strtolower(PHP_OS_FAMILY)) {
            'darwin' => getenv('HOME') . '/Library/Application Support',
            'windows' => getenv('APPDATA'),
            default => getenv('XDG_CONFIG_HOME') ?: getenv('HOME') . '/.config'
        };

        return $appName ? "$baseDir/$appName" : $baseDir;
    }

    static public function readNdjsonLd(string $path): \Generator {
        // hack, small files only
        $f = file($path);
//        $f = new SplFileObject($path, 'r');
//        $f->setFlags(SplFileObject::DROP_NEW_LINE | SplFileObject::SKIP_EMPTY);
        foreach ($f as $line) {
            if ($line === null || $line === '' || ltrim($line)[0] === '#') {
                continue; // skip blanks/comments
            }
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            try {
                yield $row;
            } catch (\JsonException $e) {
                // handle or log and continue
                error_log("Bad JSON line: " . $e->getMessage());
            }
        }
    }


    /**
     * Normalize a language tag to 2-letter ISO-639-1 when possible.
     * Returns null if it can't be normalized safely.
     */
    public static function normalizeIso639(?string $raw): ?string
    {
        if ($raw === null) return null;
        $s = strtolower(trim($raw));
        if ($s === '' || $s === '\\n' || $s === '\\N') return null;

        // common 3-letter → 2-letter, and OST oddities
        static $map = [
            'eng' => 'en', 'en-us' => 'en', 'en-gb' => 'en',
            'spa' => 'es', 'sp' => 'es', 'esl' => 'es',
            'por' => 'pt', 'pb' => 'pt', 'pob' => 'pt', 'ptbr' => 'pt', 'pt-br' => 'pt',
            'fre' => 'fr', 'fra' => 'fr',
            'ger' => 'de', 'deu' => 'de',
            'dut' => 'nl', 'nld' => 'nl',
            'rum' => 'ro', 'ron' => 'ro',
            'alb' => 'sq',
            'slo' => 'sk', 'slk' => 'sk',
            'baq' => 'eu', 'eus' => 'eu',
            'chi' => 'zh', 'zho' => 'zh', 'chs' => 'zh', 'cht' => 'zh',
        ];
        if (isset($map[$s])) {
            $s = $map[$s];
        }

        // Accept 2-letter a–z only
        if (preg_match('/^[a-z]{2}$/', $s)) {
            return $s;
        }

        // Last-resort: if it's a longer token like "english", try first two letters
        if (preg_match('/^[a-z]{3,}$/', $s)) {
            $guess = substr($s, 0, 2);
            if (preg_match('/^[a-z]{2}$/', $guess)) {
                return $guess;
            }
        }
        dd($raw);

        return null;
    }
    public static function getSymfonyProxySites(): array
    {
        $html = file_get_contents('http://127.0.0.1:7080');

        preg_match_all(
            '#<tr><td>([^<]+)<td>(?:<a[^>]+>(\d+)</a>|[^<]+)<td>(.*?)<(?:tr|/tr)#s',
            $html,
            $matches,
            PREG_SET_ORDER
        );

        $sites = [];
        foreach ($matches as $match) {
            $directory = trim($match[1]);
            $port = !empty($match[2]) ? (int)$match[2] : null;
            $domainCell = $match[3];

            // Extract all domains from the cell (match href or plain text)
            preg_match_all('#https://[^<>"]+/#', $domainCell, $domainMatches);
            $domains = array_unique($domainMatches[0]);

            // Extract code from first non-wildcard domain
            $code = null;
            foreach ($domains as $domain) {
                if (!str_contains($domain, '*')) {
                    if (preg_match('#https://([^./]+)\.wip/#', $domain, $codeMatch)) {
                        $code = $codeMatch[1];
                        break;
                    }
                }
            }

            $sites[] = [
                'directory' => $directory,
                'port' => $port,
                'code' => $code,
                'domains' => array_values($domains),
            ];
        }

        return $sites;
    }
}
