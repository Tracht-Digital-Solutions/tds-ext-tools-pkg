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

All 14. `php/docs/api.php` is the authoritative copy and is parity-tested
against the mounted routes; this table is not, so it listed 6 of 14 for months.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/tools/catalog` | public (site key) | Admin overrides + ads config for the site |
| GET | `/tools/guides` | public (site key) | Panel-edited copy for each tool page |
| POST | `/tools/registry` | site key / token | Upsert the known tools (run from `/install`) |
| GET | `/admin/tools` | `tools:manage` | Rows for the management UI |
| PUT | `/admin/tools/{id}` | `tools:manage` | Update overrides + fire rebuild |
| POST | `/admin/tools/rebuild` | `tools:manage` | Dispatch a CI rebuild of the site |
| GET | `/admin/tools/guides` | `tools:manage` | Every stored guide override |
| PUT | `/admin/tools/guides/{id}/{lang}` | `tools:manage` | Save one guide + rebuild that page |
| DELETE | `/admin/tools/guides/{id}/{lang}` | `tools:manage` | Drop one guide + rebuild that page |
| POST | `/admin/tools/cache/rebuild` | `tools:manage` | Re-render the site's cached pages |
| GET | `/tools/summary` | `tools:manage` | Dashboard widget counts |
| GET | `/tools/entitlement` | session | Has this user bought this tool? |
| POST | `/tools/checkout` | session | Start Stripe Checkout for a premium tool |
| POST | `/tools/stripe-webhook` | HMAC signature | Grant on `checkout.session.completed` |

## Settings (core SettingsStore, ns=`tools`)

All 15, and **every one has a field in the settings panel** — seven of them did
not until 0.3.0, which made the page-cache rebuild and the whole premium layer
configurable only by editing `.env` on the host. On this Plesk host that is the
same as not configurable at all.

- **AdSense** — `ads_enabled`, `adsense_publisher_id`, `adsense_slot_catalog`,
  `adsense_slot_tool`
- **Website-Rebuild (CI)** — `rebuild_repo`, `rebuild_workflow`
  (default `release.yml`), `rebuild_token` (secret), `registry_token` (secret)
- **Seiten-Cache** — `cache_url`, `cache_token` (secret). Re-renders single
  pages in seconds; distinct from the CI rebuild, which ships code.
- **Premium (Stripe)** — `stripe_secret_key` (secret),
  `stripe_webhook_secret` (secret), `currency`, `checkout_success_url`,
  `checkout_cancel_url`

DB-first, env fallback. Secrets are AES-GCM encrypted at rest and come back
masked; a blank secret on save means "keep the existing value".

## Develop

```bash
composer install && composer test   # phpunit (DB tests skip without TDS_TEST_DB_DSN)
npm install && npm run build && npm run type-check
```

See `AGENTS.md` for the local `path`-repo composer recipe and gotchas.
