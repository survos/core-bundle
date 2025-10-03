<?php
declare(strict_types=1);

namespace Survos\CoreBundle\Util;

final class JsonlReader implements \IteratorAggregate
{
    public function __construct(private string $filename) {}

    public function getIterator(): \Traversable
    {
        $gzip = str_ends_with($this->filename, '.gz');

        if ($gzip) {
            if (!function_exists('gzopen')) {
                throw new \RuntimeException('zlib not available: cannot read gzip file ' . $this->filename);
            }
            $fh = gzopen($this->filename, 'rb');
            if (!$fh) {
                throw new \RuntimeException('Cannot open: ' . $this->filename);
            }
            try {
                $lineNo = 0;
                while (!gzeof($fh)) {
                    $line = gzgets($fh);
                    if ($line === false) { break; }
                    $line = trim($line);
                    if ($line === '') continue;
                    $lineNo++;
                    yield $lineNo => json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                }
            } finally {
                gzclose($fh);
            }
            return;
        }

        $fh = fopen($this->filename, 'rb');
        if (!$fh) {
            throw new \RuntimeException('Cannot open: ' . $this->filename);
        }
        try {
            $lineNo = 0;
            while (!feof($fh)) {
                $line = fgets($fh);
                if ($line === false) { break; }
                $line = trim($line);
                if ($line === '') continue;
                $lineNo++;
                yield $lineNo => json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        } finally {
            fclose($fh);
        }
    }

    public function containsToken(string $tokenCode): bool
    {
        $idxFile = $this->filename . '.idx.json';
        if (is_file($idxFile)) {
            $idx = json_decode((string)file_get_contents($idxFile), true) ?? [];
            return isset($idx[$tokenCode]);
        }

        // fallback: linear scan
        foreach ($this as $row) {
            if (($row['_tokenCode'] ?? null) === $tokenCode) {
                return true;
            }
        }
        return false;
    }
}
