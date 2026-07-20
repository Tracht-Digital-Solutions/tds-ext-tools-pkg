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
use Tds\Panel\Contract\ModuleRegistry;
use Tds\Panel\Contract\UserContext;

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

final class ToolsModuleTest extends TestCase
{
    private function app(UserContext $user, ?PDO $pdo = null)
    {
        $container = new Container();
        $container->set(UserContext::class, $user);
        if ($pdo !== null) {
            $container->set(PDO::class, $pdo);
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
        // No registry_token in settings/env → the endpoint refuses before any DB access.
        $app = $this->app(new FakeUser());
        $res = $app->handle($this->request('POST', '/tools/registry', ['tools' => []]));
        self::assertSame(503, $res->getStatusCode());
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
}
