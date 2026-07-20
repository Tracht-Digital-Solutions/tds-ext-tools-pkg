# AGENTS.md — tds-ext-tools

Authoritative architecture/gotcha doc. Read before non-trivial changes. See the
root `CLAUDE.md` for cross-repo conventions and `tds-panel-contract` for the
extension model.

## What this is

The backend + admin UI for the public tools platform. The public site
(`tds-tools`) is a separate static repo; this extension owns the catalog config,
AdSense config, registry sync and rebuild trigger. Modelled on `tds-ext-billing`
(Stripe/settings/webhook patterns) + `tds-ext-blog-cms` (`RebuildTrigger`) +
`tds-ext-contact-tickets` (public + token-gated endpoints).

## Architecture

- **The tool list is owned by the frontend packs, not this backend.** It flows in
  via `POST /tools/registry` (token-gated), which the `tds-tools` build calls with
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

- **Migration class name is `ToolsCreateConfig`** (module-id prefixed) and the
  version prefix is globally unique — the in-process auto-migrator loads every
  module's migrations into ONE phinxlog; a reused class name is a fatal
  redeclaration.
- **`env()` uses the safe `getenv() === false` check**, never `?? getenv() ?: $d`
  (the "0"/"" precedence trap).
- **RBAC is the module's job** — each admin route calls `requireManage()` against
  the core `UserContext` (admins bypass). The registry route is token-gated
  (`hash_equals`), the catalog route is intentionally open.
- **Composer depends on the contract VCS-only** in the committed `composer.json`.
  For local dev/test, add a temporary `path` repo (or use a throwaway
  `composer.local.json`) pointing at `../tds-panel-contract` — Composer FATALs on
  a missing sibling `path` repo in an isolated CI checkout, so never commit one.
- **DB tests skip without `TDS_TEST_DB_DSN`** and run against real MariaDB/MySQL
  (drop/recreate `tools_config`). Keep migrations MySQL-8-safe.
- Version stays in the `0.1.x` line (the admin product + host pin `^0.1.x`).

## Commands

```bash
composer install && composer test
npm install && npm run type-check && npm run build
```

Enable in a product: add to the admin `astro.config` extension array +
`package.json`, and to `tds-core-panel-api`'s `Modules::enabled()` + composer
`path` repo.
