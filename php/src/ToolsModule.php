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
use Tds\Ext\Tools\Service\StripeClient;
use Tds\Ext\Tools\Service\StripeException;
use Tds\Ext\Tools\Service\WebhookVerifier;
use Tds\Frontend\Contract\AbstractModule;
use Tds\Frontend\Contract\ApiDocSource;
use Tds\Frontend\Contract\CacheEvent;
use Tds\Frontend\Contract\ConnectedSiteCache;
use Tds\Frontend\Contract\PermissionDef;
use Tds\Frontend\Contract\ReportingSiteCache;
use Tds\Frontend\Contract\SettingDef;
use Tds\Frontend\Contract\SettingsStore;
use Tds\Frontend\Contract\SiteCache;
use Tds\Frontend\Contract\SiteConnectionException;
use Tds\Frontend\Contract\SiteConnections;
use Tds\Frontend\Contract\SiteKeyProtected;
use Tds\Frontend\Contract\SiteKeys;
use Tds\Frontend\Contract\UserContext;
use Throwable;

/**
 * Backend Module for the public tools platform (tds-tools).
 *
 * Owns the tool catalog config: which tools are enabled, require login, are
 * premium (+ price), and the AdSense config. The tool *list* is owned by the
 * frontend packs and flows in via the paired site's scoped registry sync
 * (`POST /tools/registry`, called by the site server). The public site reads the
 * merged catalog from `GET /tools/catalog` (unauthenticated). Admins manage the
 * overrides via `/admin/tools`; a change refreshes only the connected site's
 * affected cache paths.
 *
 * Auth via the core {@see UserContext}: admin routes need `tools:manage` (admins
 * bypass); the catalog GET is public; the registry POST requires the paired,
 * resource-bound site key. AdSense and premium config use the core
 * {@see SettingsStore} (ns=tools), DB-first with env fallback. A legacy registry
 * token remains accepted for this migration release but has no panel field.
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
            // One-release fallback for installations not paired yet. These are
            // hidden from the UI; a paired connection always takes priority.
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
        // A paired, scoped tools key is authoritative. The legacy token remains
        // accepted for one release only, without any panel field.
        $app->post('/tools/registry', function (Request $req, Response $res) use ($c): Response {
            $body = (array) $req->getParsedBody();
            $provided = trim($req->getHeaderLine('X-TDS-Site-Key'));
            if ($provided === '') {
                $provided = (string) ($body['token'] ?? ($body['key'] ?? self::bearer($req)));
            }

            $identity = $provided !== '' ? self::siteKeys($c)?->verify($provided) : null;
            if ($identity !== null
                && $identity->resourceType === 'tools'
                && $identity->resourceId === 'tools'
                && $identity->allows('/tools/registry')) {
                $tools = is_array($body['tools'] ?? null) ? $body['tools'] : [];
                $n = $c->get(ToolConfigRepository::class)->upsertRegistry($tools);
                $cache = self::fireCache($c, null, null, 'catalog');
                return self::json($res, array_merge(['ok' => true, 'synced' => $n], $cache));
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
            $cache = self::fireCache($c, null, null, 'catalog');
            return self::json($res, array_merge(['ok' => true, 'synced' => $n, 'legacy_auth' => true], $cache));
        });

        // --- Admin: manage the catalog overrides ------------------------------
        $app->get('/admin/tools', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireManage($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            return self::json($res, ['tools' => $c->get(ToolConfigRepository::class)->all()]);
        });

        $app->get('/admin/tools/connection', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireManage($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $connections = self::connections($c);
            if ($connections === null) {
                return self::json($res, ['error' => 'Site connection service is not available'], 503);
            }
            $connection = $connections->get('tools', 'tools');
            return $connection === null
                ? self::json($res, ['error' => 'Connection not found'], 404)
                : self::json($res, ['connection' => $connection->toArray()]);
        });

        $app->delete('/admin/tools/connection', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireManage($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $connections = self::connections($c);
            if ($connections === null) {
                return self::json($res, ['error' => 'Site connection service is not available'], 503);
            }
            return self::json($res, ['ok' => true, 'deleted' => $connections->delete('tools', 'tools')]);
        });

        $app->post('/admin/tools/connection/pairing', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireManage($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $connections = self::connections($c);
            if ($connections === null) {
                return self::json($res, ['error' => 'Site connection service is not available'], 503);
            }
            $body = (array) $req->getParsedBody();
            $origin = trim((string) ($body['origin'] ?? ''));
            try {
                $pairing = $connections->createPairing(
                    'tools',
                    'tools',
                    $origin,
                    'tools',
                    ['tools' => 'tools'],
                    ['/tools/catalog', '/tools/guides', '/tools/registry'],
                );
                return self::json($res, $connections->deliverPairing($pairing, self::apiBase($req))->toArray(), 201);
            } catch (SiteConnectionException $e) {
                return self::json($res, ['error' => $e->getMessage(), 'code' => $e->errorCode], $e->httpStatus);
            } catch (Throwable $e) {
                error_log('[tools] pairing failed: ' . $e->getMessage());
                return self::json($res, ['error' => 'Pairing could not be created'], 503);
            }
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
            $cache = self::fireCache($c, (string) $args['id'], null);
            return self::json($res, array_merge(['ok' => true], $cache));
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
            $cache = self::fireCache($c, $toolId, $lang);
            return self::json($res, array_merge(['ok' => true], $cache));
        });

        $app->delete('/admin/tools/guides/{id}/{lang}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::requireManage($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $lang = strtolower((string) $args['lang']);
            $toolId = (string) $args['id'];
            $c->get(ToolGuideRepository::class)->delete($toolId, $lang);
            $cache = self::fireCache($c, $toolId, $lang);
            return self::json($res, array_merge(['ok' => true], $cache));
        });

        // --- Admin: rebuild the public site's page cache ----------------------
        //
        // Re-renders pages from content that is already saved; it never deploys.
        $app->post('/admin/tools/cache/rebuild', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireManage($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $body = (array) $req->getParsedBody();
            $toolId = isset($body['tool_id']) ? (string) $body['tool_id'] : null;
            $eventType = isset($body['event']) && $body['event'] === 'settings' ? 'catalog' : 'tool';
            $connection = self::connection($c);
            if ($connection === null) {
                $legacyUrl = self::setting($c, 'cache_url', 'TOOLS_CACHE_URL', '');
                if (trim($legacyUrl) === '') {
                    return self::json($res, array_merge(
                        ['error' => 'Tools site is not connected'],
                        self::emptyCacheReport('not_configured'),
                    ), 503);
                }
                if (self::normalizeOrigin($legacyUrl) === null) {
                    return self::json($res, ['error' => 'Invalid cache origin'], 422);
                }
            }
            $cache = self::fireCache($c, $toolId, null, $eventType);
            return self::json($res, array_merge(['ok' => $cache['cached']], $cache), self::manualCacheStatus($cache));
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
     * is stored either way and the operator can retry the targeted cache refresh.
     *
     * `has()` is legitimate here because SiteCache is an INTERFACE — the base
     * either bound an implementation or it did not. On a concrete class the
     * same check would always answer true (PHP-DI autowires), which is the
     * trap that left six modules binding nothing at all.
     */
    /** @return array{cache_status:string,cached:bool,rebuilt:array,skipped:array,failed:array,unknownEvents:array} */
    private static function fireCache(ContainerInterface $c, ?string $toolId, ?string $lang, string $type = 'tool'): array
    {
        $event = new CacheEvent($type, $toolId, $lang);
        try {
            $connection = self::connection($c);
            if ($connection !== null && $c->has(ConnectedSiteCache::class)) {
                return $c->get(ConnectedSiteCache::class)->refresh('tools', 'tools', $event)->toArray();
            }
            if ($connection !== null) {
                return self::emptyCacheReport('not_configured');
            }
            if (!$c->has(SiteCache::class)) {
                return self::emptyCacheReport('not_configured');
            }
            $url = self::setting($c, 'cache_url', 'TOOLS_CACHE_URL', '');
            $token = self::store($c)?->getSecret(self::NS, 'cache_token');
            if ($token === null || $token === '') {
                $token = self::env('TOOLS_CACHE_TOKEN', '');
            }
            $cache = $c->get(SiteCache::class);
            if (!$cache->isConfigured($url, $token)) {
                return self::emptyCacheReport('not_configured');
            }
            if ($cache instanceof ReportingSiteCache) {
                return $cache->rebuildWithResult($url, $token, [$event])->toArray();
            }
            $cache->rebuild($url, $token, [$event]);
            $report = self::emptyCacheReport('skipped');
            $report['unknownEvents'][] = ['reason' => 'legacy_transport_has_no_result'];
            return $report;
        } catch (Throwable $e) {
            error_log('[tools] page-cache request failed: ' . $e->getMessage());
            $report = self::emptyCacheReport('failed');
            $report['failed'][] = ['reason' => 'transport_error'];
            return $report;
        }
    }

    private static function connections(ContainerInterface $c): ?SiteConnections
    {
        try {
            return $c->has(SiteConnections::class) ? $c->get(SiteConnections::class) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function connection(ContainerInterface $c): mixed
    {
        try {
            return self::connections($c)?->get('tools', 'tools');
        } catch (Throwable) {
            return null;
        }
    }

    private static function apiBase(Request $req): string
    {
        $uri = $req->getUri();
        return $uri->getScheme() . '://' . $uri->getAuthority();
    }

    /** @return array{cache_status:string,cached:bool,rebuilt:array,skipped:array,failed:array,unknownEvents:array} */
    private static function emptyCacheReport(string $status): array
    {
        return [
            'cache_status' => $status,
            'cached' => false,
            'rebuilt' => [],
            'skipped' => [],
            'failed' => [],
            'unknownEvents' => [],
        ];
    }

    /** @param array{cache_status:string,cached:bool} $report */
    private static function manualCacheStatus(array $report): int
    {
        if ($report['cache_status'] === 'refreshed' && $report['cached'] === true) {
            return 202;
        }
        return $report['cache_status'] === 'not_configured' ? 503 : 502;
    }

    private static function normalizeOrigin(string $value): ?string
    {
        $value = rtrim(trim($value), '/');
        $parts = parse_url($value);
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '')) {
            return null;
        }
        return $value;
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
