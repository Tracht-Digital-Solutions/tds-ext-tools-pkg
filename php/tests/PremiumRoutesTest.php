<?php
declare(strict_types=1);

namespace Tds\Ext\Tools\Tests;

use DI\Container;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\Ext\Tools\ToolsModule;
use Tds\Frontend\Contract\ModuleRegistry;
use Tds\Frontend\Contract\Stripe\StripeApi;
use Tds\Frontend\Contract\UserContext;

/** Records what the module asks Stripe for and answers with a fixed URL. */
final class RecordingStripeApi implements StripeApi
{
    /** @var list<array{path:string, params:array<string,mixed>}> */
    public array $calls = [];

    public function isConfigured(): bool { return true; }
    public function mode(): ?string { return 'test'; }
    public function post(string $path, array $params = [], ?string $idempotencyKey = null): array
    {
        $this->calls[] = ['path' => $path, 'params' => $params];
        return ['id' => 'cs_test_1', 'url' => 'https://stripe.example' . $path];
    }
    public function get(string $path, array $query = []): array { return []; }
}

/** A signed-in user with an id and an address of its own. */
final class PremiumUser implements UserContext
{
    public function __construct(private readonly int $id = 5, private readonly bool $admin = false, private readonly array $permissions = [])
    {
    }
    public function isAuthenticated(): bool { return true; }
    public function userId(): ?int { return $this->id; }
    public function email(): ?string { return 'kunde@example.de'; }
    public function isAdmin(): bool { return $this->admin; }
    public function permissions(): array { return $this->permissions; }
    public function has(string $permission): bool { return $this->admin || in_array($permission, $this->permissions, true); }
    public function activeCompanyId(): ?int { return null; }
}

/**
 * Premium as a monthly subscription, through the real routes on SQLite:
 * stages managed by the admin, prices from the stage (never the request),
 * access only while Stripe counts the subscription as paying, old one-off
 * purchases still honoured, one subscription per account, and the webhook as
 * the only writer.
 */
final class PremiumRoutesTest extends TestCase
{
    private PDO $pdo;
    private RecordingStripeApi $stripe;
    private const SECRET = 'whsec_test';

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->sqliteCreateFunction('NOW', static fn (): string => gmdate('Y-m-d H:i:s'), 0);
        $this->pdo->exec('CREATE TABLE tools_config (tool_id TEXT PRIMARY KEY, name TEXT, category TEXT, enabled INTEGER, requires_login INTEGER, is_premium INTEGER, price_cents INTEGER, sort_order INTEGER, blog_slug TEXT)');
        $this->pdo->exec('CREATE TABLE tools_entitlement (id INTEGER PRIMARY KEY AUTOINCREMENT, app_user_id INTEGER, tool_id TEXT, status TEXT DEFAULT \'active\', source_stripe_id TEXT, expires_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(app_user_id, tool_id))');
        $this->pdo->exec('CREATE TABLE tools_tier (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT UNIQUE, name TEXT, name_en TEXT, tagline TEXT, tagline_en TEXT, features TEXT, features_en TEXT, price_cents INTEGER, tool_ids TEXT, active INTEGER DEFAULT 1, highlight INTEGER DEFAULT 0, sort_order INTEGER DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $this->pdo->exec('CREATE TABLE tools_subscription (id INTEGER PRIMARY KEY AUTOINCREMENT, app_user_id INTEGER, tier_id INTEGER, stripe_subscription_id TEXT UNIQUE, stripe_customer_id TEXT, status TEXT, current_period_end TEXT, cancel_at_period_end INTEGER DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $this->pdo->exec("INSERT INTO tools_config (tool_id, name, category, enabled, requires_login, is_premium, price_cents, sort_order) VALUES ('pdf-komprimieren', 'PDF komprimieren', 'media', 1, 1, 1, 500, 0)");
        $this->stripe = new RecordingStripeApi();
        putenv('STRIPE_WEBHOOK_SECRET=' . self::SECRET);
    }

    protected function tearDown(): void
    {
        putenv('STRIPE_WEBHOOK_SECRET');
    }

    private function call(UserContext $user, string $method, string $path, ?array $body = null, array $headers = [], ?string $raw = null): array
    {
        $container = new Container();
        $container->set(UserContext::class, $user);
        $container->set(PDO::class, $this->pdo);
        $container->set(StripeApi::class, $this->stripe);
        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        (new ModuleRegistry([new ToolsModule()]))->registerAll($app);

        $req = (new ServerRequestFactory())->createServerRequest($method, $path);
        foreach ($headers as $k => $v) {
            $req = $req->withHeader($k, $v);
        }
        if ($body !== null || $raw !== null) {
            $req = $req->withHeader('Content-Type', 'application/json');
            $req->getBody()->write($raw ?? json_encode($body, JSON_THROW_ON_ERROR));
            $req->getBody()->rewind();
        }
        /** @var ResponseInterface $res */
        $res = $app->handle($req);
        return [$res->getStatusCode(), json_decode((string) $res->getBody(), true)];
    }

    private function manager(): UserContext
    {
        return new PremiumUser(99, false, ['tools:manage']);
    }

    private function stage(array $over = []): array
    {
        return array_merge([
            'name' => 'Büro Plus',
            'price_cents' => 990,
            'tool_ids' => ['pdf-komprimieren'],
            'tagline' => 'Alle PDF-Werkzeuge ohne Grenzen.',
            'features' => "Unbegrenzt komprimieren\nOhne Upload",
        ], $over);
    }

    private function webhook(array $event): array
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $t = time();
        $sig = hash_hmac('sha256', $t . '.' . $payload, self::SECRET);
        return $this->call(new FakeUser(), 'POST', '/tools/stripe-webhook', null, ['Stripe-Signature' => "t={$t},v1={$sig}"], $payload);
    }

    private function subscriptionEvent(string $type, string $status, int $userId = 5, int $tierId = 1, array $extra = []): array
    {
        return ['type' => $type, 'data' => ['object' => array_merge([
            'id' => 'sub_1',
            'customer' => 'cus_1',
            'status' => $status,
            'cancel_at_period_end' => false,
            'items' => ['data' => [['current_period_end' => 1767225600]]],
            'metadata' => ['module' => 'tools', 'user_id' => (string) $userId, 'tier_id' => (string) $tierId],
        ], $extra)]];
    }

    public function testTheAdminManagesStagesAndTheSiteSeesOnlyActiveOnes(): void
    {
        self::assertSame(403, $this->call(new PremiumUser(), 'POST', '/admin/tools/tiers', $this->stage())[0]);

        [$status, $body] = $this->call($this->manager(), 'POST', '/admin/tools/tiers', $this->stage());
        self::assertSame(201, $status);
        self::assertSame('buero-plus', $body['tier']['slug']);
        self::assertSame(['Unbegrenzt komprimieren', 'Ohne Upload'], $body['tier']['features']);
        self::assertSame(409, $this->call($this->manager(), 'POST', '/admin/tools/tiers', $this->stage())[0], 'slug taken');

        $this->call($this->manager(), 'POST', '/admin/tools/tiers', $this->stage(['name' => 'Alt', 'active' => false]));
        [, $public] = $this->call(new FakeUser(), 'GET', '/tools/tiers');
        self::assertCount(1, $public['tiers']);
        self::assertSame(990, $public['tiers'][0]['price_cents']);
        self::assertSame('month', $public['tiers'][0]['interval']);
        self::assertSame('EUR', $public['currency']);

        [$status] = $this->call($this->manager(), 'PUT', '/admin/tools/tiers/1', $this->stage(['price_cents' => 1290]));
        self::assertSame(200, $status);
        [, $admin] = $this->call($this->manager(), 'GET', '/admin/tools/tiers');
        $plus = array_values(array_filter($admin['tiers'], static fn (array $t): bool => $t['slug'] === 'buero-plus'))[0];
        self::assertSame(1290, $plus['price_cents']);
        self::assertSame(0, $plus['subscribers']);
        self::assertCount(2, $admin['tiers'], 'the admin sees inactive stages too');
    }

    public function testStagePayloadsAreValidated(): void
    {
        foreach ([
            ['name' => ''],
            ['price_cents' => 0],
            ['price_cents' => '9,90'],
            ['tool_ids' => []],
            ['slug' => 'Nicht Gültig'],
        ] as $bad) {
            self::assertSame(422, $this->call($this->manager(), 'POST', '/admin/tools/tiers', $this->stage($bad))[0], json_encode($bad));
        }
    }

    public function testSubscribingUsesTheStagePriceNeverTheRequest(): void
    {
        $this->call($this->manager(), 'POST', '/admin/tools/tiers', $this->stage());
        self::assertSame(401, $this->call(new FakeUser(), 'POST', '/tools/subscribe', ['tier' => 'buero-plus'])[0]);
        self::assertSame(404, $this->call(new PremiumUser(), 'POST', '/tools/subscribe', ['tier' => 'gibt-es-nicht'])[0]);

        [$status, $body] = $this->call(new PremiumUser(), 'POST', '/tools/subscribe', ['tier' => 'buero-plus', 'price_cents' => 1]);
        self::assertSame(201, $status);
        self::assertSame('https://stripe.example/checkout/sessions', $body['url']);
        $params = $this->stripe->calls[0]['params'];
        self::assertSame('subscription', $params['mode']);
        self::assertSame(990, $params['line_items'][0]['price_data']['unit_amount']);
        self::assertSame(['interval' => 'month'], $params['line_items'][0]['price_data']['recurring']);
        self::assertSame(['module' => 'tools', 'user_id' => '5', 'tier_id' => '1'], $params['subscription_data']['metadata']);
        self::assertSame('kunde@example.de', $params['customer_email']);
    }

    public function testCheckoutOfAToolOpensItsCheapestStage(): void
    {
        $this->call($this->manager(), 'POST', '/admin/tools/tiers', $this->stage(['name' => 'Gross', 'price_cents' => 2900]));
        $this->call($this->manager(), 'POST', '/admin/tools/tiers', $this->stage(['name' => 'Klein', 'price_cents' => 490]));

        self::assertSame(201, $this->call(new PremiumUser(), 'POST', '/tools/checkout', ['tool' => 'pdf-komprimieren'])[0]);
        self::assertSame(490, $this->stripe->calls[0]['params']['line_items'][0]['price_data']['unit_amount']);
    }

    public function testOnlyAPayingSubscriptionOpensTheTools(): void
    {
        $this->call($this->manager(), 'POST', '/admin/tools/tiers', $this->stage());
        $user = new PremiumUser();
        $entitled = fn (): bool => $this->call($user, 'GET', '/tools/entitlement?tool=pdf-komprimieren')[1]['entitled'];

        // The finished checkout alone grants nothing: its first invoice may still fail.
        [$status] = $this->webhook(['type' => 'checkout.session.completed', 'data' => ['object' => [
            'mode' => 'subscription', 'subscription' => 'sub_1', 'customer' => 'cus_1', 'client_reference_id' => '5',
            'metadata' => ['module' => 'tools', 'user_id' => '5', 'tier_id' => '1'],
        ]]]);
        self::assertSame(200, $status);
        self::assertFalse($entitled());

        $this->webhook($this->subscriptionEvent('customer.subscription.updated', 'active'));
        self::assertTrue($entitled());
        [, $st] = $this->call($user, 'GET', '/tools/premium/status');
        self::assertTrue($st['premium']);
        self::assertSame('buero-plus', $st['subscriptions'][0]['tier']);
        self::assertSame('2026-01-01T00:00:00Z', $st['subscriptions'][0]['current_period_end']);
        self::assertSame(['pdf-komprimieren'], $st['tools']);

        // A declined renewal keeps a few days of grace; the end closes it.
        $this->webhook($this->subscriptionEvent('customer.subscription.updated', 'past_due'));
        self::assertTrue($entitled());
        $this->webhook($this->subscriptionEvent('customer.subscription.deleted', 'canceled'));
        self::assertFalse($entitled());
        self::assertFalse($this->call($user, 'GET', '/tools/premium/status')[1]['premium']);
        // Never anyone else's tools.
        self::assertFalse($this->call(new PremiumUser(6), 'GET', '/tools/entitlement?tool=pdf-komprimieren')[1]['entitled']);
    }

    public function testForeignSubscriptionsOnTheSharedAccountAreIgnored(): void
    {
        $event = $this->subscriptionEvent('customer.subscription.updated', 'active');
        $event['data']['object']['metadata']['module'] = 'billing';
        $this->webhook($event);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM tools_subscription')->fetchColumn());
    }

    public function testOneRunningSubscriptionPerAccountAndThePortalForIt(): void
    {
        $this->call($this->manager(), 'POST', '/admin/tools/tiers', $this->stage());
        [$status, $body] = $this->call(new PremiumUser(), 'POST', '/tools/billing-portal');
        self::assertSame(404, $status);
        self::assertSame('no_subscription', $body['code']);

        $this->webhook($this->subscriptionEvent('customer.subscription.created', 'active'));
        [$status, $body] = $this->call(new PremiumUser(), 'POST', '/tools/subscribe', ['tier' => 'buero-plus']);
        self::assertSame(409, $status);
        self::assertSame('has_subscription', $body['code']);

        [$status, $body] = $this->call(new PremiumUser(), 'POST', '/tools/billing-portal');
        self::assertSame(201, $status);
        self::assertSame('/billing_portal/sessions', $this->stripe->calls[0]['path']);
        self::assertSame('cus_1', $this->stripe->calls[0]['params']['customer']);

        // A stage with subscriptions is deactivated, never deleted.
        [$status, $body] = $this->call($this->manager(), 'DELETE', '/admin/tools/tiers/1');
        self::assertSame(409, $status);
        self::assertSame('has_subscriptions', $body['code']);
    }

    public function testOneOffPurchasesStayValidButDoNotMakeTheAccountPremium(): void
    {
        $this->pdo->exec("INSERT INTO tools_entitlement (app_user_id, tool_id, status) VALUES (5, 'pdf-komprimieren', 'active')");
        self::assertTrue($this->call(new PremiumUser(), 'GET', '/tools/entitlement?tool=pdf-komprimieren')[1]['entitled']);
        self::assertFalse($this->call(new PremiumUser(), 'GET', '/tools/premium/status')[1]['premium']);
        self::assertFalse($this->call(new FakeUser(), 'GET', '/tools/premium/status')[1]['authenticated']);
    }

    public function testTheRatgeberArticleIsAnOverrideTheSiteReads(): void
    {
        [$status] = $this->call($this->manager(), 'PUT', '/admin/tools/pdf-komprimieren', ['blog_slug' => 'pdf-verkleinern']);
        self::assertSame(200, $status);
        [, $catalog] = $this->call(new FakeUser(), 'GET', '/tools/catalog');
        self::assertSame('pdf-verkleinern', $catalog['tools'][0]['blog_slug']);

        $this->call($this->manager(), 'PUT', '/admin/tools/pdf-komprimieren', ['blog_slug' => '../../etc']);
        [, $catalog] = $this->call(new FakeUser(), 'GET', '/tools/catalog');
        self::assertNull($catalog['tools'][0]['blog_slug'], 'anything but a slug unlinks');
    }

    public function testWithoutTheNewTablesEverythingStaysClosed(): void
    {
        $this->pdo->exec('DROP TABLE tools_tier');
        $this->pdo->exec('DROP TABLE tools_subscription');
        self::assertSame([], $this->call(new FakeUser(), 'GET', '/tools/tiers')[1]['tiers']);
        self::assertFalse($this->call(new PremiumUser(), 'GET', '/tools/entitlement?tool=pdf-komprimieren')[1]['entitled']);
        self::assertFalse($this->call(new PremiumUser(), 'GET', '/tools/premium/status')[1]['premium']);
    }
}
