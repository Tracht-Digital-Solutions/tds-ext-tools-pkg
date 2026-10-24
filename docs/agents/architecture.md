# Architecture

## Routes

| Route | Access | Purpose |
|---|---|---|
| `GET /tools/catalog` | site key | Catalog overrides for the site |
| `GET /tools/guides` | site key | Panel-edited tool guides |
| `POST /tools/registry` | site key or legacy token | Registry sync from the site |
| `GET /tools/summary` | `tools:manage` | Widget |
| `GET /admin/tools`, `PUT /admin/tools/{id}` | `tools:manage` | Catalog overrides |
| `GET /admin/tools/guides`, `PUT` / `DELETE /admin/tools/guides/{id}/{lang}` | `tools:manage` | Guide editor |
| `POST /admin/tools/cache/rebuild` | `tools:manage` | Manual page-cache refresh |
| `GET` / `DELETE /admin/tools/connection`, `POST /admin/tools/connection/pairing` | `tools:manage` | Site connection and pairing |
| `GET /tools/entitlement` | signed-in session | May this user use a premium tool? (one-off purchase or subscription) |
| `POST /tools/checkout` | signed-in session | Unlock one tool: monthly checkout of the cheapest stage that has it |
| `GET /tools/tiers` | public | The stages on sale (fixed monthly prices, copy, tools) |
| `GET /tools/premium/status` | public (session optional) | The visitor's premium state |
| `POST /tools/subscribe` | signed-in session | Monthly Stripe checkout for a stage |
| `POST /tools/billing-portal` | signed-in session | Stripe customer portal (cancel, card, invoices) |
| `GET` / `POST /admin/tools/tiers`, `PUT` / `DELETE /admin/tools/tiers/{id}` | `tools:manage` | Manage the stages |
| `POST /tools/stripe-webhook` | Stripe signature | Subscriptions (and late one-off payments) |
| `GET /tools/studio/access` | public (session optional) | Designsystem-Studio: enabled, entitled, format policy, price, limits |
| `GET`/`POST /tools/studio/projects`, `GET`/`PUT`/`DELETE /tools/studio/projects/{id}` | session + studio entitlement | Saved studio projects (owner-scoped) |
| `POST /tools/studio/analyze` | public, rate-limited | SSRF-safe fetch of ONE HTTPS page + its stylesheets |

- One permission, **`tools:manage`**, not a read/write pair. Each admin route calls
  `requireManage()` against the core `UserContext` (admins bypass).
- Site-key, registry and pairing details: [site-integration.md](site-integration.md).

## Catalog ownership

**The tool list is owned by the `tds-tool-*` packs, not this backend.** It arrives via
`POST /tools/registry` with the site's composed catalog.
`ToolConfigRepository::upsertRegistry()` inserts missing rows with the manifest defaults
and refreshes name and category, but **never clobbers an admin override**
(`ON DUPLICATE KEY UPDATE name, category` only).

## Settings (core `SettingsStore`, namespace `tools`)

DB first, env fallback. Secrets are AES-GCM at rest. Admins edit them through the core
`/admin/settings/tools` route via the custom settings island.

| Group | Keys |
|---|---|
| AdSense | `ads_enabled`, `adsense_publisher_id`, `adsense_slot_catalog`, `adsense_slot_tool` |
| Page cache | `cache_url`, `cache_token` |
| Stripe | `stripe_secret_key` (blank = central account), `stripe_webhook_secret`, `currency`, `checkout_success_url`, `checkout_cancel_url` |
| Designsystem-Studio | `studio_premium_formats` (comma list of premium formats), `studio_price_cents`, `studio_project_limit`, `studio_analysis_enabled`, `studio_analysis_per_hour`, `studio_analysis_max_stylesheets` |

- **Every declared setting needs a field in `islands/ToolsSettings.tsx`.** The manifest
  registers a custom settings island, so the generic settings UI never renders these keys;
  a `SettingDef` without an input is invisible. `ToolsSettings.test.tsx` compares the
  posted key set against `KEYS` in both directions.
- AdSense is **off until switched on**: ads render on a public, indexable site.
- Secrets follow the store's contract: masked on read, **blank on save keeps the value**.
  Two secrets never share one masked state.
- Env reads use `getenv() === false`, never `?? getenv() ?: $d` (the `"0"` / `""` trap).
- Runtime GitHub rebuild settings (`rebuild_repo`, `rebuild_workflow`, `rebuild_token`) were
  removed by migration `ToolsRemoveGithubRuntime`. Releases are CI-owned.

## Premium (monthly stages since 2026-10-24)

- **Premium is sold as monthly stages** (`tools_tier`): name, fixed monthly price, copy in both
  languages and the tools a stage unlocks. Admins manage them freely (`ToolsTiers`). The
  per-tool `price_cents` is no longer sold or edited; `is_premium` still marks a premium tool.
- **One-off purchases stay valid** (`tools_entitlement`); nothing sells them any more. A
  pre-switch session that completes later is still granted (`mode=payment` only).
- **`Service\PremiumAccess` is the one entitlement check**: admin, one-off purchase, or a
  subscription in `SubscriptionRepository::GRANTING` (active, trialing, past_due) whose stage
  lists the tool. It fails closed when the new tables are missing.
- `POST /tools/checkout` stays for the tool gate and the studio: it opens the checkout of the
  **cheapest active stage** with the tool (400 `no_tier` without one).
- **Checkout** (`StripeClient::createSubscriptionCheckout`): `mode=subscription`, inline
  `price_data.recurring.interval=month` (no Stripe product needed), metadata
  `{module: tools, user_id, tier_id}` on the session AND `subscription_data`. A second running
  subscription is refused (409 `has_subscription`); changing or cancelling goes through
  `POST /tools/billing-portal` (the portal must be activated once in Stripe).
- **The webhook is the only writer of `tools_subscription`** (`Service\SubscriptionEvent`).
  `checkout.session.completed` creates the row without a status (the first invoice may still
  fail); `customer.subscription.*` carries status, period end (top level or items, depending on
  the API version) and cancellation. Events without `module=tools` belong to another module on
  the shared account and are ignored. Period ends are stored as UTC (`gmdate`) and handed out
  as ISO 8601 with `Z`; nothing compares them with `NOW()`.
- A price change applies to new subscriptions; Stripe keeps running ones on their price.
- A stage with subscriptions cannot be deleted (409); it is paused with `active = 0`.
- `GET /tools/tiers` is public and NOT a site-key route: prices are public, and keys paired
  before the route existed would not allow it.
- `Service\StripeClient` wraps the contract's `StripeApi`; the module's own key is optional
  (blank = central account). The webhook is declared via `stripeWebhooks()`.

## Designsystem-Studio (tool `design-system-studio`, pack `tds-tool-devkit-pkg`)

- **The tool is free; its Premium is a feature unlock** under the same tool id in
  `tools_entitlement`. `POST /tools/checkout` sells it even though the catalog row is not
  premium, at `studio_price_cents` (the catalog price wins if an admin makes the whole tool
  premium). The tool's on/off switch is its catalog row; every studio route honours it.
- **Premium gates saving and the premium formats.** Saving is enforced here. Exports are
  generated by the site's Node server (`POST /tds/studio/export` in `tds-tools-frontend`),
  which asks `GET /tools/studio/access` with the visitor's cookie first and fails closed.
- `StudioPolicy::DEFAULT_PREMIUM` must equal `DEFAULT_PREMIUM` in the pack's
  `src/studio/policy.ts` (the site's fallback when this API is unreachable).
- **The analysis fetch** (`Studio\PageFetcher`): HTTPS on 443 only, `UrlGuard` blocks
  private/reserved/metadata ranges and internal names, EVERY DNS answer must be public,
  the connection is pinned to the checked IP (`CURLOPT_RESOLVE`), the page is never
  redirected (the target is reported), stylesheets follow ≤ 3 re-checked redirects and must
  be `text/css`, one `@import` level, size/time/count caps, scripts and `on*` stripped
  before the HTML leaves the server. File-based rate limit per client IP (right-most
  `X-Forwarded-For` behind the gateway). The style analysis itself runs in the browser.
- Never list `/tools/studio/*` as site-key routes: visitors call them.

## Data model

Tables `tools_config` (incl. `blog_slug`), `tools_entitlement`, `tools_guide`, `tools_studio_project`,
`tools_tier`, `tools_subscription`.
Migrations `ToolsCreateConfig`, `ToolsCreateEntitlement`, `ToolsCreateGuide`,
`ToolsRemoveGithubRuntime`, `ToolsCreateStudioProject`, `ToolsCreatePremiumTiers` (20261024120001). Unsigned ids (MySQL 8).

## Admin islands

### `ToolsManage` (catalog table)

Every row decides what the public site shows and charges:

- **`enabled`** is the publish switch.
- **`blog_slug`** is the Ratgeber article: a select of the blog CMS's published posts
  (`GET /content/blog`, loaded after the catalog), a slug field when that fails. The repository
  stores only a valid slug; anything else unlinks.
- **`price_cents`** is no longer edited (stages carry the prices) but is sent back unchanged.
- **Nothing is saved until "Speichern".** Checkboxes patch local state only.
- The PUT does **not** echo `name` / `category` / `tool_id`; those belong to the packs.
- Per-row outcomes are toasts carrying the tool's name. A shared status banner made saving
  row 3 wipe row 1's confirmation.
- A second row stays saveable while the first is in flight (no shared busy flag).
- The empty state spells out the transfer steps (see
  [site-integration.md](site-integration.md#registry-sync)); a test pins the wording.

### `ToolGuides` (guide editor)

Cross-fades per tool and language (`Presence`), and the "eigener Text hinterlegt" hint
opens with `Collapse`. Repeatable rows (steps, use cases, FAQ) stay static: they are keyed by
index, and `AnimatePresence` would fade out the wrong row on removal. Tests wait for the name
field after choosing tool or language.

### `ToolsTiers` (premium stages)

- Price typed in euros, sent in cents through tds-shared's `parseEuros`: a typo is refused in
  the form, never rounded into another price.
- Nothing is sent until "Speichern"; a stage with subscriptions has no enabled delete button,
  and deleting asks through `ConfirmDialog`.
- The edit form says that running subscriptions keep their price.

### Widget

`—` on failure, never `0 / 0` (which would claim every tool is hidden).
