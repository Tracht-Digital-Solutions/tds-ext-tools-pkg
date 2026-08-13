# AGENTS.md — tds-ext-tools-pkg

Authoritative architecture/gotcha doc. Read before non-trivial changes. See the
root `CLAUDE.md` for cross-repo conventions and `tds-frontend-contract-pkg` for the
extension model.

## What this is

The backend + admin UI for the public tools platform. The public site
(`tds-tools-frontend`) is a separate static repo; this extension owns the catalog config,
AdSense config, registry sync and rebuild trigger. Modelled on `tds-ext-billing-pkg`
(Stripe/settings/webhook patterns) + `tds-ext-blog-cms-pkg` (`RebuildTrigger`) +
`tds-ext-contact-tickets-pkg` (public + token-gated endpoints).

## Architecture

- **The tool list is owned by the frontend packs, not this backend.** It flows in
  via `POST /tools/registry` (token-gated), which the `tds-tools-frontend` build calls with
  its composed catalog. `ToolConfigRepository::upsertRegistry()` inserts missing
  rows with the manifest defaults and refreshes name/category, but **never
  clobbers an admin override** (`ON DUPLICATE KEY UPDATE name, category` only).
- **`GET /tools/catalog` is public** (unauthenticated) — the site bakes it at
  build time (+ a runtime fallback). Every other route is `tools:manage` except
  the token-gated registry sync.
- **Config via the core `SettingsStore` (ns=`tools`), DB-first + env fallback.**
  AdSense (publisher id + slots + master switch), the rebuild target
  (repo/workflow/token), and the registry-sync token. Secrets AES-GCM at rest;
  admin edits them through the core `/admin/settings/tools` route (the FE settings
  island), not a module route.
- **An admin override change fires a rebuild** of the static site
  (`RebuildTrigger`, best-effort `workflow_dispatch`, never throws).

## Gotchas

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
npm run test:run    # vitest, 122 tests (jsdom per-file via a @vitest-environment docblock)
```

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
