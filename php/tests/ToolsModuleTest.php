<?php
declare(strict_types=1);

namespace Tds\Ext\Tools\Tests;

use DI\Container;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\Ext\Tools\Domain\ToolConfigRepository;
use Tds\Ext\Tools\ToolsModule;
use Tds\Frontend\Contract\CacheEvent;
use Tds\Frontend\Contract\CacheResult;
use Tds\Frontend\Contract\ConnectedSiteCache;
use Tds\Frontend\Contract\ModuleRegistry;
use Tds\Frontend\Contract\SiteCache;
use Tds\Frontend\Contract\SiteConnection;
use Tds\Frontend\Contract\SiteConnections;
use Tds\Frontend\Contract\SiteKeyIdentity;
use Tds\Frontend\Contract\SiteKeys;
use Tds\Frontend\Contract\SitePairing;
use Tds\Frontend\Contract\SitePairingDelivery;
use Tds\Frontend\Contract\UserContext;

/** Minimal UserContext double for RBAC tests. */
final class FakeUser implements UserContext
{
    /** @param string[] $permissions */
    public function __construct(
        private readonly bool $auth = false,
        private readonly bool $admin = false,
        private readonly array $permissions = [],
    ) {
    }

    public function isAuthenticated(): bool { return $this->auth; }
    public function userId(): ?int { return $this->auth ? 1 : null; }
    public function email(): ?string { return null; }
    public function isAdmin(): bool { return $this->admin; }
    public function permissions(): array { return $this->permissions; }
    public function has(string $permission): bool { return $this->admin || in_array($permission, $this->permissions, true); }
    public function activeCompanyId(): ?int { return null; }
}

/** A SiteKeys double: one valid plaintext, bound to one site. */
final class FakeSiteKeys implements SiteKeys
{
    public ?string $presentedKey = null;

    public function __construct(
        private readonly string $valid,
        private readonly string $site,
    ) {
    }

    public function verify(string $key, ?string $site = null, ?string $origin = null): ?SiteKeyIdentity
    {
        $this->presentedKey = $key;
        if (!hash_equals($this->valid, $key)) {
            return null;
        }
        if ($site !== null && $site !== $this->site) {
            return null;
        }
        return new SiteKeyIdentity(
            1,
            $this->site,
            $this->site,
            '',
            $this->site,
            $this->site,
            [],
            ['/tools/registry'],
        );
    }

    public function enforcement(): string
    {
        return 'off';
    }
}

/** In-memory tools connection used to test extension wiring without core DB tables. */
final class FakeSiteConnections implements SiteConnections
{
    /** @var list<array<string,mixed>> */
    public array $pairings = [];
    public bool $deleted = false;

    public function __construct(
        public ?SiteConnection $connection = null,
        private readonly bool $delivered = true,
    ) {
    }

    public function get(string $resourceType, string $resourceId): ?SiteConnection
    {
        return $resourceType === 'tools' && $resourceId === 'tools' ? $this->connection : null;
    }

    public function createPairing(
        string $resourceType,
        string $resourceId,
        string $origin,
        string $profile,
        array $bindings = [],
        array $scopes = [],
    ): SitePairing {
        $this->pairings[] = compact('resourceType', 'resourceId', 'origin', 'profile', 'bindings', 'scopes');
        return new SitePairing(
            'pairing-id',
            'pairing-secret',
            $resourceType,
            $resourceId,
            $origin,
            $profile,
            $bindings,
            $scopes,
            '2026-08-27T12:10:00+00:00',
        );
    }

    public function deliverPairing(SitePairing $pairing, string $apiBase): SitePairingDelivery
    {
        return new SitePairingDelivery(
            $this->delivered,
            $this->delivered ? SiteConnection::CONNECTED : SiteConnection::PENDING,
            $this->connection,
            $this->delivered ? null : $pairing->installUrl($apiBase),
            $pairing->expiresAt,
        );
    }

    public function delete(string $resourceType, string $resourceId): bool
    {
        $this->deleted = $resourceType === 'tools' && $resourceId === 'tools';
        if ($this->deleted) {
            $this->connection = null;
        }
        return $this->deleted;
    }
}

/** Records the resource-bound cache request and returns a chosen truthful result. */
final class RecordingConnectedSiteCache implements ConnectedSiteCache
{
    /** @var list<array{resourceType:string,resourceId:string,event:CacheEvent}> */
    public array $calls = [];

    public function __construct(private readonly CacheResult $result)
    {
    }

    public function refresh(string $resourceType, string $resourceId, CacheEvent $event): CacheResult
    {
        $this->calls[] = compact('resourceType', 'resourceId', 'event');
        return $this->result;
    }
}

/**
 * A SiteCache double that records what it was asked to rebuild.
 *
 * Bound under the CONTRACT's FQCN, which is the whole point: the module used to
 * look the interface up in its own namespace, where nothing is ever bound.
 */
final class RecordingSiteCache implements SiteCache
{
    /** @var list<array{baseUrl: string, token: ?string, events: CacheEvent[]}> */
    public array $calls = [];

    public function rebuild(string $baseUrl, ?string $token, array $events): void
    {
        $this->calls[] = ['baseUrl' => $baseUrl, 'token' => $token, 'events' => $events];
    }

    public function isConfigured(string $baseUrl, ?string $token): bool
    {
        return $baseUrl !== '' && $token !== null && $token !== '';
    }
}

final class ToolsModuleTest extends TestCase
{
    private function app(
        UserContext $user,
        ?PDO $pdo = null,
        ?SiteKeys $siteKeys = null,
        ?SiteCache $siteCache = null,
        ?SiteConnections $connections = null,
        ?ConnectedSiteCache $connectedSiteCache = null,
    ) {
        $container = new Container();
        $container->set(UserContext::class, $user);
        if ($pdo !== null) {
            $container->set(PDO::class, $pdo);
        }
        if ($siteKeys !== null) {
            $container->set(SiteKeys::class, $siteKeys);
        }
        if ($siteCache !== null) {
            $container->set(SiteCache::class, $siteCache);
        }
        if ($connections !== null) {
            $container->set(SiteConnections::class, $connections);
        }
        if ($connectedSiteCache !== null) {
            $container->set(ConnectedSiteCache::class, $connectedSiteCache);
        }
        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        (new ModuleRegistry([new ToolsModule()]))->registerAll($app);
        return $app;
    }

    private function request(string $method, string $path, ?array $body = null)
    {
        $req = (new ServerRequestFactory())->createServerRequest($method, $path);
        if ($body !== null) {
            $req = $req->withHeader('Content-Type', 'application/json');
            $req->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));
            $req->getBody()->rewind();
        }
        return $req;
    }

    public function testAdminListRejectsAnonymous(): void
    {
        $app = $this->app(new FakeUser());
        $res = $app->handle($this->request('GET', '/admin/tools'));
        self::assertSame(401, $res->getStatusCode());
    }

    public function testAdminListRejectsNonManager(): void
    {
        $app = $this->app(new FakeUser(auth: true, permissions: ['other:read']));
        $res = $app->handle($this->request('GET', '/admin/tools'));
        self::assertSame(403, $res->getStatusCode());
    }

    public function testConnectionStatusRequiresManagePermission(): void
    {
        $connections = new FakeSiteConnections($this->connectedToolsSite());
        $anonymous = $this->app(new FakeUser(), connections: $connections);
        self::assertSame(401, $anonymous->handle($this->request('GET', '/admin/tools/connection'))->getStatusCode());

        $readOnly = $this->app(new FakeUser(auth: true, permissions: ['other:read']), connections: $connections);
        self::assertSame(403, $readOnly->handle($this->request('GET', '/admin/tools/connection'))->getStatusCode());
    }

    public function testConnectionStatusNeverReturnsSecrets(): void
    {
        $connections = new FakeSiteConnections($this->connectedToolsSite());
        $app = $this->app(new FakeUser(auth: true, admin: true), connections: $connections);
        $res = $app->handle($this->request('GET', '/admin/tools/connection'));

        self::assertSame(200, $res->getStatusCode());
        $body = (string) $res->getBody();
        self::assertStringContainsString('"resource_type":"tools"', $body);
        self::assertStringNotContainsString('pairing_token', strtolower($body));
        self::assertStringNotContainsString('site_key_value', strtolower($body));
        self::assertStringNotContainsString('cache_token', strtolower($body));
    }

    public function testPairingIsBoundToToolsAndOnlyItsThreePublicRoutes(): void
    {
        $connections = new FakeSiteConnections($this->connectedToolsSite());
        $app = $this->app(new FakeUser(auth: true, admin: true), connections: $connections);
        $res = $app->handle($this->request(
            'POST',
            'https://api.tracht-digital.de/admin/tools/connection/pairing',
            ['origin' => 'https://tools.tracht-digital.de', 'profile' => 'ignored'],
        ));

        self::assertSame(201, $res->getStatusCode());
        self::assertCount(1, $connections->pairings);
        self::assertSame('tools', $connections->pairings[0]['resourceType']);
        self::assertSame('tools', $connections->pairings[0]['resourceId']);
        self::assertSame('tools', $connections->pairings[0]['profile']);
        self::assertSame(['tools' => 'tools'], $connections->pairings[0]['bindings']);
        self::assertSame(
            ['/tools/catalog', '/tools/guides', '/tools/registry'],
            $connections->pairings[0]['scopes'],
        );
        self::assertStringNotContainsString('pairing-secret', (string) $res->getBody());
    }

    public function testPairingFallbackKeepsSecretInUrlFragment(): void
    {
        $connections = new FakeSiteConnections(delivered: false);
        $app = $this->app(new FakeUser(auth: true, admin: true), connections: $connections);
        $res = $app->handle($this->request(
            'POST',
            'https://api.tracht-digital.de/admin/tools/connection/pairing',
            ['origin' => 'https://tools.tracht-digital.de'],
        ));
        $body = json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(201, $res->getStatusCode());
        self::assertFalse($body['delivered']);
        self::assertStringStartsWith('https://tools.tracht-digital.de/install#', $body['fallback_url']);
        self::assertStringContainsString('pairing_token=pairing-secret', $body['fallback_url']);
        self::assertStringNotContainsString('?pairing_token=', $body['fallback_url']);
    }

    public function testDisconnectTargetsOnlyToolsResource(): void
    {
        $connections = new FakeSiteConnections($this->connectedToolsSite());
        $app = $this->app(new FakeUser(auth: true, admin: true), connections: $connections);
        $res = $app->handle($this->request('DELETE', '/admin/tools/connection'));

        self::assertSame(200, $res->getStatusCode());
        self::assertTrue($connections->deleted);
        self::assertStringContainsString('"deleted":true', (string) $res->getBody());
    }

    public function testManualCacheReturns503WhenNoConnectionExists(): void
    {
        $app = $this->app(new FakeUser(auth: true, admin: true));
        $res = $app->handle($this->request('POST', '/admin/tools/cache/rebuild', []));

        self::assertSame(503, $res->getStatusCode());
        self::assertStringContainsString('"cache_status":"not_configured"', (string) $res->getBody());
        self::assertStringContainsString('"cached":false', (string) $res->getBody());
    }

    public function testManualCacheReturns202OnlyForCompleteRefresh(): void
    {
        $connections = new FakeSiteConnections($this->connectedToolsSite());
        $cache = new RecordingConnectedSiteCache(new CacheResult(CacheResult::REFRESHED, ['/tools/qr-code/']));
        $app = $this->app(
            new FakeUser(auth: true, admin: true),
            connections: $connections,
            connectedSiteCache: $cache,
        );
        $res = $app->handle($this->request('POST', '/admin/tools/cache/rebuild', ['tool_id' => 'qr-code']));

        self::assertSame(202, $res->getStatusCode());
        self::assertStringContainsString('"cached":true', (string) $res->getBody());
        self::assertCount(1, $cache->calls);
        self::assertSame('tools', $cache->calls[0]['resourceType']);
        self::assertSame('tools', $cache->calls[0]['resourceId']);
        self::assertSame('qr-code', $cache->calls[0]['event']->id);
    }

    public function testManualCacheReturns502ForPartialRefresh(): void
    {
        $connections = new FakeSiteConnections($this->connectedToolsSite());
        $cache = new RecordingConnectedSiteCache(new CacheResult(
            CacheResult::REFRESHED,
            ['/tools/qr-code/'],
            [],
            [['path' => '/tools/qr-code/en/', 'status' => 500]],
        ));
        $app = $this->app(
            new FakeUser(auth: true, admin: true),
            connections: $connections,
            connectedSiteCache: $cache,
        );
        $res = $app->handle($this->request('POST', '/admin/tools/cache/rebuild', ['tool_id' => 'qr-code']));

        self::assertSame(502, $res->getStatusCode());
        self::assertStringContainsString('"cached":false', (string) $res->getBody());
        self::assertStringContainsString('"failed"', (string) $res->getBody());
    }

    public function testRegistrySyncUnconfiguredReturns503(): void
    {
        // Neither a site key nor a registry_token → the endpoint refuses before
        // any DB access.
        $app = $this->app(new FakeUser());
        $res = $app->handle($this->request('POST', '/tools/registry', ['tools' => []]));
        self::assertSame(503, $res->getStatusCode());
    }

    public function testRegistrySyncNamesBothCredentialsWhenUnconfigured(): void
    {
        // The old message sent the operator to Einstellungen → Tools even when
        // the intended fix is a site key. A 503 whose text points at the wrong
        // screen costs more than no text.
        $app = $this->app(new FakeUser());
        $res = $app->handle($this->request('POST', '/tools/registry', ['tools' => []]));
        self::assertStringContainsString('Site-Key', (string) $res->getBody());
    }

    public function testRegistrySyncAcceptsAToolsSiteKey(): void
    {
        $keys = new FakeSiteKeys('tdsk_tools_valid', 'tools');
        $app = $this->app(new FakeUser(), pdo: $this->pdoOrSkip(), siteKeys: $keys);

        $req = $this->request('POST', '/tools/registry', [
            'tools' => [['id' => 'qr-code', 'name' => 'QR', 'category' => 'marketing']],
        ])->withHeader('X-TDS-Site-Key', 'tdsk_tools_valid');
        $res = $app->handle($req);

        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('"synced"', (string) $res->getBody());
        self::assertSame('tdsk_tools_valid', $keys->presentedKey);
    }

    public function testRegistrySyncRejectsAKeyBelongingToAnotherSite(): void
    {
        // The blog's key must not be able to rewrite the tools catalog. This is
        // why the site is passed to verify() instead of trusted from the body.
        $app = $this->app(
            new FakeUser(),
            pdo: $this->pdoOrSkip(),
            siteKeys: new FakeSiteKeys('tdsk_blog_valid', 'blog'),
        );

        $req = $this->request('POST', '/tools/registry', [
            'tools' => [],
        ])->withHeader('X-TDS-Site-Key', 'tdsk_blog_valid');
        $res = $app->handle($req);

        // Falls through to the legacy token path, which is unconfigured here.
        self::assertSame(503, $res->getStatusCode());
    }

    public function testEntitlementRequiresAuth(): void
    {
        $app = $this->app(new FakeUser());
        $res = $app->handle($this->request('GET', '/tools/entitlement?tool=pdf'));
        self::assertSame(401, $res->getStatusCode());
    }

    public function testCheckoutRequiresAuth(): void
    {
        $app = $this->app(new FakeUser());
        $res = $app->handle($this->request('POST', '/tools/checkout', ['tool' => 'pdf']));
        self::assertSame(401, $res->getStatusCode());
    }

    public function testWebhookRejectsMissingSecret(): void
    {
        // No stripe_webhook_secret configured → 503 before any signature check.
        $app = $this->app(new FakeUser());
        $res = $app->handle($this->request('POST', '/tools/stripe-webhook', ['type' => 'checkout.session.completed']));
        self::assertSame(503, $res->getStatusCode());
    }

    public function testWebhookVerifierAcceptsValidRejectsTampered(): void
    {
        $secret = 'whsec_test';
        $payload = '{"type":"checkout.session.completed"}';
        $t = time();
        $sig = hash_hmac('sha256', $t . '.' . $payload, $secret);
        $header = "t={$t},v1={$sig}";

        self::assertTrue(\Tds\Ext\Tools\Service\WebhookVerifier::verify($payload, $header, $secret));
        self::assertFalse(\Tds\Ext\Tools\Service\WebhookVerifier::verify($payload . 'x', $header, $secret));
        self::assertFalse(\Tds\Ext\Tools\Service\WebhookVerifier::verify($payload, $header, 'wrong'));
    }

    private function connectedToolsSite(): SiteConnection
    {
        return new SiteConnection(
            7,
            'tools',
            'tools',
            'https://tools.tracht-digital.de',
            'tools',
            ['tools' => 'tools'],
            ['/tools/catalog', '/tools/guides', '/tools/registry'],
            SiteConnection::CONNECTED,
            42,
            '2026-08-27T12:00:00+00:00',
            '2026-08-27T12:01:00+00:00',
        );
    }

    // --- DB-backed (skipped without a real MariaDB/MySQL, per the repo convention) ---

    private function pdoOrSkip(): PDO
    {
        $dsn = getenv('TDS_TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('Set TDS_TEST_DB_DSN (+ _USER/_PASS) for DB-backed tests.');
        }
        $pdo = new PDO($dsn, (string) getenv('TDS_TEST_DB_USER'), (string) getenv('TDS_TEST_DB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('DROP TABLE IF EXISTS tools_config');
        $pdo->exec(
            'CREATE TABLE tools_config (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                tool_id VARCHAR(80) NOT NULL,
                name VARCHAR(200) NOT NULL,
                category VARCHAR(40) NOT NULL DEFAULT "other",
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                requires_login TINYINT(1) NOT NULL DEFAULT 0,
                is_premium TINYINT(1) NOT NULL DEFAULT 0,
                price_cents INT NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_tool_id (tool_id)
            )',
        );
        return $pdo;
    }

    public function testRegistryUpsertPreservesOverrides(): void
    {
        $pdo = $this->pdoOrSkip();
        $repo = new ToolConfigRepository($pdo);

        $repo->upsertRegistry([
            ['id' => 'qr-code', 'name' => 'QR', 'category' => 'marketing'],
            ['id' => 'pdf', 'name' => 'PDF', 'category' => 'media', 'premium_default' => true, 'price_cents_default' => 500],
        ]);
        self::assertCount(2, $repo->all());

        // Admin disables qr-code + changes the pdf price.
        $repo->updateOverride('qr-code', ['enabled' => false]);
        $repo->updateOverride('pdf', ['price_cents' => 900]);

        // A second sync (e.g. renamed) must NOT clobber the overrides.
        $repo->upsertRegistry([
            ['id' => 'qr-code', 'name' => 'QR-Code-Generator', 'category' => 'marketing'],
            ['id' => 'pdf', 'name' => 'PDF-Werkzeuge', 'category' => 'media', 'premium_default' => true, 'price_cents_default' => 500],
        ]);

        $catalog = [];
        foreach ($repo->publicCatalog() as $row) {
            $catalog[$row['id']] = $row;
        }
        self::assertFalse($catalog['qr-code']['enabled'], 'override enabled=false preserved');
        self::assertTrue($catalog['pdf']['is_premium'], 'premium default applied');
        self::assertSame(900, $catalog['pdf']['price_cents'], 'override price preserved across re-sync');
    }

    // --- The panel-editable guides -----------------------------------------
    //
    // This whole feature shipped broken and stayed broken, because nothing here
    // touched it: four missing `use` statements in ToolsModule.php meant the
    // repository FQCN did not exist (so every guide route fatalled), the
    // fail-soft `catch (Throwable)` matched a class in the wrong namespace (so
    // the public route 500'd instead of returning nothing), and `SiteCache` /
    // `CacheEvent` resolved into the module's own namespace, making
    // `fireCache()` an unconditional no-op.
    //
    // The public site swallows all of it — a non-OK response means "no
    // overrides", and the tool page renders its committed text — so the only
    // way this can be seen is from here. `ClassReferencesTest` pins the general
    // rule; these pin the behaviour an editor actually depends on.

    public function testPublicGuidesFailsSoftWithoutADatabase(): void
    {
        // No PDO is bound, so building the repository must fail — and that
        // failure has to come back as an empty override set, not a 500. Before
        // the `use` fix this did not even reach the catch: the container threw
        // "Class Tds\Ext\Tools\ToolGuideRepository not found" and the
        // mis-namespaced `catch (Throwable)` let it straight through.
        $app = $this->app(new FakeUser());
        $res = $app->handle($this->request('GET', '/tools/guides?lang=de'));

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('{"guides":{}}', (string) $res->getBody());
    }

    public function testPublicGuidesFailsSoftForEnglishToo(): void
    {
        $app = $this->app(new FakeUser());
        $res = $app->handle($this->request('GET', '/tools/guides?lang=en'));

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('{"guides":{}}', (string) $res->getBody());
    }

    public function testAdminGuideListResolvesTheRepository(): void
    {
        // The admin routes have no fail-soft wrapper, so the assertion is
        // narrow on purpose: whatever else happens, it must not be the
        // container failing to find a class that is right there on disk.
        $app = $this->app(new FakeUser(auth: true, admin: true));

        try {
            $res = $app->handle($this->request('GET', '/admin/tools/guides'));
            self::assertNotSame(404, $res->getStatusCode());
        } catch (\Throwable $e) {
            self::assertStringNotContainsString(
                'Tds\Ext\Tools\ToolGuideRepository',
                $e->getMessage(),
                'the repository must resolve under its real namespace (…\Tools\Domain\…)',
            );
        }
    }

    public function testSaveGuideTargetsOnlyItsConnectedToolsSite(): void
    {
        $connections = new FakeSiteConnections($this->connectedToolsSite());
        $cache = new RecordingConnectedSiteCache(new CacheResult(CacheResult::REFRESHED, ['/tools/qr-code/']));
        // `prepare()` is typed `PDOStatement|false`, and an unconfigured mock
        // picks false — which reads as a DB error rather than a stored guide.
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($this->createMock(\PDOStatement::class));
        $app = $this->app(
            new FakeUser(auth: true, admin: true),
            pdo: $pdo,
            connections: $connections,
            connectedSiteCache: $cache,
        );

        $res = $app->handle($this->request('PUT', '/admin/tools/guides/qr-code/de', [
            'intro' => ['Ein Satz.'],
        ]));

        self::assertSame(200, $res->getStatusCode());
        self::assertCount(1, $cache->calls, 'saving a guide must refresh that connected page');
        self::assertSame('tools', $cache->calls[0]['resourceType']);
        self::assertSame('tools', $cache->calls[0]['resourceId']);
        self::assertSame('tool', $cache->calls[0]['event']->type);
        self::assertSame('qr-code', $cache->calls[0]['event']->id);
        self::assertSame('de', $cache->calls[0]['event']->lang);
        self::assertStringContainsString('"cache_status":"refreshed"', (string) $res->getBody());
        self::assertStringContainsString('"cached":true', (string) $res->getBody());
    }

    public function testSaveGuideSucceedsEvenWhenCacheRefreshFails(): void
    {
        $connections = new FakeSiteConnections($this->connectedToolsSite());
        $cache = new RecordingConnectedSiteCache(new CacheResult(
            CacheResult::FAILED,
            [],
            [],
            [['reason' => 'timeout']],
        ));
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($this->createMock(\PDOStatement::class));
        $app = $this->app(
            new FakeUser(auth: true, admin: true),
            pdo: $pdo,
            connections: $connections,
            connectedSiteCache: $cache,
        );

        $res = $app->handle($this->request('PUT', '/admin/tools/guides/qr-code/de', [
            'intro' => ['Der gespeicherte Satz.'],
        ]));

        self::assertSame(200, $res->getStatusCode(), 'cache failure must not roll back or fail content persistence');
        self::assertStringContainsString('"ok":true', (string) $res->getBody());
        self::assertStringContainsString('"cache_status":"failed"', (string) $res->getBody());
        self::assertStringContainsString('"cached":false', (string) $res->getBody());
    }
}
