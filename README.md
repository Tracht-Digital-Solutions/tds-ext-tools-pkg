# @tracht-digital-solutions/tds-ext-tools

The **public tools platform admin** — the frontend extension that steers the
`tds-tools-frontend` site (`tools.tracht-digital.de`). Manages the tool catalog config
(which tools are enabled / require login / are premium + price), the AdSense
config, and the site rebuild.

Dual package: a frontend manifest (`@tracht-digital-solutions/tds-ext-tools`, npm)
+ a PHP `Module` (`tracht-digital-solutions/tds-ext-tools`, Composer VCS). Enable
it by adding it to the admin product's `astro.config` extension array **and**
`tds-core-frontend-api`'s `Modules::enabled()`.

## Data flow

The tool **list** is owned by the frontend `tds-tool-*` packs. The `tds-tools-frontend`
site build POSTs its composed catalog to `POST /tools/registry` (token-gated),
which upserts rows into `tools_config` **without** clobbering admin overrides.
The admin edits the overrides (`/admin/tools`); the site reads the merged catalog
back from the public `GET /tools/catalog` at its next build. An admin change fires
a rebuild (`RebuildTrigger`).

## Endpoints (mounted into the core API)

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/tools/catalog` | public | Merged config + ads for the site build |
| POST | `/tools/registry` | token | Upsert the known tools (site build sync) |
| GET | `/admin/tools` | `tools:manage` | Rows for the management UI |
| PUT | `/admin/tools/{id}` | `tools:manage` | Update overrides + fire rebuild |
| POST | `/admin/tools/rebuild` | `tools:manage` | Manual rebuild |
| GET | `/tools/summary` | `tools:manage` | Dashboard widget counts |

## Settings (core SettingsStore, ns=`tools`)

`ads_enabled`, `adsense_publisher_id`, `adsense_slot_catalog`,
`adsense_slot_tool`, `registry_token` (secret), `rebuild_repo`,
`rebuild_workflow`, `rebuild_token` (secret). DB-first, env fallback.

## Develop

```bash
composer install && composer test   # phpunit (DB tests skip without TDS_TEST_DB_DSN)
npm install && npm run build && npm run type-check
```

See `AGENTS.md` for the local `path`-repo composer recipe and gotchas.
