<?php
declare(strict_types=1);

namespace Tds\Ext\Tools\Studio;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Loads exactly ONE user-named HTTPS page plus the stylesheets it links, and
 * nothing more: no links followed, no sitemap, no second HTML document, no
 * JavaScript executed (the HTML is only parsed as data, and scripts are
 * stripped before the page leaves this server).
 *
 * Every request — page, stylesheet, redirect hop — runs the same gate:
 * {@see UrlGuard::parse()} on the URL, then DNS resolution where EVERY answer
 * must be public, then a connection pinned to the checked address. The page
 * itself is never redirected; a redirect is reported with its target so the
 * user can decide to analyse that page instead. Stylesheets may follow a few
 * HTTPS redirects (CDNs do that), each hop checked again, and must really be
 * CSS — an HTML answer is not read.
 */
final class PageFetcher
{
    private float $deadline = 0.0;

    public function __construct(
        private readonly HostResolver $resolver,
        private readonly HttpTransport $http,
        private readonly int $maxStylesheets = 12,
        private readonly int $maxHtmlBytes = 2_000_000,
        private readonly int $maxCssBytes = 1_000_000,
        private readonly int $maxTotalCssBytes = 4_000_000,
        private readonly int $timeoutSeconds = 8,
        private readonly float $budgetSeconds = 20.0,
    ) {
    }

    /**
     * @return array{url:string,finalUrl:string,title:string,html:string,stylesheets:list<array{url:string,css:string}>,skipped:list<array{url:string,reason:string}>}
     * @throws StudioFetchException
     */
    public function fetch(string $url): array
    {
        $this->deadline = microtime(true) + $this->budgetSeconds;
        $target = UrlGuard::parse($url);
        $res = $this->request($target['url'], $target['host'], $this->maxHtmlBytes);

        if ($res['status'] >= 300 && $res['status'] < 400) {
            $location = UrlGuard::resolve($target['url'], (string) ($res['headers']['location'] ?? ''));
            $safe = null;
            if ($location !== null) {
                try {
                    $safe = UrlGuard::parse($location)['url'];
                } catch (StudioFetchException) {
                    $safe = null;
                }
            }
            throw new StudioFetchException(
                'redirect',
                $safe !== null ? "Die Seite leitet weiter auf {$safe}" : 'Die Seite leitet auf eine Adresse weiter, die nicht analysiert werden darf.',
                $safe,
            );
        }
        if ($res['truncated']) {
            throw new StudioFetchException('too_large', 'Die Seite ist größer als ' . intdiv($this->maxHtmlBytes, 1_000_000) . ' MB.');
        }
        if ($res['status'] !== 200) {
            throw new StudioFetchException('fetch_failed', "Die Seite antwortete mit HTTP {$res['status']}.");
        }
        $type = strtolower((string) ($res['headers']['content-type'] ?? ''));
        if (!str_contains($type, 'text/html') && !str_contains($type, 'application/xhtml+xml')) {
            throw new StudioFetchException('not_html', 'Unter dieser Adresse liegt keine HTML-Seite.');
        }

        $html = self::toUtf8($res['body'], $type);
        [$clean, $title, $links] = $this->parseHtml($html, $target['url']);

        $sheets = [];
        $skipped = [];
        $total = 0;
        $queue = $links;
        $seen = [];
        /** @var array<string,bool> $fromImport sheets reached via @import (not followed further) */
        $fromImport = [];
        while ($queue !== []) {
            $href = array_shift($queue);
            if (isset($seen[$href])) {
                continue;
            }
            $seen[$href] = true;
            if (count($sheets) >= $this->maxStylesheets) {
                $skipped[] = ['url' => $href, 'reason' => 'limit'];
                continue;
            }
            if (microtime(true) > $this->deadline) {
                $skipped[] = ['url' => $href, 'reason' => 'time_budget'];
                continue;
            }
            try {
                $css = $this->fetchCss($href, 3);
            } catch (StudioFetchException $e) {
                $skipped[] = ['url' => $href, 'reason' => $e->errorCode];
                continue;
            }
            if ($total + strlen($css) > $this->maxTotalCssBytes) {
                $skipped[] = ['url' => $href, 'reason' => 'too_large'];
                continue;
            }
            $total += strlen($css);
            $sheets[] = ['url' => $href, 'css' => $css];
            // One level of @import, resolved against the stylesheet itself.
            if (!isset($fromImport[$href])) {
                foreach (self::imports($css, $href) as $imp) {
                    $fromImport[$imp] = true;
                    $queue[] = $imp;
                }
            }
        }

        return [
            'url' => $url,
            'finalUrl' => $target['url'],
            'title' => $title,
            'html' => $clean,
            'stylesheets' => $sheets,
            'skipped' => $skipped,
        ];
    }

    /** @return array{status:int,headers:array<string,string>,body:string,truncated:bool,error:?string} */
    private function request(string $url, string $host, int $maxBytes): array
    {
        $ip = $this->publicIp($host);
        $remaining = (int) ceil($this->deadline - microtime(true));
        if ($remaining <= 0) {
            throw new StudioFetchException('timeout', 'Die Analyse hat ihr Zeitlimit erreicht.');
        }
        $res = $this->http->get($url, $host, $ip, $maxBytes, min($this->timeoutSeconds, $remaining));
        if ($res['error'] === 'timeout') {
            throw new StudioFetchException('timeout', 'Die Seite hat nicht rechtzeitig geantwortet.');
        }
        if ($res['error'] !== null) {
            throw new StudioFetchException('fetch_failed', 'Die Seite konnte nicht geladen werden.');
        }
        return $res;
    }

    /** Resolves and requires EVERY address to be public; returns the one to pin. */
    private function publicIp(string $host): string
    {
        $ips = $this->resolver->resolve($host);
        if ($ips === []) {
            throw new StudioFetchException('fetch_failed', 'Der Hostname ließ sich nicht auflösen.');
        }
        foreach ($ips as $ip) {
            if (!UrlGuard::isPublicIp($ip)) {
                throw new StudioFetchException('blocked_host', 'Der Host zeigt (auch) auf eine interne oder reservierte Adresse.');
            }
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $ip;
            }
        }
        return $ips[0];
    }

    private function fetchCss(string $url, int $redirectsLeft): string
    {
        $target = UrlGuard::parse($url);
        $res = $this->request($target['url'], $target['host'], $this->maxCssBytes);
        if ($res['status'] >= 300 && $res['status'] < 400) {
            if ($redirectsLeft <= 0) {
                throw new StudioFetchException('redirect', 'Zu viele Weiterleitungen.');
            }
            $next = UrlGuard::resolve($target['url'], (string) ($res['headers']['location'] ?? ''));
            if ($next === null) {
                throw new StudioFetchException('redirect', 'Ungültige Weiterleitung.');
            }
            return $this->fetchCss($next, $redirectsLeft - 1);
        }
        if ($res['truncated']) {
            throw new StudioFetchException('too_large', 'Stylesheet zu groß.');
        }
        if ($res['status'] !== 200) {
            throw new StudioFetchException('fetch_failed', "HTTP {$res['status']}");
        }
        $type = strtolower((string) ($res['headers']['content-type'] ?? ''));
        if (!str_contains($type, 'text/css')) {
            // Never read a second HTML document (or anything else) as "CSS".
            throw new StudioFetchException('not_css', 'Kein Stylesheet.');
        }
        return self::toUtf8($res['body'], $type);
    }

    /**
     * Parses the page as inert data: no network (libxml never fetches here), no
     * script execution. Returns the page without active content, its title and
     * the stylesheet URLs it links (HTTPS only, screen media only).
     *
     * @return array{0:string,1:string,2:list<string>}
     */
    private function parseHtml(string $html, string $pageUrl): array
    {
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new DOMXPath($doc);

        $base = $pageUrl;
        $baseEl = $xp->query('//base[@href]')->item(0);
        if ($baseEl instanceof DOMElement) {
            $resolved = UrlGuard::resolve($pageUrl, $baseEl->getAttribute('href'));
            if ($resolved !== null && str_starts_with(strtolower($resolved), 'https://')) {
                $base = $resolved;
            }
        }

        $links = [];
        foreach ($xp->query('//link[@href]') as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }
            $rel = ' ' . strtolower($link->getAttribute('rel')) . ' ';
            if (!str_contains($rel, ' stylesheet ') || str_contains($rel, ' alternate ')) {
                continue;
            }
            $media = strtolower(trim($link->getAttribute('media')));
            if ($media !== '' && !str_contains($media, 'all') && !str_contains($media, 'screen')) {
                continue;
            }
            $abs = UrlGuard::resolve($base, $link->getAttribute('href'));
            if ($abs !== null && str_starts_with(strtolower($abs), 'https://')) {
                $links[] = $abs;
            }
        }

        $title = trim((string) ($xp->query('//title')->item(0)?->textContent ?? ''));

        // Strip active and embedded content; the client only needs markup + styles.
        foreach (['script', 'noscript', 'template', 'iframe', 'object', 'embed', 'frame', 'frameset', 'applet', 'base'] as $tag) {
            foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
        foreach ($xp->query('//@*[starts-with(translate(name(), "ON", "on"), "on")]') as $attr) {
            if ($attr instanceof \DOMAttr) {
                $attr->ownerElement?->removeAttributeNode($attr);
            }
        }
        $clean = (string) $doc->saveHTML();
        $clean = str_replace('<?xml encoding="UTF-8">', '', $clean);
        return [$clean, mb_substr($title, 0, 200), array_values(array_unique($links))];
    }

    /** @return list<string> absolute HTTPS @import targets */
    private static function imports(string $css, string $sheetUrl): array
    {
        $out = [];
        $noComments = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
        if (preg_match_all('/@import\s+(?:url\(\s*)?["\']?([^"\')\s;]+)["\']?\s*\)?/i', $noComments, $m) > 0) {
            foreach ($m[1] as $ref) {
                $abs = UrlGuard::resolve($sheetUrl, $ref);
                if ($abs !== null && str_starts_with(strtolower($abs), 'https://')) {
                    $out[] = $abs;
                }
            }
        }
        return $out;
    }

    private static function toUtf8(string $body, string $contentType): string
    {
        $charset = null;
        if (preg_match('/charset=([\w-]+)/i', $contentType, $m) === 1) {
            $charset = strtoupper($m[1]);
        } elseif (preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', substr($body, 0, 4096), $m) === 1) {
            $charset = strtoupper($m[1]);
        }
        if ($charset !== null && $charset !== 'UTF-8' && $charset !== 'UTF8' && function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($body, 'UTF-8', $charset);
            if (is_string($converted)) {
                return $converted;
            }
        }
        return function_exists('mb_scrub') ? mb_scrub($body, 'UTF-8') : $body;
    }
}
