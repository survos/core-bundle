<?php
declare(strict_types=1);

namespace Survos\CoreBundle\Util;

/**
 * Append JSON-encoded rows (arrays/objects) to a JSONL file (optionally gzipped).
 * Also maintains a sidecar index (.idx.json) with unique keys for fast lookup.
 */
final class JsonlWriter
{
    private $fh;
    private bool $gzip;
    private string $filename;
    private string $indexFile;
    private array $index = [];

    private function __construct(string $filename)
    {
        $this->filename = $filename;
        $this->gzip     = str_ends_with($filename, '.gz');
        $this->indexFile = $filename . '.idx.json';

        // load existing index if present
        if (is_file($this->indexFile)) {
            $this->index = json_decode((string)file_get_contents($this->indexFile), true) ?? [];
        }

        if ($this->gzip) {
            if (!function_exists('gzopen')) {
                throw new \RuntimeException('zlib not available: cannot write gzip file ' . $filename);
            }
            $this->fh = gzopen($filename, 'ab9');
        } else {
            $this->fh = fopen($filename, 'ab');
        }
        if (!$this->fh) {
            throw new \RuntimeException("Cannot open $filename for appending");
        }
    }

    public static function open(string $filename): self
    {
        return new self($filename);
    }

    public function write(array $row, ?string $tokenCode = null): void
    {
        if ($tokenCode) {
            if (isset($this->index[$tokenCode])) {
                // already written, skip
                return;
            }
            $this->index[$tokenCode] = true;
        }

        $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        if ($this->gzip) {
            gzwrite($this->fh, $line);
        } else {
            fwrite($this->fh, $line);
        }
    }

    public function close(): void
    {
        try {
            // persist index
            file_put_contents($this->indexFile, json_encode($this->index, JSON_PRETTY_PRINT));
        } finally {
            if ($this->gzip) {
                gzclose($this->fh);
            } else {
                fclose($this->fh);
            }
        }
    }

    public function __destruct()
    {
        if (is_resource($this->fh)) {
            $this->close();
        }
    }
}
