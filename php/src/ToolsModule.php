<?php
declare(strict_types=1);

namespace Tds\Ext\Tools;

use PDO;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Tds\Ext\Tools\Domain\EntitlementRepository;
use Tds\Ext\Tools\Domain\StudioProjectRepository;
use Tds\Ext\Tools\Domain\SubscriptionRepository;
use Tds\Ext\Tools\Domain\TierPayload;
use Tds\Ext\Tools\Domain\TierRepository;
use Tds\Ext\Tools\Domain\ToolConfigRepository;
use Tds\Ext\Tools\Domain\ToolGuideRepository;
use Tds\Ext\Tools\Service\PremiumAccess;
use Tds\Ext\Tools\Service\StripeClient;
use Tds\Ext\Tools\Service\SubscriptionEvent;
use Tds\Ext\Tools\Studio\CurlTransport;
use Tds\Ext\Tools\Studio\DnsHostResolver;
use Tds\Ext\Tools\Studio\FileRateLimiter;
use Tds\Ext\Tools\Studio\PageFetcher;
use Tds\Ext\Tools\Studio\StudioFetchException;
use Tds\Ext\Tools\Studio\StudioPolicy;
use Tds\Ext\Tools\Studio\UrlGuard;
use Tds\Frontend\Contract\Stripe\StripeException;
use Tds\Frontend\Contract\Stripe\StripeWebhook;
use Tds\Frontend\Contract\AbstractModule;
use Tds\Frontend\Contract\SetupStatusSource;
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
use Tds\Frontend\Contract\ModuleHttp;
use Tds\Frontend\Contract\Stripe\StripeWebhookDef;
use Tds\Frontend\Contract\Stripe\StripeWebhookSource;
use Tds\Frontend\Contract\Stripe\StripeApi;
use Tds\Frontend\Contract\Stripe\CurlStripeApi;

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
final class ToolsModule extends AbstractModule implements ApiDocSource, SiteKeyProtected, StripeWebhookSource, SetupStatusSource
{
    /** Kept from register() for setupItems(), which the base calls without one. */
    private ?\Psr\Container\ContainerInterface $setupContainer = null;

    use ModuleHttp;

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
            new SettingDef('stripe_secret_key', 'Stripe Secret Key (optional — leer = zentrales Konto)', true, 'tools'),
            new SettingDef('stripe_webhook_secret', 'Stripe Webhook Secret', true, 'tools'),
            new SettingDef('currency', 'Währung (Premium)', false, 'tools', 'EUR'),
            new SettingDef('checkout_success_url', 'Checkout Success-URL', false, 'tools', 'https://tools.tracht-digital.de/'),
            new SettingDef('checkout_cancel_url', 'Checkout Cancel-URL', false, 'tools', 'https://tools.tracht-digital.de/'),
            // Designsystem-Studio. The tool's on/off switch is its catalog row.
            new SettingDef('studio_premium_formats', 'Designsystem-Studio: Premium-Exportformate', false, 'tools', StudioPolicy::DEFAULT_PREMIUM),
            new SettingDef('studio_price_cents', 'Designsystem-Studio: Premium-Preis (Cent)', false, 'tools', '900'),
            new SettingDef('studio_project_limit', 'Designsystem-Studio: Projekte pro Nutzer', false, 'tools', '50'),
            new SettingDef('studio_analysis_enabled', 'Designsystem-Studio: Website-Analyse aktiv (1/0)', false, 'tools', '1'),
            new SettingDef('studio_analysis_per_hour', 'Designsystem-Studio: Analysen pro Stunde und Adresse', false, 'tools', '20'),
            new SettingDef('studio_analysis_max_stylesheets', 'Designsystem-Studio: Stylesheets pro Analyse', false, 'tools', '12'),
        ];
    }

    /** Listed in the admin panel under Einstellungen → Zahlungen (Stripe). */
    public function stripeWebhooks(): array
    {
        return [new StripeWebhookDef(
            'Premium-Tools',
            '/tools/stripe-webhook',
            [
                'checkout.session.completed',
                'checkout.session.async_payment_succeeded',
                // Premium as a monthly subscription (stages): status, renewal
                // and cancellation arrive on the subscription itself.
                'customer.subscription.created',
                'customer.subscription.updated',
                'customer.subscription.deleted',
            ],
            self::NS,
            'stripe_webhook_secret',
        )];
    }

    /**
     * What the panel's setup wizard should say about this module. Uses the
     * same check the feature itself runs, never a secret.
     *
     * @return list<array<string,string>>
     */
    public function setupItems(\Tds\Frontend\Contract\UserContext $user): array
    {
        $c = $this->setupContainer;
        if ($c === null) {
            return [];
        }
        $items = [];
        try {
            $secret = self::store($c)?->getSecret(self::NS, 'stripe_webhook_secret');
            $items[] = [
                'id' => 'tools:stripe-webhook',
                'module' => 'tools',
                'title' => 'Tools: Stripe-Webhook',
                'description' => 'Ohne Webhook-Geheimnis meldet Stripe keine Zahlung zurück: gekaufte Premium-Tools werden nicht freigeschaltet.',
                'state' => ($secret !== null && $secret !== '') || self::env('STRIPE_WEBHOOK_SECRET', '') !== '' ? 'ok' : 'missing',
                'level' => 'recommended',
                'href' => '/einstellungen#settings-stripe',
            ];
        } catch (\Throwable) {
        }
        try {
            $items[] = [
                'id' => 'tools:premium-tiers',
                'module' => 'tools',
                'title' => 'Tools: Premium-Stufen',
                'description' => 'Ohne aktive Stufe gibt es nichts zu abonnieren: Premium-Werkzeuge lassen sich dann nicht freischalten. Für Kündigungen muss in Stripe das Kundenportal aktiviert sein.',
                'state' => $c->get(TierRepository::class)->active() !== [] ? 'ok' : 'missing',
                'level' => 'recommended',
                'href' => '/tools-verwaltung#premium-tiers',
            ];
        } catch (\Throwable) {
        }
        return $items;
    }

    public function register(App $app): void
    {
        $c = $app->getContainer();
        $this->setupContainer = $c;
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
            $c->set(StudioProjectRepository::class, static fn ($c) => new StudioProjectRepository($c->get(PDO::class)));
            $c->set(TierRepository::class, static fn ($c) => new TierRepository($c->get(PDO::class)));
            $c->set(SubscriptionRepository::class, static fn ($c) => new SubscriptionRepository($c->get(PDO::class)));
            $c->set(PremiumAccess::class, static fn ($c) => new PremiumAccess(
                $c->get(EntitlementRepository::class),
                $c->get(SubscriptionRepository::class),
                $c->get(TierRepository::class),
            ));
            $c->set(PageFetcher::class, static fn ($c) => new PageFetcher(
                new DnsHostResolver(),
                new CurlTransport(),
                max(1, min(30, (int) self::setting($c, 'studio_analysis_max_stylesheets', 'TOOLS_STUDIO_MAX_STYLESHEETS', '12'))),
            ));
            $c->set(FileRateLimiter::class, static fn () => new FileRateLimiter(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tds-tools-studio-rate'));
            $c->set(StripeClient::class, static function ($c): StripeClient {
                // Its own key overrides the platform account; otherwise the
                // central one from Einstellungen → Zahlungen (Stripe), which
                // itself falls back to STRIPE_SECRET_KEY on the host.
                $own = (string) (self::store($c)?->getSecret(self::NS, 'stripe_secret_key') ?? '');
                if ($own !== '') {
                    return new StripeClient(new CurlStripeApi($own));
                }
                $central = $c->has(StripeApi::class) ? $c->get(StripeApi::class) : null;
                return new StripeClient($central instanceof StripeApi
                    ? $central
                    : new CurlStripeApi(self::env('STRIPE_SECRET_KEY', '')));
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
            // Admins, one-off purchases and granting subscriptions (PremiumAccess).
            $entitled = $c->get(PremiumAccess::class)->isEntitled((int) $user->userId(), $toolId, $user->isAdmin());
            return self::json($res, ['entitled' => $entitled, 'authenticated' => true]);
        });

        // --- Premium: unlock one tool (login required) ------------------------
        //
        // One-off sales ended with the subscriptions (2026-10-24). The route
        // stays, because the tool gate and the studio call it: it now opens
        // the monthly checkout of the CHEAPEST stage on sale that unlocks the
        // tool. Purchases made before keep working (PremiumAccess).
        $app->post('/tools/checkout', function (Request $req, Response $res) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (!$user->isAuthenticated() || $user->userId() === null) {
                return self::json($res, ['error' => 'Unauthorized'], 401);
            }
            $toolId = trim((string) (((array) $req->getParsedBody())['tool'] ?? ''));
            if ($toolId === '') {
                return self::json($res, ['error' => 'Kein Werkzeug angegeben.'], 400);
            }
            $tool = $c->get(ToolConfigRepository::class)->find($toolId);
            if ($tool !== null && !$tool['enabled']) {
                return self::json($res, ['error' => 'Das Tool ist deaktiviert.'], 403);
            }
            if ($c->get(PremiumAccess::class)->isEntitled((int) $user->userId(), $toolId, $user->isAdmin())) {
                return self::json($res, ['error' => 'Bereits freigeschaltet.'], 409);
            }
            $tier = self::tierFor($c, $toolId);
            if ($tier === null) {
                return self::json($res, ['error' => 'Keine Premium-Stufe enthält dieses Werkzeug.', 'code' => 'no_tier'], 400);
            }
            return self::startSubscription($c, $res, $tier);
        });

        // --- Premium: the stages on sale (public) ------------------------------
        //
        // Public on purpose and NOT a site-key route: prices are public
        // information, the site reads them server-side, and a paired key from
        // before this route existed would not allow it. Fails soft to [].
        $app->get('/tools/tiers', function (Request $req, Response $res) use ($c): Response {
            try {
                $tiers = array_map(static fn (array $t): array => [
                    'slug' => $t['slug'],
                    'name' => $t['name'],
                    'name_en' => $t['name_en'],
                    'tagline' => $t['tagline'],
                    'tagline_en' => $t['tagline_en'],
                    'features' => $t['features'],
                    'features_en' => $t['features_en'],
                    'price_cents' => $t['price_cents'],
                    'interval' => 'month',
                    'tool_ids' => $t['tool_ids'],
                    'highlight' => $t['highlight'],
                ], $c->get(TierRepository::class)->active());
            } catch (Throwable) {
                $tiers = [];
            }
            return self::json($res, [
                'tiers' => $tiers,
                'currency' => self::setting($c, 'currency', 'TOOLS_CURRENCY', 'EUR'),
            ]);
        });

        // --- Premium: the visitor's own state (session optional) --------------
        $app->get('/tools/premium/status', function (Request $req, Response $res) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (!$user->isAuthenticated() || $user->userId() === null) {
                return self::json($res, ['authenticated' => false, 'admin' => false, 'premium' => false, 'subscriptions' => [], 'tools' => []]);
            }
            $status = $c->get(PremiumAccess::class)->status((int) $user->userId());
            return self::json($res, [
                'authenticated' => true,
                'admin' => $user->isAdmin(),
                // "Has a premium account": an admin, or a subscription Stripe
                // still counts as paying. One-off purchases unlock their tool
                // (see /tools/entitlement) but do not make the account premium.
                'premium' => $user->isAdmin() || $status['subscriptions'] !== [],
                'subscriptions' => $status['subscriptions'],
                'tools' => $status['tools'],
            ]);
        });

        // --- Premium: subscribe to a stage (login required) -------------------
        $app->post('/tools/subscribe', function (Request $req, Response $res) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (!$user->isAuthenticated() || $user->userId() === null) {
                return self::json($res, ['error' => 'Bitte anmelden.', 'code' => 'login_required'], 401);
            }
            $slug = strtolower(trim((string) (((array) $req->getParsedBody())['tier'] ?? '')));
            $tier = $slug === '' ? null : $c->get(TierRepository::class)->findBySlug($slug);
            if ($tier === null || !$tier['active']) {
                return self::json($res, ['error' => 'Diese Premium-Stufe gibt es nicht.', 'code' => 'unknown_tier'], 404);
            }
            return self::startSubscription($c, $res, $tier);
        });

        // --- Premium: manage / cancel the subscription (login required) -------
        $app->post('/tools/billing-portal', function (Request $req, Response $res) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (!$user->isAuthenticated() || $user->userId() === null) {
                return self::json($res, ['error' => 'Bitte anmelden.', 'code' => 'login_required'], 401);
            }
            $customer = $c->get(SubscriptionRepository::class)->customerFor((int) $user->userId());
            if ($customer === null) {
                return self::json($res, ['error' => 'Zu diesem Konto gibt es kein Abo.', 'code' => 'no_subscription'], 404);
            }
            $client = $c->get(StripeClient::class);
            if (!$client->isConfigured()) {
                return self::json($res, ['error' => 'Zahlung nicht konfiguriert.'], 503);
            }
            try {
                $url = $client->createPortalSession(
                    $customer,
                    self::setting($c, 'checkout_cancel_url', 'TOOLS_CHECKOUT_CANCEL_URL', 'https://tools.tracht-digital.de/'),
                );
            } catch (StripeException $e) {
                return self::json($res, ['error' => $e->getMessage()], 502);
            }
            return $url === null
                ? self::json($res, ['error' => 'Kein Kundenportal verfügbar.'], 502)
                : self::json($res, ['url' => $url], 201);
        });

        // --- Admin: the premium stages -----------------------------------------
        $app->get('/admin/tools/tiers', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireManage($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $counts = $c->get(SubscriptionRepository::class)->grantingCountsByTier();
            $tiers = array_map(
                static fn (array $t): array => $t + ['subscribers' => $counts[$t['id']] ?? 0],
                $c->get(TierRepository::class)->all(),
            );
            return self::json($res, [
                'tiers' => $tiers,
                'currency' => self::setting($c, 'currency', 'TOOLS_CURRENCY', 'EUR'),
            ]);
        });

        $app->post('/admin/tools/tiers', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireManage($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $tier = TierPayload::parse((array) $req->getParsedBody());
            if (is_string($tier)) {
                return self::json($res, ['error' => $tier], 422);
            }
            $repo = $c->get(TierRepository::class);
            if ($repo->slugTaken($tier['slug'])) {
                return self::json($res, ['error' => 'Dieser Kurzname ist schon vergeben.'], 409);
            }
            $id = $repo->create($tier);
            $cache = self::fireCache($c, null, null, 'catalog');
            return self::json($res, array_merge(['ok' => true, 'tier' => $repo->find($id)], $cache), 201);
        });

        $app->put('/admin/tools/tiers/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::requireManage($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $id = (int) $args['id'];
            $repo = $c->get(TierRepository::class);
            if ($repo->find($id) === null) {
                return self::json($res, ['error' => 'Stufe nicht gefunden.'], 404);
            }
            $tier = TierPayload::parse((array) $req->getParsedBody());
            if (is_string($tier)) {
                return self::json($res, ['error' => $tier], 422);
            }
            if ($repo->slugTaken($tier['slug'], $id)) {
                return self::json($res, ['error' => 'Dieser Kurzname ist schon vergeben.'], 409);
            }
            $repo->update($id, $tier);
            $cache = self::fireCache($c, null, null, 'catalog');
            return self::json($res, array_merge(['ok' => true, 'tier' => $repo->find($id)], $cache));
        });

        $app->delete('/admin/tools/tiers/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::requireManage($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $id = (int) $args['id'];
            // A stage with subscriptions (even ended ones) stays: their rows
            // point at it, and a running one still unlocks its tools by it.
            // It is taken off sale with "aktiv" instead.
            if ($c->get(SubscriptionRepository::class)->countForTier($id) > 0) {
                return self::json($res, ['error' => 'Die Stufe hat Abos. Statt zu löschen bitte deaktivieren.', 'code' => 'has_subscriptions'], 409);
            }
            if (!$c->get(TierRepository::class)->delete($id)) {
                return self::json($res, ['error' => 'Stufe nicht gefunden.'], 404);
            }
            $cache = self::fireCache($c, null, null, 'catalog');
            return self::json($res, array_merge(['ok' => true], $cache));
        });

        // --- Designsystem-Studio ------------------------------------------------
        //
        // The studio runs in the visitor's browser; these routes are what must
        // NOT run there: who may save, which formats are premium, and the one
        // network fetch an analysis needs. Like /tools/entitlement they are
        // called by visitors and never by the site with its key.
        $app->get('/tools/studio/access', function (Request $req, Response $res) use ($c): Response {
            return self::json($res, self::studioAccess($c));
        });

        $app->get('/tools/studio/projects', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireStudioPremium($c, $res)) !== null) {
                return $deny;
            }
            $uid = (int) $c->get(UserContext::class)->userId();
            return self::json($res, ['projects' => $c->get(StudioProjectRepository::class)->listFor($uid)]);
        });

        $app->post('/tools/studio/projects', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireStudioPremium($c, $res)) !== null) {
                return $deny;
            }
            $uid = (int) $c->get(UserContext::class)->userId();
            $input = self::studioPayload((array) $req->getParsedBody());
            if (is_string($input)) {
                return self::json($res, ['error' => $input, 'code' => 'invalid_project'], 422);
            }
            $repo = $c->get(StudioProjectRepository::class);
            $limit = self::studioProjectLimit($c);
            if ($repo->countFor($uid) >= $limit) {
                return self::json($res, ['error' => "Höchstens {$limit} Projekte — bitte ein altes löschen.", 'code' => 'limit'], 409);
            }
            $id = $repo->create($uid, $input['name'], $input['schema'], $input['json']);
            return self::json($res, ['project' => $repo->summary($uid, $id)], 201);
        });

        $app->get('/tools/studio/projects/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::requireStudioPremium($c, $res)) !== null) {
                return $deny;
            }
            $uid = (int) $c->get(UserContext::class)->userId();
            $project = $c->get(StudioProjectRepository::class)->find($uid, (int) $args['id']);
            return $project === null
                ? self::json($res, ['error' => 'Projekt nicht gefunden.'], 404)
                : self::json($res, ['project' => $project]);
        });

        $app->put('/tools/studio/projects/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::requireStudioPremium($c, $res)) !== null) {
                return $deny;
            }
            $uid = (int) $c->get(UserContext::class)->userId();
            $input = self::studioPayload((array) $req->getParsedBody());
            if (is_string($input)) {
                return self::json($res, ['error' => $input, 'code' => 'invalid_project'], 422);
            }
            $repo = $c->get(StudioProjectRepository::class);
            if (!$repo->update($uid, (int) $args['id'], $input['name'], $input['schema'], $input['json'])) {
                return self::json($res, ['error' => 'Projekt nicht gefunden.'], 404);
            }
            return self::json($res, ['project' => $repo->summary($uid, (int) $args['id'])]);
        });

        $app->delete('/tools/studio/projects/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::requireStudioPremium($c, $res)) !== null) {
                return $deny;
            }
            $uid = (int) $c->get(UserContext::class)->userId();
            $ok = $c->get(StudioProjectRepository::class)->delete($uid, (int) $args['id']);
            return $ok ? self::json($res, ['ok' => true]) : self::json($res, ['error' => 'Projekt nicht gefunden.'], 404);
        });

        $app->post('/tools/studio/analyze', function (Request $req, Response $res) use ($c): Response {
            if (!self::studioEnabled($c)) {
                return self::json($res, ['error' => 'Das Designsystem-Studio ist deaktiviert.', 'code' => 'disabled'], 403);
            }
            if (self::setting($c, 'studio_analysis_enabled', 'TOOLS_STUDIO_ANALYSIS', '1') !== '1') {
                return self::json($res, ['error' => 'Die Website-Analyse ist abgeschaltet.', 'code' => 'disabled'], 403);
            }
            $url = trim((string) (((array) $req->getParsedBody())['url'] ?? ''));
            try {
                // Validate first: a refused address costs no rate-limit slot
                // and never touches DNS.
                UrlGuard::parse($url);
                $perHour = max(1, (int) self::setting($c, 'studio_analysis_per_hour', 'TOOLS_STUDIO_ANALYSIS_PER_HOUR', '20'));
                if (!$c->get(FileRateLimiter::class)->allow('studio-analyze|' . self::clientIp($req), $perHour, 3600)) {
                    throw new StudioFetchException('rate_limited', 'Zu viele Analysen in kurzer Zeit.');
                }
                return self::json($res, $c->get(PageFetcher::class)->fetch($url));
            } catch (StudioFetchException $e) {
                return self::json($res, array_filter([
                    'error' => $e->getMessage(),
                    'code' => $e->errorCode,
                    'location' => $e->location,
                ], static fn ($v) => $v !== null), $e->httpStatus());
            } catch (Throwable $e) {
                error_log('[tools] studio analysis failed: ' . $e->getMessage());
                return self::json($res, ['error' => 'Die Seite konnte nicht analysiert werden.', 'code' => 'fetch_failed'], 502);
            }
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
            if (!StripeWebhook::verify($payload, $req->getHeaderLine('Stripe-Signature'), $secret)) {
                return self::json($res, ['error' => 'Invalid signature'], 400);
            }
            $event = json_decode($payload, true);
            $type = is_array($event) ? (string) ($event['type'] ?? '') : '';
            // Subscriptions first: a subscription checkout is also a
            // `checkout.session.completed`, but it grants nothing by itself —
            // the row it creates waits for the subscription's own status.
            $subscription = is_array($event) ? SubscriptionEvent::fromStripe($event) : null;
            if ($subscription !== null) {
                $c->get(SubscriptionRepository::class)->upsert(
                    $subscription['subscriptionId'],
                    $subscription['userId'],
                    $subscription['tierId'],
                    $subscription['customerId'],
                    $subscription['status'],
                    $subscription['periodEnd'],
                    $subscription['cancelAtPeriodEnd'],
                );
                return self::json($res, ['received' => true]);
            }
            // `completed` is not `paid`: a delayed method (SEPA, bank transfer)
            // completes the session `unpaid` and confirms later with
            // `async_payment_succeeded`. The tool used to unlock before the
            // money arrived — and stayed unlocked if it never did.
            $session = is_array($event) ? ($event['data']['object'] ?? []) : [];
            $paid = $type === 'checkout.session.async_payment_succeeded'
                || ($type === 'checkout.session.completed' && ($session['payment_status'] ?? null) === 'paid');
            // One-off purchases: no longer sold, but a session started before
            // the switch can still complete or confirm a delayed payment.
            if ($paid && ($session['mode'] ?? 'payment') === 'payment') {
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

    /**
     * Everything the studio (and the site's export endpoint, forwarding the
     * visitor's cookie) needs to know about one visitor. Never fails: a
     * database hiccup answers "not entitled", which keeps premium closed.
     *
     * @return array<string,mixed>
     */
    private static function studioAccess(ContainerInterface $c): array
    {
        $user = $c->get(UserContext::class);
        $authenticated = $user->isAuthenticated() && $user->userId() !== null;
        $entitled = false;
        if ($authenticated) {
            try {
                $entitled = $c->get(PremiumAccess::class)->isEntitled((int) $user->userId(), StudioPolicy::TOOL_ID, $user->isAdmin());
            } catch (Throwable) {
                $entitled = false;
            }
        }
        $tier = self::tierFor($c, StudioPolicy::TOOL_ID);
        return [
            'enabled' => self::studioEnabled($c),
            'authenticated' => $authenticated,
            'entitled' => $entitled,
            'formats' => StudioPolicy::formats(self::setting($c, 'studio_premium_formats', 'TOOLS_STUDIO_PREMIUM_FORMATS', StudioPolicy::DEFAULT_PREMIUM)),
            // The monthly price of the cheapest stage with the studio; without
            // one, the legacy one-off setting (no longer sold, kept for display).
            'priceCents' => $tier !== null ? $tier['price_cents'] : self::studioPrice($c),
            'priceInterval' => $tier !== null ? 'month' : 'once',
            'tier' => $tier['slug'] ?? null,
            'currency' => self::setting($c, 'currency', 'TOOLS_CURRENCY', 'EUR'),
            'analysisEnabled' => self::setting($c, 'studio_analysis_enabled', 'TOOLS_STUDIO_ANALYSIS', '1') === '1',
            'projectLimit' => self::studioProjectLimit($c),
        ];
    }

    /** The cheapest stage on sale that unlocks the tool; null without one or without the tables. */
    private static function tierFor(ContainerInterface $c, string $toolId): ?array
    {
        try {
            return $c->get(TierRepository::class)->cheapestFor($toolId);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Open the monthly Stripe checkout for a stage. One running subscription
     * per account: a second one would bill twice, so the visitor is sent to
     * the customer portal instead (409 `has_subscription`).
     *
     * @param array<string,mixed> $tier
     */
    private static function startSubscription(ContainerInterface $c, Response $res, array $tier): Response
    {
        $user = $c->get(UserContext::class);
        $userId = (int) $user->userId();
        $subscriptions = $c->get(SubscriptionRepository::class);
        if ($subscriptions->grantingFor($userId) !== []) {
            return self::json($res, ['error' => 'Es läuft schon ein Premium-Abo. Wechsel und Kündigung über die Abo-Verwaltung.', 'code' => 'has_subscription'], 409);
        }
        $client = $c->get(StripeClient::class);
        if (!$client->isConfigured()) {
            return self::json($res, ['error' => 'Zahlung nicht konfiguriert.'], 503);
        }
        try {
            $session = $client->createSubscriptionCheckout(
                $userId,
                (int) $tier['id'],
                'Online-Tools Premium: ' . $tier['name'],
                (int) $tier['price_cents'],
                self::setting($c, 'currency', 'TOOLS_CURRENCY', 'EUR'),
                $subscriptions->customerFor($userId),
                $user->email(),
                self::setting($c, 'checkout_success_url', 'TOOLS_CHECKOUT_SUCCESS_URL', 'https://tools.tracht-digital.de/'),
                self::setting($c, 'checkout_cancel_url', 'TOOLS_CHECKOUT_CANCEL_URL', 'https://tools.tracht-digital.de/'),
            );
        } catch (StripeException $e) {
            return self::json($res, ['error' => $e->getMessage()], 502);
        }
        return self::json($res, ['url' => $session['url']], 201);
    }

    /** The catalog switch. No row yet (registry not synced) counts as on. */
    private static function studioEnabled(ContainerInterface $c): bool
    {
        try {
            $row = $c->get(ToolConfigRepository::class)->find(StudioPolicy::TOOL_ID);
        } catch (Throwable) {
            return true;
        }
        return $row === null || (bool) $row['enabled'];
    }

    /** Price of the studio's Premium: the catalog price if the admin made the whole tool premium, else its own setting. */
    private static function studioPrice(ContainerInterface $c): int
    {
        try {
            $row = $c->get(ToolConfigRepository::class)->find(StudioPolicy::TOOL_ID);
            if ($row !== null && $row['is_premium'] && (int) $row['price_cents'] > 0) {
                return (int) $row['price_cents'];
            }
        } catch (Throwable) {
        }
        return max(0, (int) self::setting($c, 'studio_price_cents', 'TOOLS_STUDIO_PRICE_CENTS', '900'));
    }

    private static function studioProjectLimit(ContainerInterface $c): int
    {
        return max(1, (int) self::setting($c, 'studio_project_limit', 'TOOLS_STUDIO_PROJECT_LIMIT', '50'));
    }

    /** 401 without a session, 403 without the entitlement or with the tool switched off. */
    private static function requireStudioPremium(ContainerInterface $c, Response $res): ?Response
    {
        $user = $c->get(UserContext::class);
        if (!$user->isAuthenticated() || $user->userId() === null) {
            return self::json($res, ['error' => 'Bitte anmelden.', 'code' => 'login_required'], 401);
        }
        if (!self::studioEnabled($c)) {
            return self::json($res, ['error' => 'Das Designsystem-Studio ist deaktiviert.', 'code' => 'disabled'], 403);
        }
        if (!$c->get(PremiumAccess::class)->isEntitled((int) $user->userId(), StudioPolicy::TOOL_ID, $user->isAdmin())) {
            return self::json($res, ['error' => 'Speichern ist Premium-Nutzern vorbehalten.', 'code' => 'premium_required'], 403);
        }
        return null;
    }

    /**
     * Validates a project save. The server checks the envelope (an object with
     * a known schema version, a name, a size cap) and stores the rest as-is.
     *
     * @return array{name:string,schema:int,json:string}|string the input, or an error message
     */
    private static function studioPayload(array $body): array|string
    {
        $data = $body['data'] ?? null;
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            return 'Kein gültiges Projekt.';
        }
        $schema = $data['schema'] ?? null;
        if (!is_int($schema) || $schema < 1 || $schema > 100) {
            return 'Unbekannte Projektversion.';
        }
        $name = trim((string) ($body['name'] ?? ($data['name'] ?? '')));
        $name = preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?? '';
        if ($name === '') {
            return 'Bitte einen Projektnamen angeben.';
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > 512_000) {
            return 'Das Projekt ist zu groß (höchstens 512 KB).';
        }
        return ['name' => mb_substr($name, 0, 120), 'schema' => $schema, 'json' => $json];
    }

    /**
     * The visitor's address for rate limiting. Behind the gateway REMOTE_ADDR
     * is the proxy; the gateway appends the address it saw to X-Forwarded-For,
     * so the right-most entry is the one no client can forge.
     */
    private static function clientIp(Request $req): string
    {
        $remote = (string) ($req->getServerParams()['REMOTE_ADDR'] ?? '');
        $forwarded = array_values(array_filter(array_map('trim', explode(',', $req->getHeaderLine('X-Forwarded-For')))));
        $internal = $remote === '' || filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        if ($internal && $forwarded !== []) {
            $last = end($forwarded);
            if (filter_var($last, FILTER_VALIDATE_IP) !== false) {
                return $last;
            }
        }
        return $remote !== '' ? $remote : 'unknown';
    }

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
