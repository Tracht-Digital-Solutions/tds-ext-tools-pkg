# AGENTS.md — tds-ext-tools-pkg

Authoritative architecture/gotcha doc. Read before non-trivial changes. See the
root `CLAUDE.md` for cross-repo conventions and `tds-frontend-contract-pkg` for the
extension model.

## What this is

The backend + admin UI for the public tools platform. The public site
(`tds-tools-frontend`) is a separate repo — server-rendered behind a page cache
since 2026-08-24, not static; this extension owns the catalog config, AdSense
config, the panel-editable tool guides, registry sync and both rebuild triggers
(CI build and page cache). Modelled on `tds-ext-billing-pkg`
(Stripe/settings/webhook patterns) + `tds-ext-blog-cms-pkg` (`RebuildTrigger`) +
`tds-ext-contact-tickets-pkg` (public + token-gated endpoints).

## Architecture

- **The tool list is owned by the frontend packs, not this backend.** It flows in
  via `POST /tools/registry` (token-gated) with the site's composed catalog.
  `ToolConfigRepository::upsertRegistry()` inserts missing rows with the manifest
  defaults and refreshes name/category, but **never clobbers an admin override**
  (`ON DUPLICATE KEY UPDATE name, category` only).
- **Nothing pushes that catalog automatically — a human runs the transfer.** The
  `tds-tools-frontend` *build* used to, gated on `TOOLS_REGISTRY_TOKEN`; no
  workflow ever exported it, and without a `PUBLIC_` prefix Vite would not have
  injected it anyway, so the sync never ran once and `tools_config` stayed empty
  for the platform's whole life. Nothing went red: the sync fails soft by design.
  The dead path is gone from `catalog.ts` (2026-08-16). The transfer is now
  host-side: the site publishes `dist/tools-catalog.json` and
  `/install` posts it with the token typed into its form.
  **Two steps, in order** — store the token here first (*Einstellungen → Tools*),
  because `POST /tools/registry` answers **503** until it exists, and a wizard run
  before that lands in an error with no visible cause. `ToolsManage`'s empty state
  spells both steps out; it used to promise the tools would "appear automatically",
  which is precisely why nobody went looking. Its test now pins that wording.
- **`GET /tools/catalog` and `GET /tools/guides` are public** (site-key gated,
  see `siteKeyRoutes()`) — the site reads them while rendering and stores the
  result in its page cache. Every other route is `tools:manage`, except the
  token-gated registry sync, the two session-gated premium routes and the
  signature-verified Stripe webhook.
  **Both fail soft on the site**, which is the trap: a 500 here is
  indistinguishable from "no overrides", so the tool page renders its committed
  text and the deploy looks healthy. Anything broken behind these two routes has
  to be caught by a test in this repo, because no other surface will report it.
- **Config via the core `SettingsStore` (ns=`tools`), DB-first + env fallback.**
  AdSense (publisher id + slots + master switch), the rebuild target
  (repo/workflow/token), and the registry-sync token. Secrets AES-GCM at rest;
  admin edits them through the core `/admin/settings/tools` route (the FE settings
  island), not a module route.
- **An admin override change fires a CI rebuild** of the site
  (`RebuildTrigger`, best-effort `workflow_dispatch`, never throws), while a
  guide edit fires a **page-cache** rebuild through the contract's `SiteCache`
  (`fireCache()`). Two different jobs: the first ships code, the second
  re-renders pages from content already saved. Neither ever fails a save.

## Gotchas

- **A missing `use` statement is silent, and it took the whole guides feature
  down for the life of the release (fixed 0.3.0).** `ToolsModule.php` is in
  `namespace Tds\Ext\Tools`, and an unqualified class name resolves against
  *that* namespace. Four were missing, with three different symptoms:
  `ToolGuideRepository` (really `…\Tools\Domain\…`) made the DI factory throw
  *Class not found*, so all four guide routes fatalled; `Throwable` resolved to
  `Tds\Ext\Tools\Throwable`, so the fail-soft `catch` on the public route
  matched nothing and a DB hiccup became a 500; and `SiteCache` / `CacheEvent`
  resolved into this namespace too, where `$c->has()` on a class that does not
  exist is permanently false — making `fireCache()` an unconditional no-op, so
  saving a guide answered `{"ok":true}` and never rebuilt the page.
  Nothing was red: no test touched a guide or a cache route, the doc-parity test
  compares only method + pattern, and the public site turns any non-OK response
  into "no overrides" and renders its committed text. `X::class` is a
  compile-time string, so even a wholly wrong FQCN costs nothing until something
  resolves it. **`php/tests/ClassReferencesTest.php` now walks every class
  reference in `php/src` with PHP's own tokenizer and asserts it resolves.** It
  uses the lexer rather than a regex on purpose: the first version stripped
  `//` comments before strings, ate the `//` inside a URL, left an unterminated
  quote, swallowed 80% of the file and then reported it clean.
- **The rebuild workflow default is `release.yml`, not `dev.yml`.**
  `tds-tools-frontend` deleted its `dev.yml` on 2026-08-24 when the deploy
  stopped running on every push. `RebuildTrigger` is best-effort and never
  throws, so the stale default made every catalog-change dispatch 404 in
  silence. If that workflow is renamed again, this default and
  `islands/ToolsSettings.tsx` both have to follow.
- **There is a SECOND rebuild trigger, in CI, and it points the other way.**
  Don't confuse them: the bullet above is `RebuildTrigger`, PHP, at request
  time, aimed at `tds-tools-frontend`. This one is `_build.yml`'s *Dispatch site
  rebuild* step, aimed at **`tds-admin-frontend`** after a `@latest` publish —
  and since `dev.yml` auto-releases a patch on every push to `main`, it fires on
  every commit here. It named `release.yml` until 2026-08-25, which meant a
  commit in this repo **deployed the admin panel to production**. That was
  survivable while the panel was a folder of static files; the products are Node
  applications now, so a push to their `release` branch takes the panel down on
  every path until Plesk restarts it. The step now takes a `rebuild_workflow`
  input defaulting to **`dev.yml`** — a build, never a deploy. Deploying a
  product stays a decision somebody makes in that product's own repo.
- **Every declared setting needs a field in `ToolsSettings.tsx`.** The manifest
  registers a *custom* settings island, so the generic settings UI never renders
  these keys — a `SettingDef` with no matching input is invisible, and the
  operator's only route to it is editing `.env` on the host. Seven were in that
  state (page cache + the entire Stripe layer). `ToolsSettings.test.tsx`
  compares the posted key set against `KEYS` in both directions, which is what
  makes a forgotten field fail rather than hide.
- **Never guard a container binding with `!$c->has(X::class)` — the premium
  checkout and the Stripe webhook 500'd because of it.** PHP-DI answers `has()`
  out of its definition sources, and *autowiring is one of them*: for any
  concrete, instantiable class the answer is always `true`, bound or not. So the
  single guard wrapping all three bindings never ran, and the container quietly
  autowired instead. For the two repositories that is invisible — their only
  argument is the bound `PDO`. For `StripeClient` it is fatal, because its
  constructor takes a string:

  ```
  Entry "…\Service\StripeClient" cannot be resolved:
  Parameter $secretKey of __construct() has no value defined or guessable
  ```

  The settings-store factory that reads `tools.stripe_secret_key` had never run
  once, so that panel setting was dead as well. Nothing went red: this repo's CI
  runs type-check + build, not tests, and a PHP-DI entry is built lazily. The
  module owns these classes and nothing else defines them, so **bind
  unconditionally**. Pinned by `ExtensionBindingsTest` in
  `tds-core-frontend-api`.

- **Call the API with `apiFetch` from `@tracht-digital-solutions/tds-shared/api`,
  never a relative `fetch`.** Every island used to define its own
  `const api = (path, init) => fetch(path, { credentials: "include", ...init })`
  with a RELATIVE path. In a product that resolves against the product's own
  static host (`management.`/`app.tracht-digital.de`), not the API — and the
  static host answers unknown paths with its SPA fallback, i.e. **200 + HTML**.
  So `res.ok` is `true`, `res.json()` throws, and the usual
  `.catch(() => setRows([]))` renders a calm, permanent empty state with no
  error and no console warning. `apiFetch` resolves the base from
  `<meta name="tds-api-base">` (written by the frontend host) and routes 401s
  through the host's confirm-against-`/me` backstop, which extension calls
  previously skipped entirely.
  The island tests match on the request PATH (`pathOf()`), which a relative
  fetch satisfies just as well — so one assertion per suite pins the **absolute
  host**. That is the line that fails if this ever regresses.


- **Migration class name is `ToolsCreateConfig`** (module-id prefixed) and the
  version prefix is globally unique — the in-process auto-migrator loads every
  module's migrations into ONE phinxlog; a reused class name is a fatal
  redeclaration.
- **…and the FILE name must map to that class**: `20260720000001_tools_create_config.php`
  ⇒ `ToolsCreateConfig`. Phinx derives the expected class from the file name and throws
  `Could not find class …` while *scanning* the set, so a mismatch aborts the whole
  composed migration run — every extension, not just this one. Both files here were
  originally `create_tools_*` (⇒ `CreateTools*`) against `ToolsCreate*` classes and were
  renamed in 0.1.10; nothing had ever migrated. Verify with a real `phinx migrate`, not by
  reading — phpunit does not run migrations.
- **Per-row outcomes are toasts (tds-shared `>=0.16.0`), never one shared
  banner.** `ToolsManage` is a table, and a single `status` string for all rows
  meant saving row 3 wiped row 1's confirmation — while the banner itself sat at
  the top of the table, far from the button that produced it. Toasts carry the
  tool's name, so the confirmation says *which* row was saved. Never mount a
  `ToastHost` here; the frontend host owns the only one.
- **`env()` uses the safe `getenv() === false` check**, never `?? getenv() ?: $d`
  (the "0"/"" precedence trap).
- **RBAC is the module's job** — each admin route calls `requireManage()` against
  the core `UserContext` (admins bypass). The registry route is token-gated
  (`hash_equals`), the catalog route is intentionally open.
- **Composer depends on the contract VCS-only** in the committed `composer.json`.
  For local dev/test, add a temporary `path` repo (or use a throwaway
  `composer.local.json`) pointing at `../tds-frontend-contract` — Composer FATALs on
  a missing sibling `path` repo in an isolated CI checkout, so never commit one.
- **DB tests skip without `TDS_TEST_DB_DSN`** and run against real MariaDB/MySQL
  (drop/recreate `tools_config`). Keep migrations MySQL-8-safe.
- Version stays in the `0.1.x` line (the admin product + host pin `^0.1.x`).

## Tests

```bash
npm run test:run    # vitest, 134 tests (jsdom per-file via a @vitest-environment docblock)
composer test       # phpunit, 22 tests (3 skip without TDS_TEST_DB_DSN)
```

**Both run in CI as of 0.3.0.** `composer test` always did; `npm run test:run`
did not, so ~1 200 lines of island tests gated nothing.

- `islands/ToolsManage.test.tsx` — the catalog table. Every row decides what the
  PUBLIC site shows and what it charges, so three things are pinned hard:
  **`enabled`** (the publish switch), **`price_cents`** (edited in EUROS, stored
  in CENTS — a 100× slip is a billing bug, and the clamp/rounding are covered at
  their edges), and that **nothing is saved until Speichern** — the checkboxes
  patch local state only, so a stray click cannot publish a tool by itself. The
  PUT is also asserted NOT to echo back `name`/`category`/`tool_id`: those are
  owned by the `tds-tool-*` packs via the registry sync, and sending them would
  let the admin table overwrite the packs' own metadata.
- `islands/ToolsSettings.test.tsx` — AdSense + the rebuild target + the two
  secrets. Both tokens follow the store's contract (masked on read, **blank on
  save = keep existing**) and are asserted to stay APART: one shared masked
  state would make a configured registry token look like a configured rebuild
  PAT. AdSense is off until switched on — ads render on a public, indexable site.
- `islands/WidgetBody.test.tsx` — the widget's states; `—` on a failure, never
  `0 / 0`, which would claim every tool is hidden.
- `src/index.test.ts` + `tests/packaging.test.ts` — the manifest as a product
  build sees it (this extension has ONE permission, `tools:manage`, not a
  read/write pair) and that every specifier resolves, is exported, and ships.

Error-path tests deliberately answer with a POPULATED body and a non-OK status.
Against an EMPTY error body `res.ok ? (await res.json()).x ?? [] : []` and a bare
`await res.json()` are indistinguishable, so the ok-check could be deleted with
no test noticing.

Two tests exist only because the mutation pass proved the obvious ones blind: a
login-gated FREE tool (both flags true on the premium fixture hides a swap), and
a second row staying saveable while the first is in flight (a shared busy flag
would lock the whole table).

> **Float note:** `Math.round(euros * 100)` is float maths, so a mid-point like
> `1.005 €` lands on `100.4999…` and rounds DOWN to 100 cents. The tests assert
> the real behaviour (`1.999 € → 200`) rather than pretending the artefact away.

Verified by mutation: 51 deliberate breakages introduced, 51 caught.

## Commands

```bash
composer install && composer test
npm install && npm run type-check && npm run test:run && npm run build
```

Enable in a product: add to the admin `astro.config` extension array +
`package.json`, and to `tds-core-frontend-api`'s `Modules::enabled()` + composer
`path` repo.

## Mobile layout

This package ships **no CSS**, so every layout decision is a shared class or a
Tailwind utility, and neither is checked by anything at runtime. Two rules:

- **A row of more than two things — or any row holding a full-width field —
  goes on `.tds-row`, `.tds-list__row` or `.tds-toolbar`.** All three wrap.
  A hand-rolled `flex` does not, and on a 375px screen the overflow is not
  even visible: `body { overflow-x: hidden }` clips it, so the content simply
  is not there.
- **A `<table>` needs `tds-table` and nothing else.** The primitive turns
  itself into a horizontal scroller below 40rem; an extra `overflow-x`
  wrapper or an inline style is redundant. A table with no focusable cell
  also needs `tabindex="0"` + `role="region"` + a label, or its scrollport
  cannot be reached by keyboard.

`npm run lint:primitives` enforces the class part of this (including a
`<table>` without `tds-table` and a flex/grid table cell, which silently
drops the cell out of the column algorithm). It is a **regex scan**, so a tag
name written inside a comment counts as markup — name elements in prose.

## API-Referenz (`php/docs/api.php`)

This module implements the contract's optional `ApiDocSource`: `php/docs/api.php`
returns one entry per route (summary, params, responses, required permission),
and the admin frontend's API reference joins it onto the introspected Slim routes
by `"<METHOD> <pattern>"`. Two things to know before editing a route:

- **`pattern` must be the Slim pattern verbatim**, inline regex included
  (`C:/Program Files/Git/admin/tools/{id}`). A prettified path silently produces an orphan doc *and* an
  undocumented route rather than an error.
- **`php/tests/ToolsApiDocsTest.php` asserts both directions** — the documented
  set and the registered set must be the same set, every path placeholder must
  be described, and a named permission must exist in `permissions()`. Adding or
  renaming a route without touching `docs/api.php` fails there. That is the
  point: prose next to code rots, and a reference full of confident, wrong
  detail is worse than the bare route list it replaced.

## Site keys (`SiteKeyProtected`) + registry auth

`ToolsModule` implements the contract's optional `SiteKeyProtected` and declares
`/tools/catalog` — what the static tools site bakes at build time.

Two exclusions matter more than the inclusion:

- **`/tools/registry` is NOT declared.** It carries its own credential check;
  going through the middleware as well would reject a legacy `registry_token`
  call before the route ever saw it, breaking the one path an operator mid-setup
  is most likely to be on.
- **`/tools/entitlement` and `/tools/checkout` are NOT declared.** They run in a
  visitor's browser on the public site, which has no key and never will. Listing
  one would turn `enforce` into a paywall that rejects paying customers.

`POST /tools/registry` now accepts **two** credentials. A **site key** for the
`tools` site is the way forward — issued in the panel, revocable, and it records
when it was last used. The legacy `registry_token` keeps working for one
release, because a human types it into the `/install` wizard and an operator
mid-setup should not be stopped by an upgrade. The unconfigured 503 names both,
since the old text sent people to *Einstellungen → Tools* even when the intended
fix was a key.

**The site id is passed to `verify()`, never read from the body.** A key belongs
to exactly one site; trusting a `site` field sent alongside the key would let the
blog's key rewrite the tools catalog. `ToolsModuleTest` pins both directions.
