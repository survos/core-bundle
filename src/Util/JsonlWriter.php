<?php
declare(strict_types=1);

namespace Survos\CoreBundle\Util;

/**
 * Unified JSONL writer (plain or gzip) with both row- and line-level APIs.
 * - write(array $row, bool $pretty=false)
 * - writeLine(string $line)
 * - flush(), close()
 */
final class JsonlWriter
{
    /** @var resource */

    /**
     * @param resource $handle
     */
    public function __construct(private $handle, private bool $gzip=false)
    {
        $this->h = $handle;
        $this->gzip = $gzip;
    }

    public static function open(string $path, bool $gzip = false, int $modeLevel = 6): self
    {
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0775, true);
        }

        if ($gzip) {
            $h = @gzopen($path, "a{$modeLevel}");
            if (!$h) { $h = @gzopen($path, "w{$modeLevel}"); }
            if (!$h) {
                throw new \RuntimeException("Unable to open gzip file for write: $path");
            }
            return new self($h, true);
        }

        $h = @fopen($path, 'ab');
        if (!$h) { $h = @fopen($path, 'wb'); }
        if (!$h) {
            throw new \RuntimeException("Unable to open file for write: $path");
        }
        return new self($h, false);
    }

    public function write(array $row, bool $pretty = false): void
    {
        $flags = JSON_UNESCAPED_UNICODE;
        $json = $pretty ? json_encode($row, $flags|JSON_PRETTY_PRINT) : json_encode($row, $flags);
        $this->writeLine($json);
    }

    public function writeLine(string $line): void
    {
        if ($line === '' || $line[strlen($line)-1] !== "\n") {
            $line .= "\n";
        }
        if ($this->gzip) {
            gzwrite($this->h, $line);
        } else {
            fwrite($this->h, $line);
        }
    }

    public function flush(): void
    {
        if (!$this->gzip) {
            fflush($this->h);
        }
    }

    public function close(): void
    {
        if ($this->gzip) {
            gzclose($this->h);
        } else {
            fclose($this->h);
        }
    }
}
