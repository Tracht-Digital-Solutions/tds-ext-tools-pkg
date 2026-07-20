<?php
declare(strict_types=1);

namespace Tds\Ext\Tools;

use PDO;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Tds\Ext\Tools\Domain\ToolConfigRepository;
use Tds\Ext\Tools\Service\RebuildTrigger;
use Tds\Panel\Contract\AbstractModule;
use Tds\Panel\Contract\PermissionDef;
use Tds\Panel\Contract\SettingDef;
use Tds\Panel\Contract\SettingsStore;
use Tds\Panel\Contract\UserContext;

/**
 * Backend Module for the public tools platform (tds-tools).
 *
 * Owns the tool catalog config: which tools are enabled, require login, are
 * premium (+ price), and the AdSense config. The tool *list* is owned by the
 * frontend packs and flows in via the token-gated registry sync
 * (`POST /tools/registry`, called by the site build). The public site reads the
 * merged catalog from `GET /tools/catalog` (unauthenticated). Admins manage the
 * overrides via `/admin/tools`; a change fires a rebuild of the static site.
 *
 * Auth via the core {@see UserContext}: admin routes need `tools:manage` (admins
 * bypass); the catalog GET is public; the registry POST is token-gated. Config
 * (AdSense, rebuild, registry token) via the core {@see SettingsStore} (ns=tools),
 * DB-first with env fallback.
 */
final class ToolsModule extends AbstractModule
{
    private const NS = 'tools';

    public function id(): string
    {
        return 'tools';
    }

    /** @return PermissionDef[] */
    public function permissions(): array
    {
        return [new PermissionDef('tools:manage', 'Tools verwalten', 'tools')];
    }

    /** @return string[] */
    public function migrations(): array
    {
        return [__DIR__ . '/../db/migrations'];
    }

    /** @return SettingDef[] */
    public function settings(): array
    {
        return [
            new SettingDef('ads_enabled', 'AdSense aktiv (1/0)', false, 'tools', '0'),
            new SettingDef('adsense_publisher_id', 'AdSense Publisher-ID (ca-pub-…)', false, 'tools'),
            new SettingDef('adsense_slot_catalog', 'AdSense Slot (Übersicht)', false, 'tools'),
            new SettingDef('adsense_slot_tool', 'AdSense Slot (Tool-Seite)', false, 'tools'),
            new SettingDef('registry_token', 'Registry-Sync-Token', true, 'tools'),
            new SettingDef('rebuild_repo', 'Rebuild-Repo (owner/name)', false, 'tools', 'Tracht-Digital-Solutions/tds-tools'),
            new SettingDef('rebuild_workflow', 'Rebuild-Workflow', false, 'tools', 'dev.yml'),
            new SettingDef('rebuild_token', 'Rebuild-Token (GitHub PAT)', true, 'tools'),
        ];
    }

    public function register(App $app): void
    {
        $c = $app->getContainer();
        if ($c !== null && !$c->has(ToolConfigRepository::class)) {
            $c->set(ToolConfigRepository::class, static fn ($c) => new ToolConfigRepository($c->get(PDO::class)));
        }

        // --- Public: the catalog the static site bakes at build time ----------
        $app->get('/tools/catalog', function (Request $req, Response $res) use ($c): Response {
            $repo = $c->get(ToolConfigRepository::class);
            return self::json($res, [
                'tools' => $repo->publicCatalog(),
                'ads' => self::adsConfig($c),
            ]);
        });

        // --- Registry sync (token-gated; the site build upserts its packs) ----
        $app->post('/tools/registry', function (Request $req, Response $res) use ($c): Response {
            $configured = self::store($c)?->getSecret(self::NS, 'registry_token');
            if ($configured === null || $configured === '') {
                $configured = self::env('TOOLS_REGISTRY_TOKEN', '');
            }
            if ($configured === '') {
                return self::json($res, ['error' => 'Registry sync not configured'], 503);
            }
            $body = (array) $req->getParsedBody();
            $provided = (string) ($body['token'] ?? self::bearer($req));
            if (!hash_equals($configured, $provided)) {
                return self::json($res, ['error' => 'Unauthorized'], 401);
            }
            $tools = is_array($body['tools'] ?? null) ? $body['tools'] : [];
            $n = $c->get(ToolConfigRepository::class)->upsertRegistry($tools);
            return self::json($res, ['ok' => true, 'synced' => $n]);
        });

        // --- Admin: manage the catalog overrides ------------------------------
        $app->get('/admin/tools', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireManage($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            return self::json($res, ['tools' => $c->get(ToolConfigRepository::class)->all()]);
        });

        $app->put('/admin/tools/{id}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::requireManage($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $body = (array) $req->getParsedBody();
            $updated = $c->get(ToolConfigRepository::class)->updateOverride((string) $args['id'], $body);
            if (!$updated) {
                return self::json($res, ['error' => 'Not found or nothing to update'], 404);
            }
            self::fireRebuild($c, 'tool-config-change');
            return self::json($res, ['ok' => true]);
        });

        $app->post('/admin/tools/rebuild', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireManage($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            self::fireRebuild($c, 'manual-rebuild');
            return self::json($res, ['ok' => true]);
        });

        // --- Dashboard widget summary (admin) ---------------------------------
        $app->get('/tools/summary', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireManage($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $counts = $c->get(ToolConfigRepository::class)->counts();
            $counts['ads'] = self::adsConfig($c)['enabled'];
            return self::json($res, $counts);
        });
    }

    // --- helpers ---------------------------------------------------------------

    /** @return array{enabled:bool,publisherId:string,slotCatalog:string,slotTool:string} */
    private static function adsConfig(ContainerInterface $c): array
    {
        $publisher = self::setting($c, 'adsense_publisher_id', 'ADSENSE_PUBLISHER_ID', '');
        $enabled = self::setting($c, 'ads_enabled', 'ADSENSE_ENABLED', '0') === '1' && $publisher !== '';
        return [
            'enabled' => $enabled,
            'publisherId' => $publisher,
            'slotCatalog' => self::setting($c, 'adsense_slot_catalog', 'ADSENSE_SLOT_CATALOG', ''),
            'slotTool' => self::setting($c, 'adsense_slot_tool', 'ADSENSE_SLOT_TOOL', ''),
        ];
    }

    private static function fireRebuild(ContainerInterface $c, string $reason): void
    {
        $token = self::store($c)?->getSecret(self::NS, 'rebuild_token');
        if ($token === null || $token === '') {
            $token = self::env('TOOLS_REBUILD_TOKEN', '');
        }
        $repo = self::setting($c, 'rebuild_repo', 'TOOLS_REBUILD_REPO', '');
        $workflow = self::setting($c, 'rebuild_workflow', 'TOOLS_REBUILD_WORKFLOW', 'dev.yml');
        (new RebuildTrigger($token))->trigger($repo !== '' ? $repo : null, $workflow, $reason);
    }

    private static function bearer(Request $req): string
    {
        $h = $req->getHeaderLine('Authorization');
        return preg_match('/^Bearer\s+(.+)$/i', $h, $m) === 1 ? trim($m[1]) : '';
    }

    private static function setting(ContainerInterface $c, string $key, string $envKey, string $default): string
    {
        $v = self::store($c)?->get(self::NS, $key);
        if ($v !== null && $v !== '') {
            return $v;
        }
        return self::env($envKey, $default);
    }

    private static function store(ContainerInterface $c): ?SettingsStore
    {
        return $c->has(SettingsStore::class) ? $c->get(SettingsStore::class) : null;
    }

    /** Env read with explicit default — avoids the `?? getenv() ?: $d` precedence trap ("0"/""). */
    private static function env(string $key, string $default): string
    {
        $v = getenv($key);
        return $v === false ? $default : $v;
    }

    private static function requireManage(UserContext $user, Response $res): ?Response
    {
        if (!$user->isAuthenticated()) {
            return self::json($res, ['error' => 'Unauthorized'], 401);
        }
        if (!$user->has('tools:manage')) {
            return self::json($res, ['error' => 'Forbidden'], 403);
        }
        return null;
    }

    private static function json(Response $res, mixed $data, int $status = 200): Response
    {
        $res->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $res->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
