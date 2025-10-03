<?php
declare(strict_types=1);

namespace Survos\CoreBundle\Util;

/**
 * Small helpers for JSONL files (plain or gzip).
 */
final class Jsonl
{
    /**
     * Count the number of lines (rows) in a JSONL file.
     * Works for both plain text and .gz files; streams safely.
     */
    public static function countLines(string $path): int
    {
        if (!is_file($path)) {
            return 0;
        }

        $gzip = self::isGzipPath($path);

        $count = 0;
        if ($gzip) {
            $h = \gzopen($path, 'rb');
            if (!$h) return 0;

            // Read in chunks for speed; count "\n"
            while (!\gzeof($h)) {
                $chunk = \gzread($h, 1 << 20); // 1 MiB
                if ($chunk === false) break;
                $count += substr_count($chunk, "\n");
            }
            \gzclose($h);
            return $count;
        }

        $h = \fopen($path, 'rb');
        if (!$h) return 0;

        while (!\feof($h)) {
            $chunk = \fread($h, 1 << 20);
            if ($chunk === false) break;
            $count += substr_count($chunk, "\n");
        }
        \fclose($h);
        return $count;
    }

    /**
     * True if the path likely refers to a gzip file.
     */
    public static function isGzipPath(string $path): bool
    {
        return str_ends_with($path, '.gz') || str_ends_with($path, '.gzip');
    }
}
