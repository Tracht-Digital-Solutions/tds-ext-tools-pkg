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
use Tds\Frontend\Contract\UserContext;

/**
 * The studio's server-side guarantees, through the real routes: guests get an
 * answer but no storage, only the entitled user stores, a project never
 * crosses users, the admin's switches act without a code change, and the
 * analysis refuses bad targets before any network access.
 *
 * Runs on in-memory SQLite with the module's tables recreated in portable SQL,
 * so it gates CI without a database server.
 */
final class StudioRoutesTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->sqliteCreateFunction('NOW', static fn (): string => gmdate('Y-m-d H:i:s'), 0);
        $this->pdo->exec('CREATE TABLE tools_config (tool_id TEXT PRIMARY KEY, name TEXT, category TEXT, enabled INTEGER, requires_login INTEGER, is_premium INTEGER, price_cents INTEGER, sort_order INTEGER)');
        $this->pdo->exec('CREATE TABLE tools_entitlement (id INTEGER PRIMARY KEY AUTOINCREMENT, app_user_id INTEGER, tool_id TEXT, status TEXT DEFAULT \'active\', source_stripe_id TEXT, expires_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(app_user_id, tool_id))');
        $this->pdo->exec('CREATE TABLE tools_studio_project (id INTEGER PRIMARY KEY AUTOINCREMENT, app_user_id INTEGER NOT NULL, name TEXT NOT NULL, schema_version INTEGER NOT NULL DEFAULT 1, data TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        foreach (['TOOLS_STUDIO_PROJECT_LIMIT', 'TOOLS_STUDIO_ANALYSIS', 'TOOLS_STUDIO_PREMIUM_FORMATS', 'TOOLS_STUDIO_PRICE_CENTS', 'STRIPE_SECRET_KEY'] as $k) {
            putenv($k);
        }
    }

    protected function tearDown(): void
    {
        foreach (['TOOLS_STUDIO_PROJECT_LIMIT', 'TOOLS_STUDIO_ANALYSIS', 'TOOLS_STUDIO_PREMIUM_FORMATS', 'TOOLS_STUDIO_PRICE_CENTS', 'STRIPE_SECRET_KEY'] as $k) {
            putenv($k);
        }
    }

    private function app(UserContext $user)
    {
        $container = new Container();
        $container->set(UserContext::class, $user);
        $container->set(PDO::class, $this->pdo);
        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        (new ModuleRegistry([new ToolsModule()]))->registerAll($app);
        return $app;
    }

    private function call(UserContext $user, string $method, string $path, ?array $body = null): array
    {
        $req = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '203.0.113.9']);
        if ($body !== null) {
            $req = $req->withHeader('Content-Type', 'application/json');
            $req->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));
            $req->getBody()->rewind();
        }
        /** @var ResponseInterface $res */
        $res = $this->app($user)->handle($req);
        return [$res->getStatusCode(), json_decode((string) $res->getBody(), true)];
    }

    private function entitle(int $userId = 1): void
    {
        $this->pdo->exec("INSERT INTO tools_entitlement (app_user_id, tool_id, status) VALUES ({$userId}, 'design-system-studio', 'active')");
    }

    private function project(string $name = 'Marke'): array
    {
        return ['name' => $name, 'data' => ['schema' => 1, 'name' => $name, 'prefix' => 'ds', 'seed' => ['kind' => 'button']]];
    }

    public function testGuestsGetAccessInfoButNoPremium(): void
    {
        [$status, $body] = $this->call(new FakeUser(), 'GET', '/tools/studio/access');
        self::assertSame(200, $status);
        self::assertTrue($body['enabled']);
        self::assertFalse($body['authenticated']);
        self::assertFalse($body['entitled']);
        self::assertSame('free', $body['formats']['css']);
        self::assertSame('premium', $body['formats']['zip']);
        self::assertSame(900, $body['priceCents']);
    }

    public function testGuestsAndNonPremiumUsersCannotStore(): void
    {
        self::assertSame(401, $this->call(new FakeUser(), 'POST', '/tools/studio/projects', $this->project())[0]);
        [$status, $body] = $this->call(new FakeUser(auth: true), 'POST', '/tools/studio/projects', $this->project());
        self::assertSame(403, $status);
        self::assertSame('premium_required', $body['code']);
        self::assertSame(403, $this->call(new FakeUser(auth: true), 'GET', '/tools/studio/projects')[0]);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM tools_studio_project')->fetchColumn());
    }

    public function testEntitledUsersSaveOpenUpdateAndDelete(): void
    {
        $this->entitle();
        $user = new FakeUser(auth: true);
        [$status, $body] = $this->call($user, 'POST', '/tools/studio/projects', $this->project('Bäckerei'));
        self::assertSame(201, $status);
        $id = $body['project']['id'];
        self::assertSame('Bäckerei', $body['project']['name']);

        [, $list] = $this->call($user, 'GET', '/tools/studio/projects');
        self::assertCount(1, $list['projects']);

        [$status, $one] = $this->call($user, 'GET', "/tools/studio/projects/{$id}");
        self::assertSame(200, $status);
        self::assertSame(1, $one['project']['data']['schema']);
        self::assertSame('button', $one['project']['data']['seed']['kind']);

        $changed = $this->project('Bäckerei 2');
        $changed['data']['prefix'] = 'bk';
        [$status, $upd] = $this->call($user, 'PUT', "/tools/studio/projects/{$id}", $changed);
        self::assertSame(200, $status);
        self::assertSame('Bäckerei 2', $upd['project']['name']);
        self::assertSame('bk', $this->call($user, 'GET', "/tools/studio/projects/{$id}")[1]['project']['data']['prefix']);

        self::assertSame(200, $this->call($user, 'DELETE', "/tools/studio/projects/{$id}")[0]);
        self::assertSame(404, $this->call($user, 'GET', "/tools/studio/projects/{$id}")[0]);
    }

    public function testAProjectNeverCrossesUsers(): void
    {
        $this->entitle();
        $this->pdo->exec("INSERT INTO tools_studio_project (app_user_id, name, data) VALUES (2, 'Fremd', '{\"schema\":1}')");
        $foreign = (int) $this->pdo->lastInsertId();
        $user = new FakeUser(auth: true);
        self::assertSame(404, $this->call($user, 'GET', "/tools/studio/projects/{$foreign}")[0]);
        self::assertSame(404, $this->call($user, 'PUT', "/tools/studio/projects/{$foreign}", $this->project())[0]);
        self::assertSame(404, $this->call($user, 'DELETE', "/tools/studio/projects/{$foreign}")[0]);
        self::assertSame([], $this->call($user, 'GET', '/tools/studio/projects')[1]['projects']);
        self::assertSame('Fremd', $this->pdo->query("SELECT name FROM tools_studio_project WHERE id = {$foreign}")->fetchColumn());
    }

    public function testRejectsInvalidProjectsAndEnforcesTheLimit(): void
    {
        $this->entitle();
        $user = new FakeUser(auth: true);
        self::assertSame(422, $this->call($user, 'POST', '/tools/studio/projects', ['name' => 'x', 'data' => ['no' => 'schema']])[0]);
        self::assertSame(422, $this->call($user, 'POST', '/tools/studio/projects', ['name' => 'x', 'data' => [1, 2, 3]])[0]);
        self::assertSame(422, $this->call($user, 'POST', '/tools/studio/projects', ['name' => '   ', 'data' => ['schema' => 1]])[0]);
        $huge = $this->project();
        $huge['data']['customCss'] = str_repeat('a', 600_000);
        self::assertSame(422, $this->call($user, 'POST', '/tools/studio/projects', $huge)[0]);

        putenv('TOOLS_STUDIO_PROJECT_LIMIT=1');
        self::assertSame(201, $this->call($user, 'POST', '/tools/studio/projects', $this->project())[0]);
        [$status, $body] = $this->call($user, 'POST', '/tools/studio/projects', $this->project());
        self::assertSame(409, $status);
        self::assertSame('limit', $body['code']);
    }

    public function testAdminsAreEntitledWithoutAPurchase(): void
    {
        [, $body] = $this->call(new FakeUser(auth: true, admin: true), 'GET', '/tools/studio/access');
        self::assertTrue($body['entitled']);
        self::assertSame(201, $this->call(new FakeUser(auth: true, admin: true), 'POST', '/tools/studio/projects', $this->project())[0]);
    }

    public function testAdminSwitchesActWithoutACodeChange(): void
    {
        putenv('TOOLS_STUDIO_PREMIUM_FORMATS=css,json');
        putenv('TOOLS_STUDIO_PRICE_CENTS=1490');
        [, $body] = $this->call(new FakeUser(), 'GET', '/tools/studio/access');
        self::assertSame('premium', $body['formats']['css']);
        self::assertSame('free', $body['formats']['zip']);
        self::assertSame(1490, $body['priceCents']);

        // The catalog switch turns the studio off — for access, storage and analysis.
        $this->pdo->exec("INSERT INTO tools_config VALUES ('design-system-studio', 'Designsystem-Studio', 'design', 0, 0, 0, 0, 0)");
        $this->entitle();
        self::assertFalse($this->call(new FakeUser(), 'GET', '/tools/studio/access')[1]['enabled']);
        [$status, $body] = $this->call(new FakeUser(auth: true), 'GET', '/tools/studio/projects');
        self::assertSame(403, $status);
        self::assertSame('disabled', $body['code']);
        self::assertSame(403, $this->call(new FakeUser(), 'POST', '/tools/studio/analyze', ['url' => 'https://example.org/'])[0]);
    }

    public function testAnalysisRefusesBadTargetsBeforeAnyNetworkAccess(): void
    {
        foreach (['http://example.org/' => 'invalid_url', 'https://127.0.0.1/' => 'blocked_host', 'https://169.254.169.254/' => 'blocked_host', 'https://localhost/' => 'blocked_host'] as $url => $code) {
            [$status, $body] = $this->call(new FakeUser(), 'POST', '/tools/studio/analyze', ['url' => $url]);
            self::assertSame(422, $status, $url);
            self::assertSame($code, $body['code'], $url);
        }
        putenv('TOOLS_STUDIO_ANALYSIS=0');
        [$status, $body] = $this->call(new FakeUser(), 'POST', '/tools/studio/analyze', ['url' => 'https://example.org/']);
        self::assertSame(403, $status);
        self::assertSame('disabled', $body['code']);
    }

    public function testStudioPremiumCanBeBoughtAlthoughTheToolIsFree(): void
    {
        $this->pdo->exec("INSERT INTO tools_config VALUES ('design-system-studio', 'Designsystem-Studio', 'design', 1, 0, 0, 0, 0)");
        $this->pdo->exec("INSERT INTO tools_config VALUES ('json-formatter', 'JSON', 'developer', 1, 0, 0, 0, 0)");
        // Not a "free tool" refusal: it reaches the payment step (unconfigured here → 503).
        self::assertSame(503, $this->call(new FakeUser(auth: true), 'POST', '/tools/checkout', ['tool' => 'design-system-studio'])[0]);
        self::assertSame(400, $this->call(new FakeUser(auth: true), 'POST', '/tools/checkout', ['tool' => 'json-formatter'])[0]);
        $this->entitle();
        self::assertSame(409, $this->call(new FakeUser(auth: true), 'POST', '/tools/checkout', ['tool' => 'design-system-studio'])[0]);
    }
}
