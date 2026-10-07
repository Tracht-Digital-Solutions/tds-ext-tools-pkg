# Site integration

How `tds-tools-frontend` and this module talk to each other.

## Public reads fail soft

`GET /tools/catalog` and `GET /tools/guides` are read by the site while rendering and stored
in its page cache. **Both fail soft on the site:** a 500 here is indistinguishable from "no
overrides", so the tool page renders its committed text and the deploy looks healthy.
Anything broken behind these two routes must be caught by a test in this repo.

## Site keys (`SiteKeyProtected`)

`siteKeyRoutes()` declares `/tools/catalog` and `/tools/guides`. The exclusions matter more:

- **`/tools/registry` is not declared.** It checks its own credentials; going through the
  middleware too would reject a legacy-token call before the route sees it.
- **`/tools/entitlement` and `/tools/checkout` are not declared.** They run in a visitor's
  browser, which has no key. Declaring them would turn `enforce` into a paywall that rejects
  paying customers.

## Registry sync

`POST /tools/registry` accepts two credentials:

1. A **site key** for the `tools` site (`X-TDS-Site-Key`, or `token` / `key` / bearer). The key
   must belong to resource `tools`/`tools` and allow `/tools/registry`. This is the way forward:
   issued in the panel, revocable, with a last-used time.
2. The **legacy `registry_token`** (stored secret or `TOOLS_REGISTRY_TOKEN`), accepted for one
   more release without a panel field. Responses then carry `legacy_auth: true`.

Neither configured → **503** naming both. Wrong credential → 401.

**The site id comes from the verified key, never from the body.** Trusting a `site` field
would let the blog's key rewrite the tools catalog. `ToolsModuleTest` pins both directions.

**Nothing pushes the catalog automatically; an operator runs the transfer.** The site publishes
`dist/tools-catalog.json`, and `/install` posts it with the credential typed into its form.
Order matters: set up the credential here first, because the route answers 503 until it exists.

## Site connection and pairing

`/admin/tools/connection` and `/admin/tools/connection/pairing` manage the paired `tools`
site connection (contract connections API). A pairing is delivered to the site with the API
base derived from the request.

## Page-cache refresh

Saving an override, a guide or the registry fires a **page-cache** refresh via
`fireCache()`:

- With a paired connection and a `ConnectedSiteCache`, it refreshes through the connection.
- Otherwise it uses the core `SiteCache` (`cache_url` / `cache_token`).
- `has()` is legitimate here because `SiteCache` is an **interface** the core may or may not
  bind (unlike concrete classes; see [backend.md](backend.md)).
- A cache failure never fails a save; the result is reported alongside the save.

## CI rebuild of the admin product

Separate from the page cache: after a `@latest` publish, `_build.yml` dispatches
`rebuild_workflow` (default **`dev.yml`**, a build) in **`tds-admin-frontend`**. It must never
target that product's `release.yml`: the products are Node applications, and a push to their
`release` branch takes the panel down until Plesk restarts it. Deploying a product is a
decision made in that product's repo.
