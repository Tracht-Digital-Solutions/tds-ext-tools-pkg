<?php
declare(strict_types=1);

namespace Tds\Ext\Tools\Studio;

/**
 * cURL GET with the connection pinned to a checked IP (`CURLOPT_RESOLVE`), so
 * curl never asks DNS a second time — the classic rebinding window is closed.
 * HTTPS only, certificate verified, no proxy from the environment, no
 * redirects, a byte cap that counts DECODED bytes (a gzip bomb stops at the
 * cap) and hard connect/total timeouts.
 */
final class CurlTransport implements HttpTransport
{
    public const USER_AGENT = 'TDS-Designsystem-Studio/1.0 (+https://tools.tracht-digital.de; single-page style analysis)';

    public function get(string $url, string $host, string $pinnedIp, int $maxBytes, int $timeoutSeconds): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'headers' => [], 'body' => '', 'truncated' => false, 'error' => 'curl unavailable'];
        }
        $body = '';
        $truncated = false;
        $headers = [];
        $ch = curl_init($url);
        $resolveIp = str_contains($pinnedIp, ':') ? "[{$pinnedIp}]" : $pinnedIp;
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => ["{$host}:443:{$resolveIp}"],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,text/css;q=0.9,*/*;q=0.1', 'Accept-Language: de,en;q=0.8'],
            CURLOPT_ENCODING => '',
            CURLOPT_MAXFILESIZE => $maxBytes,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$truncated, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $truncated = true;
                    return -1; // aborts the transfer
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $primary = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        curl_close($ch);
        if ($primary !== '' && inet_pton($primary) !== inet_pton($pinnedIp)) {
            // Should be impossible with CURLOPT_RESOLVE; refuse rather than trust.
            return ['status' => 0, 'headers' => [], 'body' => '', 'truncated' => false, 'error' => 'connected to an unexpected address'];
        }
        if ($truncated || $errno === CURLE_FILESIZE_EXCEEDED) {
            return ['status' => $status, 'headers' => $headers, 'body' => '', 'truncated' => true, 'error' => null];
        }
        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            return ['status' => 0, 'headers' => $headers, 'body' => '', 'truncated' => false, 'error' => 'timeout'];
        }
        if ($errno !== 0) {
            return ['status' => 0, 'headers' => $headers, 'body' => '', 'truncated' => false, 'error' => 'curl error ' . $errno];
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $body, 'truncated' => false, 'error' => null];
    }
}
