<?php
declare(strict_types=1);

namespace Tds\Ext\Tools\Studio;

/**
 * Sliding-window limiter in small locked files, one per key (hashed). Same
 * shape as the core's pairing limiter: no table, works across PHP processes,
 * and fails OPEN when the host cannot write — availability over a counter.
 */
final class FileRateLimiter
{
    public function __construct(private readonly string $directory)
    {
    }

    public function allow(string $key, int $limit, int $windowSeconds, ?int $now = null): bool
    {
        $now ??= time();
        if ($limit < 1) {
            return false;
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            return true;
        }
        $path = rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
        $file = @fopen($path, 'c+');
        if ($file === false) {
            return true;
        }
        try {
            if (!flock($file, LOCK_EX)) {
                return true;
            }
            rewind($file);
            $raw = stream_get_contents($file);
            $stamps = json_decode(is_string($raw) ? $raw : '', true);
            $stamps = is_array($stamps) ? array_values(array_filter(
                array_map('intval', $stamps),
                static fn (int $t): bool => $t > $now - $windowSeconds,
            )) : [];
            if (count($stamps) >= $limit) {
                return false;
            }
            $stamps[] = $now;
            ftruncate($file, 0);
            rewind($file);
            fwrite($file, json_encode($stamps, JSON_THROW_ON_ERROR));
            fflush($file);
            return true;
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }
}
