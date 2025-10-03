<?php
declare(strict_types=1);

namespace Survos\CoreBundle\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Robust chunked downloader with resume, retries, backoff, and progress reporting.
 *
 * Usage:
 *   $bytes = $downloader->download($url, $dest, function(int $written, ?int $total, float $bps) {
 *       // update progress bar here
 *   }, ['timeout' => 120.0]);
 */
final class ChunkDownloader
{
    public function __construct(
        private readonly ?HttpClientInterface $http=null,
        private readonly ?LoggerInterface $logger = new NullLogger()??null,
    ) {

    }

    /**
     * @param string        $url
     * @param string        $destination absolute or relative path to final file
     * @param null|callable $onProgress  fn(int $bytesWritten, ?int $totalBytes, float $bytesPerSecond): void
     * @param array{
     *   resume?: bool,
     *   overwrite?: bool,
     *   headers?: array<string,string>,
     *   timeout?: float|null,        // seconds; omit to use client default; DO NOT pass 0
     *   max_duration?: float|null,   // overall cap in seconds
     *   retries?: int,               // default 4
     *   backoff_ms?: int,            // base backoff (default 200ms)
     * } $options
     *
     * @return int bytes written (total size of the final file)
     * @throws \Throwable
     */
    public function download(string $url, string $destination, ?callable $onProgress = null, array $options = []): int
    {
        $resume       = $options['resume']        ?? true;
        $overwrite    = $options['overwrite']     ?? false;
        $headers      = $options['headers']       ?? [];
        $timeout      = $options['timeout']       ?? null;  // do NOT set 0 (causes immediate timeout)
        $maxDuration  = $options['max_duration']  ?? null;
        $retries      = max(0, (int)($options['retries'] ?? 4));
        $backoffMs    = max(1, (int)($options['backoff_ms'] ?? 200));

        // ensure destination directory exists
        $dir = \dirname($destination);
        if ($dir !== '' && !is_dir($dir)) {
            if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new \RuntimeException("Failed to create directory: $dir");
            }
        }

        $temp = $destination . '.part';
        $existing = 0;

        if (file_exists($destination)) {
            if ($overwrite) {
                @unlink($destination);
            } else {
                // already downloaded
                return filesize($destination) ?: 0;
            }
        }

        if (file_exists($temp)) {
            $existing = filesize($temp) ?: 0;
        } else {
            // create empty part file
            if (false === @touch($temp)) {
                throw new \RuntimeException("Cannot create temp file: $temp");
            }
        }

        $attempt = 0;
        $startAt = microtime(true);

        RETRY:
        $attempt++;

        // Determine resume
        $requestHeaders = $headers;
        $rangeRequested = false;
        if ($resume && $existing > 0) {
            $requestHeaders['Range'] = "bytes={$existing}-";
            $rangeRequested = true;
        }

        $clientOptions = [
            'headers' => $requestHeaders,
        ];
        if ($timeout !== null) {
            if ($timeout <= 0) {
                // 0 causes immediate timeout in Symfony HttpClient — refuse it.
                throw new \InvalidArgumentException('timeout must be null or > 0; do not pass 0.');
            }
            $clientOptions['timeout'] = (float)$timeout;
        }
        if ($maxDuration !== null && $maxDuration > 0) {
            $clientOptions['max_duration'] = (float)$maxDuration;
        }

        $response = null;
        $fp = null;

        try {
            $response = $this->http->request('GET', $url, $clientOptions);

            $status = $response->getStatusCode();
            if ($rangeRequested) {
                // Expect 206, but some servers ignore Range and send 200
                if ($status === 200) {
                    // server ignored Range: start over
                    $this->logger->notice("Server ignored Range, restarting full download for $url");
                    $existing = 0;
                    // truncate temp
                    $fp = fopen($temp, 'wb');
                } elseif ($status === 206) {
                    // partial content ok: append to temp
                    $fp = fopen($temp, 'ab');
                } else {
                    throw new \RuntimeException("Unexpected HTTP status $status for ranged request");
                }
            } else {
                if ($status !== 200) {
                    throw new \RuntimeException("Unexpected HTTP status $status");
                }
                $fp = fopen($temp, 'wb');
            }

            if (!$fp) {
                throw new \RuntimeException("Cannot open $temp for writing");
            }

            $this->logger->info(sprintf('Downloading %s -> %s (attempt %d, resume=%s, existing=%d)',
                $url, $destination, $attempt, $rangeRequested ? 'yes' : 'no', $existing));

            // Determine total size (best effort)
            $totalBytes = null;
            $headersAll = $response->getHeaders(false);
            if (isset($headersAll['content-length'][0]) && ctype_digit((string)$headersAll['content-length'][0])) {
                $segment = (int)$headersAll['content-length'][0];
                $totalBytes = $rangeRequested ? $existing + $segment : $segment;
            }

            $written = $existing;
            $lastTick = microtime(true);
            $lastBytes = $written;

            // stream chunks
            foreach ($this->http->stream($response) as $chunk) {
                $bytes = $chunk->getContent();
                if ($bytes !== '') {
                    $n = strlen($bytes);
                    if (fwrite($fp, $bytes) !== $n) {
                        throw new \RuntimeException("Short write to $temp");
                    }
                    $written += $n;

                    // Progress callback (once every ~100ms)
                    $now = microtime(true);
                    if ($onProgress && ($now - $lastTick) >= 0.1) {
                        $deltaB = $written - $lastBytes;
                        $deltaT = max(1e-6, $now - $lastTick);
                        $bps = $deltaB / $deltaT;
                        $onProgress($written, $totalBytes, $bps);
                        $lastTick = $now;
                        $lastBytes = $written;
                    }
                }
            }

            // final tick
            if ($onProgress) {
                $elapsed = max(1e-6, microtime(true) - $lastTick);
                $bps = ($written - $lastBytes) / $elapsed;
                $onProgress($written, $totalBytes, $bps);
            }

            fflush($fp);
            fclose($fp);
            $fp = null;

            // sanity check if we knew expected total
            if ($totalBytes !== null && $written !== $totalBytes) {
                $this->logger->warning(sprintf('Size mismatch: written=%d expected=%d (server may have closed early)', $written, $totalBytes));
            }

            // atomic rename
            if (!@rename($temp, $destination)) {
                throw new \RuntimeException("Failed to rename $temp to $destination");
            }

            $finalSize = filesize($destination) ?: 0;
            $this->logger->info(sprintf('Downloaded -> %s (%d bytes) in %.2fs',
                $destination, $finalSize, microtime(true) - $startAt));

            return $finalSize;
        } catch (\Throwable $e) {
            if (is_resource($fp)) {
                @fclose($fp);
            }
            // keep .part for resume on next attempt unless overwrite=false and status 200 conflict
            $this->logger->warning(sprintf('Download attempt %d failed: %s', $attempt, $e->getMessage()));

            if ($attempt <= $retries && $this->isRetryable($e)) {
                $sleep = $backoffMs * (2 ** ($attempt - 1));
                usleep(min(2000_000, $sleep) * 1000); // cap 2s
                goto RETRY;
            }

            // do not delete temp on final failure; caller may retry later
            throw $e;
        }
    }

    private function isRetryable(\Throwable $e): bool
    {
        if ($e instanceof TransportExceptionInterface) {
            return true;
        }
        if ($e instanceof HttpExceptionInterface) {
            // retry 5xx
            $code = $e->getResponse()->getStatusCode();
            return $code >= 500 && $code < 600;
        }
        // short write / connection reset / etc. are often transient
        $msg = strtolower($e->getMessage());
        foreach (['timeout', 'timed out', 'reset', 'aborted', 'broken pipe', 'connection'] as $needle) {
            if (str_contains($msg, $needle)) {
                return true;
            }
        }
        return false;
    }
}
