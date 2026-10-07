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
| `GET /tools/entitlement` | signed-in session | Does this user own a premium tool? |
| `POST /tools/checkout` | signed-in session | Start a Stripe Checkout Session |
| `POST /tools/stripe-webhook` | Stripe signature | Grant entitlements |

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

## Premium

- A tool pack declares only defaults (`premiumDefault`, `priceCentsDefault`); the admin
  catalog overrides them.
- `GET /tools/entitlement` and `POST /tools/checkout` require a signed-in user. Admins are
  always entitled.
- `Service\StripeClient` wraps the contract's `StripeApi`; the module's own key is optional
  (blank = central account). The webhook is declared via `stripeWebhooks()`
  (`StripeWebhookSource`) and grants rows in `tools_entitlement`.

## Data model

Tables `tools_config`, `tools_entitlement`, `tools_guide`. Migrations
`ToolsCreateConfig`, `ToolsCreateEntitlement`, `ToolsCreateGuide`,
`ToolsRemoveGithubRuntime`. Unsigned ids (MySQL 8).

## Admin islands

### `ToolsManage` (catalog table)

Every row decides what the public site shows and charges:

- **`enabled`** is the publish switch.
- **`price_cents`** is edited in **euros** and stored in **cents**. A 100× slip is a billing
  bug. `Math.round(euros * 100)` is float maths (`1.005 €` → 100 cents); tests assert the
  real behaviour.
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

### Widget

`—` on failure, never `0 / 0` (which would claim every tool is hidden).
