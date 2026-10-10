<?php
declare(strict_types=1);

namespace Tds\Ext\Tools\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tds\Ext\Tools\Studio\FileRateLimiter;
use Tds\Ext\Tools\Studio\HostResolver;
use Tds\Ext\Tools\Studio\HttpTransport;
use Tds\Ext\Tools\Studio\PageFetcher;
use Tds\Ext\Tools\Studio\StudioFetchException;
use Tds\Ext\Tools\Studio\StudioPolicy;
use Tds\Ext\Tools\Studio\UrlGuard;

/** DNS double: a fixed answer per host. */
final class FakeResolver implements HostResolver
{
    /** @param array<string,list<string>> $answers */
    public function __construct(private readonly array $answers)
    {
    }

    public function resolve(string $host): array
    {
        return $this->answers[$host] ?? [];
    }
}

/** Transport double: canned responses per URL; records every request. */
final class FakeTransport implements HttpTransport
{
    /** @var list<array{url:string,host:string,ip:string}> */
    public array $requests = [];

    /** @param array<string,array{status?:int,headers?:array<string,string>,body?:string,truncated?:bool,error?:?string}> $responses */
    public function __construct(private readonly array $responses)
    {
    }

    public function get(string $url, string $host, string $pinnedIp, int $maxBytes, int $timeoutSeconds): array
    {
        $this->requests[] = ['url' => $url, 'host' => $host, 'ip' => $pinnedIp];
        $r = $this->responses[$url] ?? ['status' => 404];
        $body = $r['body'] ?? '';
        return [
            'status' => $r['status'] ?? 200,
            'headers' => $r['headers'] ?? [],
            'body' => strlen($body) > $maxBytes ? '' : $body,
            'truncated' => ($r['truncated'] ?? false) || strlen($body) > $maxBytes,
            'error' => $r['error'] ?? null,
        ];
    }
}

final class StudioFetchTest extends TestCase
{
    /** @return iterable<string,array{string}> */
    public static function refusedUrls(): iterable
    {
        yield 'plain http' => ['http://example.org/'];
        yield 'other scheme' => ['ftp://example.org/'];
        yield 'credentials' => ['https://user:pw@example.org/'];
        yield 'non-standard port' => ['https://example.org:8443/'];
        yield 'localhost' => ['https://localhost/'];
        yield 'local suffix' => ['https://printer.local/'];
        yield 'internal suffix' => ['https://db.internal/'];
        yield 'single label' => ['https://intranet/'];
        yield 'loopback literal' => ['https://127.0.0.1/'];
        yield 'private literal' => ['https://10.1.2.3/'];
        yield 'cloud metadata' => ['https://169.254.169.254/latest/meta-data/'];
        yield 'cgnat' => ['https://100.64.0.1/'];
        yield 'zero net' => ['https://0.0.0.0/'];
        yield 'ipv6 loopback' => ['https://[::1]/'];
        yield 'ipv6 unique local' => ['https://[fd00::1]/'];
        yield 'ipv4-mapped ipv6' => ['https://[::ffff:127.0.0.1]/'];
        yield 'empty' => [''];
        yield 'control chars' => ["https://exa\nmple.org/"];
    }

    #[DataProvider('refusedUrls')]
    public function testRefusesNonPublicOrNonHttpsTargets(string $url): void
    {
        $this->expectException(StudioFetchException::class);
        UrlGuard::parse($url);
    }

    public function testAcceptsAPublicHttpsUrl(): void
    {
        $t = UrlGuard::parse('https://Example.org/pfad?x=1#frag');
        self::assertSame('https://example.org/pfad?x=1', $t['url']);
        self::assertSame('example.org', $t['host']);
    }

    public function testClassifiesAddresses(): void
    {
        foreach (['93.184.216.34', '8.8.8.8', '2606:4700:4700::1111'] as $ip) {
            self::assertTrue(UrlGuard::isPublicIp($ip), $ip);
        }
        foreach (['127.0.0.1', '10.0.0.1', '172.16.5.4', '192.168.1.1', '169.254.169.254', '100.64.1.1', '::1', 'fe80::1', 'fc00::1', '::ffff:10.0.0.1', '224.0.0.1', '255.255.255.255', 'not-an-ip'] as $ip) {
            self::assertFalse(UrlGuard::isPublicIp($ip), $ip);
        }
    }

    public function testResolvesRelativeReferences(): void
    {
        self::assertSame('https://a.example/css/x.css', UrlGuard::resolve('https://a.example/dir/page.html', '/css/x.css'));
        self::assertSame('https://a.example/dir/x.css', UrlGuard::resolve('https://a.example/dir/page.html', 'x.css'));
        self::assertSame('https://a.example/x.css', UrlGuard::resolve('https://a.example/dir/sub/p', '../../x.css'));
        self::assertSame('https://cdn.example/x.css', UrlGuard::resolve('https://a.example/', '//cdn.example/x.css'));
        self::assertNull(UrlGuard::resolve('https://a.example/', 'javascript:alert(1)'));
    }

    private function page(string $html, array $extra = []): FakeTransport
    {
        return new FakeTransport(array_merge([
            'https://site.example/' => ['status' => 200, 'headers' => ['content-type' => 'text/html; charset=utf-8'], 'body' => $html],
        ], $extra));
    }

    private const PUBLIC = ['site.example' => ['93.184.216.34'], 'cdn.example' => ['93.184.216.35'], 'other.example' => ['93.184.216.36']];

    public function testFetchesOnePageAndItsStylesheetsOnly(): void
    {
        $html = '<html><head><title>T</title><link rel="stylesheet" href="/a.css"><link rel="stylesheet" href="https://cdn.example/b.css">'
            . '<link rel="alternate stylesheet" href="/alt.css"><link rel="stylesheet" media="print" href="/print.css"><link rel="icon" href="/fav.ico">'
            . '<script>alert(1)</script></head><body onload="x()"><a href="/unterseite">Mehr</a><a href="https://other.example/">Fremd</a>'
            . '<button onclick="y()">B</button></body></html>';
        $http = $this->page($html, [
            'https://site.example/a.css' => ['headers' => ['content-type' => 'text/css'], 'body' => '@import url("https://cdn.example/c.css"); .a{color:red}'],
            'https://cdn.example/b.css' => ['headers' => ['content-type' => 'text/css'], 'body' => '.b{color:blue}'],
            'https://cdn.example/c.css' => ['headers' => ['content-type' => 'text/css'], 'body' => '.c{color:green}'],
        ]);
        $result = (new PageFetcher(new FakeResolver(self::PUBLIC), $http))->fetch('https://site.example/');

        self::assertSame(['https://site.example/a.css', 'https://cdn.example/b.css', 'https://cdn.example/c.css'], array_column($result['stylesheets'], 'url'));
        // No link was followed: only the page and its stylesheets were requested.
        self::assertSame(
            ['https://site.example/', 'https://site.example/a.css', 'https://cdn.example/b.css', 'https://cdn.example/c.css'],
            array_column($http->requests, 'url'),
        );
        self::assertStringNotContainsString('<script', $result['html']);
        self::assertStringNotContainsString('onload', $result['html']);
        self::assertStringNotContainsString('onclick', $result['html']);
        self::assertSame('T', $result['title']);
        // Every connection was pinned to the checked address.
        self::assertSame('93.184.216.34', $http->requests[0]['ip']);
        self::assertSame('93.184.216.35', $http->requests[2]['ip']);
    }

    public function testRefusesAHostWithAnyPrivateAnswer(): void
    {
        // DNS rebinding / split answers: one public and one internal address.
        $resolver = new FakeResolver(['site.example' => ['93.184.216.34', '10.0.0.5']]);
        $http = $this->page('<html></html>');
        try {
            (new PageFetcher($resolver, $http))->fetch('https://site.example/');
            self::fail('expected blocked_host');
        } catch (StudioFetchException $e) {
            self::assertSame('blocked_host', $e->errorCode);
        }
        self::assertSame([], $http->requests, 'nothing may be requested from a blocked host');
    }

    public function testReportsAPageRedirectInsteadOfFollowingIt(): void
    {
        $http = new FakeTransport(['https://site.example/' => ['status' => 301, 'headers' => ['location' => '/neu']]]);
        try {
            (new PageFetcher(new FakeResolver(self::PUBLIC), $http))->fetch('https://site.example/');
            self::fail('expected redirect');
        } catch (StudioFetchException $e) {
            self::assertSame('redirect', $e->errorCode);
            self::assertSame('https://site.example/neu', $e->location);
            self::assertSame(422, $e->httpStatus());
        }
        self::assertCount(1, $http->requests);
    }

    public function testARedirectToAnInternalAddressIsNotOffered(): void
    {
        $http = new FakeTransport(['https://site.example/' => ['status' => 302, 'headers' => ['location' => 'https://127.0.0.1/admin']]]);
        try {
            (new PageFetcher(new FakeResolver(self::PUBLIC), $http))->fetch('https://site.example/');
            self::fail('expected redirect');
        } catch (StudioFetchException $e) {
            self::assertNull($e->location);
        }
    }

    public function testStylesheetRedirectsAreRecheckedAndNonCssIsRefused(): void
    {
        $html = '<link rel="stylesheet" href="/to-internal.css"><link rel="stylesheet" href="/html.css"><link rel="stylesheet" href="http://site.example/plain.css">';
        $http = $this->page($html, [
            'https://site.example/to-internal.css' => ['status' => 302, 'headers' => ['location' => 'https://intern.example/x.css']],
            'https://site.example/html.css' => ['headers' => ['content-type' => 'text/html'], 'body' => '<html>another page</html>'],
        ]);
        $resolver = new FakeResolver(self::PUBLIC + ['intern.example' => ['192.168.0.10']]);
        $result = (new PageFetcher($resolver, $http))->fetch('https://site.example/');
        self::assertSame([], $result['stylesheets']);
        $reasons = array_column($result['skipped'], 'reason', 'url');
        self::assertSame('blocked_host', $reasons['https://site.example/to-internal.css']);
        self::assertSame('not_css', $reasons['https://site.example/html.css']);
        // The http:// sheet is never even requested.
        self::assertNotContains('http://site.example/plain.css', array_column($http->requests, 'url'));
        self::assertNotContains('https://intern.example/x.css', array_column($http->requests, 'url'));
    }

    public function testEnforcesLimits(): void
    {
        $links = '';
        for ($i = 0; $i < 5; $i++) {
            $links .= "<link rel=\"stylesheet\" href=\"/s{$i}.css\">";
        }
        $responses = [];
        for ($i = 0; $i < 5; $i++) {
            $responses["https://site.example/s{$i}.css"] = ['headers' => ['content-type' => 'text/css'], 'body' => '.x{}'];
        }
        $result = (new PageFetcher(new FakeResolver(self::PUBLIC), $this->page($links, $responses), 2))->fetch('https://site.example/');
        self::assertCount(2, $result['stylesheets']);
        self::assertSame('limit', $result['skipped'][0]['reason']);

        $big = new FakeTransport(['https://site.example/' => ['headers' => ['content-type' => 'text/html'], 'body' => str_repeat('x', 3000)]]);
        try {
            (new PageFetcher(new FakeResolver(self::PUBLIC), $big, 12, 1000))->fetch('https://site.example/');
            self::fail('expected too_large');
        } catch (StudioFetchException $e) {
            self::assertSame('too_large', $e->errorCode);
        }
    }

    public function testRejectsNonHtmlAndTimeouts(): void
    {
        $pdf = new FakeTransport(['https://site.example/' => ['headers' => ['content-type' => 'application/pdf'], 'body' => '%PDF']]);
        try {
            (new PageFetcher(new FakeResolver(self::PUBLIC), $pdf))->fetch('https://site.example/');
            self::fail('expected not_html');
        } catch (StudioFetchException $e) {
            self::assertSame('not_html', $e->errorCode);
        }
        $slow = new FakeTransport(['https://site.example/' => ['status' => 0, 'error' => 'timeout']]);
        try {
            (new PageFetcher(new FakeResolver(self::PUBLIC), $slow))->fetch('https://site.example/');
            self::fail('expected timeout');
        } catch (StudioFetchException $e) {
            self::assertSame('timeout', $e->errorCode);
            self::assertSame(504, $e->httpStatus());
        }
    }

    public function testRateLimiterCountsPerKeyInAWindow(): void
    {
        $dir = sys_get_temp_dir() . '/tds-studio-rate-test-' . bin2hex(random_bytes(4));
        $limiter = new FileRateLimiter($dir);
        self::assertTrue($limiter->allow('a', 2, 3600, 1000));
        self::assertTrue($limiter->allow('a', 2, 3600, 1001));
        self::assertFalse($limiter->allow('a', 2, 3600, 1002));
        self::assertTrue($limiter->allow('b', 2, 3600, 1002));
        self::assertTrue($limiter->allow('a', 2, 3600, 5000), 'the window slides');
        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }

    public function testPolicyParsesTheAdminSetting(): void
    {
        $f = StudioPolicy::formats(StudioPolicy::DEFAULT_PREMIUM);
        self::assertSame('free', $f['css']);
        self::assertSame('premium', $f['zip']);
        self::assertSame(StudioPolicy::FORMATS, array_keys($f));
        self::assertSame('css,zip', StudioPolicy::normalize(' ZIP, css ,unknown'));
    }
}
