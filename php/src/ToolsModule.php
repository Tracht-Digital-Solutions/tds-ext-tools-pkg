<?php
declare(strict_types=1);

namespace Tds\Ext\Tools;

use PDO;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Tds\Ext\Tools\Domain\EntitlementRepository;
use Tds\Ext\Tools\Domain\ToolConfigRepository;
use Tds\Ext\Tools\Domain\ToolGuideRepository;
use Tds\Ext\Tools\Service\RebuildTrigger;
use Tds\Ext\Tools\Service\StripeClient;
use Tds\Ext\Tools\Service\StripeException;
use Tds\Ext\Tools\Service\WebhookVerifier;
use Tds\Frontend\Contract\AbstractModule;
use Tds\Frontend\Contract\ApiDocSource;
use Tds\Frontend\Contract\CacheEvent;
use Tds\Frontend\Contract\PermissionDef;
use Tds\Frontend\Contract\SettingDef;
use Tds\Frontend\Contract\SettingsStore;
use Tds\Frontend\Contract\SiteCache;
use Tds\Frontend\Contract\SiteKeyProtected;
use Tds\Frontend\Contract\SiteKeys;
use Tds\Frontend\Contract\UserContext;
use Throwable;

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
final class ToolsModule extends AbstractModule implements ApiDocSource, SiteKeyProtected
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
            new SettingDef('rebuild_repo', 'Rebuild-Repo (owner/name)', false, 'tools', 'Tracht-Digital-Solutions/tds-tools-frontend'),
            // release.yml, NOT dev.yml: tds-tools-frontend deleted its dev.yml on
            // 2026-08-24 when the deploy stopped running on every push. The
            // dispatch is best-effort and never throws, so the stale default
            // meant every catalog change 404'd against GitHub in silence.
            new SettingDef('rebuild_workflow', 'Rebuild-Workflow', false, 'tools', 'release.yml'),
            new SettingDef('rebuild_token', 'Rebuild-Token (GitHub PAT)', true, 'tools'),
            // The page cache of the public site. Separate from the rebuild
            // pair above and NOT interchangeable with it: a rebuild ships code
            // through CI, this re-renders a page from content that is already
            // saved. Both exist because both jobs exist.
            new SettingDef('cache_url', 'Seiten-Cache: Basis-URL der Tools-Site', false, 'tools', 'https://tools.tracht-digital.de'),
            new SettingDef('cache_token', 'Seiten-Cache: Token', true, 'tools'),
            new SettingDef('stripe_secret_key', 'Stripe Secret Key (Premium)', true, 'tools'),
            new SettingDef('stripe_webhook_secret', 'Stripe Webhook Secret', true, 'tools'),
            new SettingDef('currency', 'Währung (Premium)', false, 'tools', 'EUR'),
            new SettingDef('checkout_success_url', 'Checkout Success-URL', false, 'tools', 'https://tools.tracht-digital.de/'),
            new SettingDef('checkout_cancel_url', 'Checkout Cancel-URL', false, 'tools', 'https://tools.tracht-digital.de/'),
        ];
    }

    public function register(App $app): void
    {
        $c = $app->getContainer();
        // NEVER guard these with `!$c->has(X)`. PHP-DI answers `has()` from its
        // definition sources, and autowiring is one of them: for any *concrete,
        // instantiable* class the answer is always true, whether or not anyone
        // ever bound it. So the guard skipped every binding below and the
        // container silently autowired instead — invisible for the repositories
        // (their only argument is the bound PDO, so the object is identical),
        // fatal for the StripeClient, whose constructor takes a string PHP-DI
        // cannot guess: the premium checkout and webhook routes answered 500
        // with `Parameter $secretKey of __construct() has no value defined or
        // guessable`, and the settings-store factory never ran at all. The
        // module owns these classes; nothing else defines them.
        if ($c !== null) {
            $c->set(ToolConfigRepository::class, static fn ($c) => new ToolConfigRepository($c->get(PDO::class)));
            $c->set(ToolGuideRepository::class, static fn ($c) => new ToolGuideRepository($c->get(PDO::class)));
            $c->set(EntitlementRepository::class, static fn ($c) => new EntitlementRepository($c->get(PDO::class)));
            $c->set(StripeClient::class, static function ($c): StripeClient {
                $key = self::store($c)?->getSecret(self::NS, 'stripe_secret_key');
                if ($key === null || $key === '') {
                    $key = self::env('STRIPE_SECRET_KEY', '');
                }
                return new StripeClient($key);
            });
        }

        // --- Public: the catalog the static site bakes at build time ----------
        $app->get('/tools/catalog', function (Request $req, Response $res) use ($c): Response {
            $repo = $c->get(ToolConfigRepository::class);
            return self::json($res, [
                'tools' => $repo->publicCatalog(),
                'ads' => self::adsConfig($c),
            ]);
        });

        // --- Public: the panel-editable copy of each tool page ----------------
        //
        // Site-key protected like /tools/catalog (see siteKeyRoutes). It has to
        // be listed there: a new public read path that nobody adds to the list
        // is the one hole in an otherwise gated surface.
        $app->get('/tools/guides', function (Request $req, Response $res) use ($c): Response {
            $lang = strtolower(trim((string) ($req->getQueryParams()['lang'] ?? 'de')));
            if (!in_array($lang, ['de', 'en'], true)) {
                $lang = 'de';
            }
            try {
                $guides = $c->get(ToolGuideRepository::class)->allForLang($lang);
            } catch (Throwable) {
                // Fail soft, exactly like the other public content reads: the
                // site falls back to the guides committed in its own repo, so
                // a database hiccup makes a tool page stale, never blank.
                $guides = [];
            }
            return self::json($res, ['guides' => (object) $guides]);
        });

        // --- Registry sync (token-gated; the site build upserts its packs) ----
        //
        // TWO credentials are accepted. A **site key** for the `tools` site is
        // the way forward: it is issued in the panel, revocable, records when it
        // was last used, and is the same thing every other public site presents.
        // The legacy `registry_token` keeps working for one release, because the
        // token is typed into the /install wizard by a human and an operator
        // mid-setup should not be stopped by an upgrade.
        //
        // The site id is passed to verify() rather than read from the body: a
        // key belongs to exactly one site, and trusting a `site` field sent
        // alongside the key would let the blog's key write the tools catalog.
        $app->post('/tools/registry', function (Request $req, Response $res) use ($c): Response {
            $body = (array) $req->getParsedBody();
            $provided = (string) ($body['token'] ?? ($body['key'] ?? self::bearer($req)));

            if ($provided !== '' && self::siteKeys($c)?->verify($provided, 'tools') !== null) {
                $tools = is_array($body['tools'] ?? null) ? $body['tools'] : [];
                $n = $c->get(ToolConfigRepository::class)->upsertRegistry($tools);
                return self::json($res, ['ok' => true, 'synced' => $n]);
            }

            $configured = self::store($c)?->getSecret(self::NS, 'registry_token');
            if ($configured === null || $configured === '') {
                $configured = self::env('TOOLS_REGISTRY_TOKEN', '');
            }
            if ($configured === '') {
                // Neither credential exists. Named as such: the older message
                // ("Registry sync not configured") sent an operator to the tools
                // settings even when the intended fix was a site key.
                return self::json($res, [
                    'error' => 'Registry sync not configured — Site-Key oder Registry-Token hinterlegen',
                ], 503);
            }
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

        // --- Admin: the tool pages' copy --------------------------------------
        $app->get('/admin/tools/guides', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireManage($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            return self::json($res, ['guides' => $c->get(ToolGuideRepository::class)->all()]);
        });

        $app->put('/admin/tools/guides/{id}/{lang}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::requireManage($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $lang = strtolower((string) $args['lang']);
            if (!in_array($lang, ['de', 'en'], true)) {
                return self::json($res, ['error' => 'Unsupported language'], 422);
            }
            $toolId = (string) $args['id'];
            $c->get(ToolGuideRepository::class)->save($toolId, $lang, (array) $req->getParsedBody());
            self::fireCache($c, $toolId, $lang);
            return self::json($res, ['ok' => true]);
        });

        $app->delete('/admin/tools/guides/{id}/{lang}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::requireManage($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $lang = strtolower((string) $args['lang']);
            $toolId = (string) $args['id'];
            $c->get(ToolGuideRepository::class)->delete($toolId, $lang);
            self::fireCache($c, $toolId, $lang);
            return self::json($res, ['ok' => true]);
        });

        // --- Admin: rebuild the public site's page cache ----------------------
        //
        // Distinct from /admin/tools/rebuild above, which dispatches a CI build.
        // This one re-renders pages from content that is already saved, in
        // seconds, and is what an editor reaches for.
        $app->post('/admin/tools/cache/rebuild', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireManage($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $body = (array) $req->getParsedBody();
            $toolId = isset($body['tool_id']) ? (string) $body['tool_id'] : null;
            self::fireCache($c, $toolId, null);
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

        // --- Premium: entitlement check (login required) ----------------------
        $app->get('/tools/entitlement', function (Request $req, Response $res) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (!$user->isAuthenticated() || $user->userId() === null) {
                return self::json($res, ['entitled' => false, 'authenticated' => false], 401);
            }
            $toolId = (string) ($req->getQueryParams()['tool'] ?? '');
            if ($toolId === '') {
                return self::json($res, ['error' => 'tool query param required'], 422);
            }
            // Admins can use every premium tool without a purchase.
            $entitled = $user->isAdmin() || $c->get(EntitlementRepository::class)->isEntitled((int) $user->userId(), $toolId);
            return self::json($res, ['entitled' => $entitled, 'authenticated' => true]);
        });

        // --- Premium: start a Stripe Checkout Session (login required) --------
        $app->post('/tools/checkout', function (Request $req, Response $res) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (!$user->isAuthenticated() || $user->userId() === null) {
                return self::json($res, ['error' => 'Unauthorized'], 401);
            }
            $body = (array) $req->getParsedBody();
            $toolId = (string) ($body['tool'] ?? '');
            $tool = $toolId === '' ? null : $c->get(ToolConfigRepository::class)->find($toolId);
            if ($tool === null || !$tool['is_premium'] || $tool['price_cents'] <= 0) {
                return self::json($res, ['error' => 'Kein kostenpflichtiges Tool.'], 400);
            }
            if ($c->get(EntitlementRepository::class)->isEntitled((int) $user->userId(), $toolId)) {
                return self::json($res, ['error' => 'Bereits freigeschaltet.'], 409);
            }
            $client = $c->get(StripeClient::class);
            if (!$client->isConfigured()) {
                return self::json($res, ['error' => 'Zahlung nicht konfiguriert.'], 503);
            }
            try {
                $session = $client->createCheckoutSession(
                    (int) $user->userId(),
                    $toolId,
                    $tool['name'],
                    (int) $tool['price_cents'],
                    self::setting($c, 'currency', 'TOOLS_CURRENCY', 'EUR'),
                    self::setting($c, 'checkout_success_url', 'TOOLS_CHECKOUT_SUCCESS_URL', 'https://tools.tracht-digital.de/'),
                    self::setting($c, 'checkout_cancel_url', 'TOOLS_CHECKOUT_CANCEL_URL', 'https://tools.tracht-digital.de/'),
                );
            } catch (StripeException $e) {
                return self::json($res, ['error' => $e->getMessage()], 502);
            }
            return self::json($res, ['url' => $session['url']], 201);
        });

        // --- Premium: Stripe webhook (unauthenticated; signature-verified) ----
        $app->post('/tools/stripe-webhook', function (Request $req, Response $res) use ($c): Response {
            $secret = self::store($c)?->getSecret(self::NS, 'stripe_webhook_secret');
            if ($secret === null || $secret === '') {
                $secret = self::env('STRIPE_WEBHOOK_SECRET', '');
            }
            if ($secret === '') {
                return self::json($res, ['error' => 'Webhook secret not configured'], 503);
            }
            $payload = (string) $req->getBody();
            if (!WebhookVerifier::verify($payload, $req->getHeaderLine('Stripe-Signature'), $secret)) {
                return self::json($res, ['error' => 'Invalid signature'], 400);
            }
            $event = json_decode($payload, true);
            $type = is_array($event) ? (string) ($event['type'] ?? '') : '';
            if ($type === 'checkout.session.completed') {
                $session = $event['data']['object'] ?? [];
                $userId = (int) ($session['client_reference_id'] ?? ($session['metadata']['user_id'] ?? 0));
                $toolId = (string) ($session['metadata']['tool_id'] ?? '');
                $sessionId = (string) ($session['id'] ?? '');
                if ($userId > 0 && $toolId !== '') {
                    $c->get(EntitlementRepository::class)->grant($userId, $toolId, $sessionId !== '' ? $sessionId : null);
                }
            }
            return self::json($res, ['received' => true]);
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

    /**
     * Ask the public site to re-render the pages a tool's copy affects.
     *
     * Never throws and never fails the save: a site that is down, moved or not
     * configured yet must not turn "save this guide" into an error. The guide
     * is stored either way and the operator has a rebuild button to catch up.
     *
     * `has()` is legitimate here because SiteCache is an INTERFACE — the base
     * either bound an implementation or it did not. On a concrete class the
     * same check would always answer true (PHP-DI autowires), which is the
     * trap that left six modules binding nothing at all.
     */
    private static function fireCache(ContainerInterface $c, ?string $toolId, ?string $lang): void
    {
        if (!$c->has(SiteCache::class)) {
            return;
        }
        $url = self::setting($c, 'cache_url', 'TOOLS_CACHE_URL', '');
        $token = self::store($c)?->getSecret(self::NS, 'cache_token');
        if ($token === null || $token === '') {
            $token = self::env('TOOLS_CACHE_TOKEN', '');
        }

        $c->get(SiteCache::class)->rebuild($url, $token, [
            new CacheEvent('tool', $toolId, $lang),
        ]);
    }

    private static function fireRebuild(ContainerInterface $c, string $reason): void
    {
        $token = self::store($c)?->getSecret(self::NS, 'rebuild_token');
        if ($token === null || $token === '') {
            $token = self::env('TOOLS_REBUILD_TOKEN', '');
        }
        $repo = self::setting($c, 'rebuild_repo', 'TOOLS_REBUILD_REPO', '');
        $workflow = self::setting($c, 'rebuild_workflow', 'TOOLS_REBUILD_WORKFLOW', 'release.yml');
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

    /**
     * The site-key verifier, or null on a base that predates it or has no
     * database. Null-safe on purpose: this module must keep composing against an
     * older core, and the legacy registry token below is then the only path.
     */
    private static function siteKeys(ContainerInterface $c): ?SiteKeys
    {
        try {
            return $c->has(SiteKeys::class) ? $c->get(SiteKeys::class) : null;
        } catch (\Throwable) {
            return null;
        }
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

    /**
     * Route documentation for the admin frontend's API reference. Kept in its
     * own file so the prose does not sit in the middle of the wiring.
     *
     * @return list<array<string, mixed>>
     */
    public function apiDocs(): array
    {
        return require __DIR__ . '/../docs/api.php';
    }

    /**
     * The catalog the static tools site bakes at build time.
     *
     * `/tools/registry` is deliberately NOT listed: it carries its own
     * credential check, and going through the middleware as well would reject a
     * legacy `registry_token` call before the route ever saw it — breaking the
     * one path an operator mid-setup is most likely to be on.
     *
     * Nor is `/tools/entitlement` or `/tools/checkout`: those run in a
     * visitor's browser on the public site, which has no key and never will.
     * Listing one would turn `enforce` into a paywall that rejects paying
     * customers.
     *
     * @return list<string>
     */
    public function siteKeyRoutes(): array
    {
        return ['/tools/catalog', '/tools/guides'];
    }
}
