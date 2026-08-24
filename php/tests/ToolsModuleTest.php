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
use Tds\Frontend\Contract\ModuleRegistry;
use Tds\Frontend\Contract\SiteCache;
use Tds\Frontend\Contract\SiteKeyIdentity;
use Tds\Frontend\Contract\SiteKeys;
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
    /** The site the caller demanded — asserted, because trusting the body is the bug. */
    public ?string $demandedSite = null;

    public function __construct(
        private readonly string $valid,
        private readonly string $site,
    ) {
    }

    public function verify(string $key, ?string $site = null, ?string $origin = null): ?SiteKeyIdentity
    {
        $this->demandedSite = $site;
        if (!hash_equals($this->valid, $key)) {
            return null;
        }
        if ($site !== null && $site !== $this->site) {
            return null;
        }
        return new SiteKeyIdentity(1, $this->site, $this->site, '');
    }

    public function enforcement(): string
    {
        return 'off';
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

        $res = $app->handle($this->request('POST', '/tools/registry', [
            'key' => 'tdsk_tools_valid',
            'tools' => [['id' => 'qr-code', 'name' => 'QR', 'category' => 'marketing']],
        ]));

        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('"synced"', (string) $res->getBody());
        self::assertSame('tools', $keys->demandedSite, 'the site must be demanded, not read from the body');
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

        $res = $app->handle($this->request('POST', '/tools/registry', [
            'key' => 'tdsk_blog_valid',
            'site' => 'tools',
            'tools' => [],
        ]));

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

    public function testSaveGuideAsksTheSiteToRebuildThatPage(): void
    {
        // `fireCache()` looked SiteCache up under the module's own namespace,
        // where `$c->has()` is always false — so an editor's save answered
        // {"ok":true} and the public page kept serving the old render forever.
        // A mocked PDO, not pdoOrSkip(): this asserts a wiring fact, not a
        // storage fact, and a test that skips wherever no MariaDB is running
        // would have gated exactly nothing — which is how the bug survived.
        $cache = new RecordingSiteCache();
        // `prepare()` is typed `PDOStatement|false`, and an unconfigured mock
        // picks false — which reads as a DB error rather than a stored guide.
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($this->createMock(\PDOStatement::class));
        $app = $this->app(new FakeUser(auth: true, admin: true), pdo: $pdo, siteCache: $cache);

        $res = $app->handle($this->request('PUT', '/admin/tools/guides/qr-code/de', [
            'intro' => ['Ein Satz.'],
        ]));

        self::assertSame(200, $res->getStatusCode());
        self::assertCount(1, $cache->calls, 'saving a guide must ask the site to rebuild that page');
        self::assertSame('tool', $cache->calls[0]['events'][0]->type);
        self::assertSame('qr-code', $cache->calls[0]['events'][0]->id);
        self::assertSame('de', $cache->calls[0]['events'][0]->lang);
    }
}
