<?php
declare(strict_types=1);

namespace Tds\Ext\Tools\Studio;

/**
 * Decides which URLs the Designsystem-Studio may fetch: HTTPS on port 443 to a
 * public host, nothing else. Applied to the page AND to every stylesheet and
 * every redirect hop — the address is checked where it is used, not once.
 *
 * Host names are only half of it: {@see PageFetcher} also resolves the name,
 * requires every answer to be public and pins the connection to the checked
 * address, so a second DNS answer (rebinding) cannot reach an internal host.
 */
final class UrlGuard
{
    public const MAX_URL_LENGTH = 2048;

    /** Reserved, private, loopback, link-local, CGNAT, multicast, documentation, … */
    private const DENY_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
        '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
    ];

    private const DENY_V6 = [
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/23',
        '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    private const DENY_SUFFIXES = ['.localhost', '.local', '.internal', '.intranet', '.lan', '.home', '.corp', '.home.arpa', '.localdomain'];

    /**
     * @return array{url:string,host:string,path:string}
     * @throws StudioFetchException invalid_url | blocked_host
     */
    public static function parse(string $url): array
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new StudioFetchException('invalid_url', 'Ungültige Adresse.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            throw new StudioFetchException('invalid_url', 'Nur HTTPS-Adressen werden analysiert.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new StudioFetchException('invalid_url', 'Adressen mit Zugangsdaten werden nicht geladen.');
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            throw new StudioFetchException('invalid_url', 'Nur der HTTPS-Standardport 443 ist erlaubt.');
        }
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '') {
            throw new StudioFetchException('invalid_url', 'Die Adresse enthält keinen Host.');
        }
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (!self::isPublicIp($host)) {
                throw new StudioFetchException('blocked_host', 'Interne oder reservierte Adressen werden nicht geladen.');
            }
        } else {
            if (preg_match('/[^\x20-\x7e]/', $host) === 1) {
                $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;
                if (!is_string($ascii) || $ascii === '') {
                    throw new StudioFetchException('invalid_url', 'Der Hostname kann nicht verarbeitet werden.');
                }
                $host = strtolower($ascii);
            }
            if ($host === 'localhost' || self::hasDeniedSuffix($host)) {
                throw new StudioFetchException('blocked_host', 'Interne Hostnamen werden nicht geladen.');
            }
            if (strlen($host) > 253 || preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $host) !== 1) {
                throw new StudioFetchException('invalid_url', 'Ungültiger Hostname.');
            }
        }
        $path = (string) ($parts['path'] ?? '/');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $hostPart = str_contains($host, ':') ? "[{$host}]" : $host;
        return ['url' => "https://{$hostPart}" . ($path === '' ? '/' : $path) . $query, 'host' => $host, 'path' => $path];
    }

    private static function hasDeniedSuffix(string $host): bool
    {
        foreach (self::DENY_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }
        return false;
    }

    /** True only for a globally routable unicast address. */
    public static function isPublicIp(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
            // IPv4-mapped: judge the embedded IPv4 address (and refuse it anyway below).
            return false;
        }
        $list = strlen($bin) === 4 ? self::DENY_V4 : self::DENY_V6;
        foreach ($list as $cidr) {
            if (self::inCidr($bin, $cidr)) {
                return false;
            }
        }
        // Belt and braces: PHP's own reserved/private lists.
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private static function inCidr(string $bin, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $netBin = inet_pton($net);
        if ($netBin === false || strlen($netBin) !== strlen($bin)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xff << (8 - $rest)) & 0xff;
        return (ord($bin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    /** Resolves `$ref` against `$base` (RFC 3986, enough for href/src/@import values). */
    public static function resolve(string $base, string $ref): ?string
    {
        $ref = trim(html_entity_decode($ref, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($ref === '' || str_starts_with($ref, '#') || preg_match('/^(data|javascript|mailto|tel|blob):/i', $ref) === 1) {
            return null;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $ref) === 1) {
            return $ref;
        }
        $b = parse_url($base);
        if (!is_array($b) || !isset($b['scheme'], $b['host'])) {
            return null;
        }
        $origin = $b['scheme'] . '://' . (str_contains($b['host'], ':') && !str_starts_with($b['host'], '[') ? "[{$b['host']}]" : $b['host']) . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($ref, '//')) {
            return $b['scheme'] . ':' . $ref;
        }
        if (str_starts_with($ref, '/')) {
            return $origin . self::removeDots($ref);
        }
        $dir = preg_replace('#/[^/]*$#', '/', (string) ($b['path'] ?? '/')) ?: '/';
        if (str_starts_with($ref, '?')) {
            return $origin . (string) ($b['path'] ?? '/') . $ref;
        }
        return $origin . self::removeDots($dir . $ref);
    }

    private static function removeDots(string $path): string
    {
        $query = '';
        if (($q = strpos($path, '?')) !== false) {
            $query = substr($path, $q);
            $path = substr($path, 0, $q);
        }
        $out = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '..') {
                array_pop($out);
            } elseif ($seg !== '.') {
                $out[] = $seg;
            }
        }
        $joined = implode('/', $out);
        return (str_starts_with($joined, '/') ? $joined : '/' . ltrim($joined, '/')) . $query;
    }
}
