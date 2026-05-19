<?php
declare(strict_types=1);

namespace Survos\CoreBundle\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @deprecated Use \Survos\MultiFetchBundle\Service\ChunkDownloader instead.
 */
final class ChunkDownloader
{
    public function __construct(
        private readonly ?HttpClientInterface $http = null,
        private readonly ?LoggerInterface $logger = new NullLogger() ?? null,
    ) {
    }

    /** @return int bytes written */
    public function download(string $url, string $destination, ?callable $onProgress = null, array $options = []): int
    {
        $resume      = $options['resume']       ?? true;
        $overwrite   = $options['overwrite']    ?? false;
        $headers     = $options['headers']      ?? [];
        $timeout     = $options['timeout']      ?? null;
        $maxDuration = $options['max_duration'] ?? null;
        $retries     = max(0, (int)($options['retries']    ?? 4));
        $backoffMs   = max(1, (int)($options['backoff_ms'] ?? 200));

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
                return filesize($destination) ?: 0;
            }
        }

        if (file_exists($temp)) {
            $existing = filesize($temp) ?: 0;
        } elseif (false === @touch($temp)) {
            throw new \RuntimeException("Cannot create temp file: $temp");
        }

        $attempt = 0;
        $startAt = microtime(true);

        RETRY:
        $attempt++;
        $requestHeaders = $headers;
        $rangeRequested = false;
        if ($resume && $existing > 0) {
            $requestHeaders['Range'] = "bytes={$existing}-";
            $rangeRequested = true;
        }

        $clientOptions = ['headers' => $requestHeaders];
        if ($timeout !== null) {
            if ($timeout <= 0) {
                throw new \InvalidArgumentException('timeout must be null or > 0.');
            }
            $clientOptions['timeout'] = (float)$timeout;
        }
        if ($maxDuration !== null && $maxDuration > 0) {
            $clientOptions['max_duration'] = (float)$maxDuration;
        }

        $fp = null;
        try {
            $response = $this->http->request('GET', $url, $clientOptions);
            $status = $response->getStatusCode();

            if ($rangeRequested) {
                if ($status === 200) {
                    $existing = 0;
                    $fp = fopen($temp, 'wb');
                } elseif ($status === 206) {
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

            $totalBytes = null;
            $headersAll = $response->getHeaders(false);
            if (isset($headersAll['content-length'][0]) && ctype_digit((string)$headersAll['content-length'][0])) {
                $segment = (int)$headersAll['content-length'][0];
                $totalBytes = $rangeRequested ? $existing + $segment : $segment;
            }

            $written = $existing;
            $lastTick = microtime(true);
            $lastBytes = $written;

            foreach ($this->http->stream($response) as $chunk) {
                $bytes = $chunk->getContent();
                if ($bytes !== '') {
                    $n = strlen($bytes);
                    if (fwrite($fp, $bytes) !== $n) {
                        throw new \RuntimeException("Short write to $temp");
                    }
                    $written += $n;
                    $now = microtime(true);
                    if ($onProgress && ($now - $lastTick) >= 0.1) {
                        $bps = ($written - $lastBytes) / max(1e-6, $now - $lastTick);
                        $onProgress($written, $totalBytes, $bps);
                        $lastTick = $now;
                        $lastBytes = $written;
                    }
                }
            }

            if ($onProgress) {
                $bps = ($written - $lastBytes) / max(1e-6, microtime(true) - $lastTick);
                $onProgress($written, $totalBytes, $bps);
            }

            fflush($fp);
            fclose($fp);
            $fp = null;

            if (!@rename($temp, $destination)) {
                throw new \RuntimeException("Failed to rename $temp to $destination");
            }

            return filesize($destination) ?: 0;
        } catch (\Throwable $e) {
            if (is_resource($fp)) {
                @fclose($fp);
            }
            if ($attempt <= $retries && $this->isRetryable($e)) {
                usleep(min(2_000_000, $backoffMs * (2 ** ($attempt - 1))) * 1000);
                goto RETRY;
            }
            throw $e;
        }
    }

    private function isRetryable(\Throwable $e): bool
    {
        if ($e instanceof TransportExceptionInterface || $e instanceof HttpExceptionInterface) {
            return !($e instanceof HttpExceptionInterface) || $e->getResponse()->getStatusCode() >= 500;
        }
        $msg = strtolower($e->getMessage());
        foreach (['timeout', 'timed out', 'reset', 'aborted', 'broken pipe', 'connection'] as $needle) {
            if (str_contains($msg, $needle)) {
                return true;
            }
        }
        return false;
    }
}
