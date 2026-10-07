# AGENTS.md — tds-ext-tools-pkg

Backend and admin UI for the **public tools platform**. The public site
(`tds-tools-frontend`, server-rendered behind a page cache) is a separate repo; the tools
themselves live in the `tds-tool-*` packs. This extension owns the catalog overrides
(enabled, login, premium, price), AdSense config, the panel-editable tool guides, the
registry sync, premium entitlements with Stripe Checkout, and the site's page-cache
refresh. It contributes a PHP `Module` to `tds-core-frontend-api` and the management UI
to the admin product.

Read `tds-frontend-contract-pkg/AGENTS.md` and `tds-tools-contract-pkg/AGENTS.md` first.

## Commands

```bash
npm install --no-package-lock   # never npm ci
npm run type-check
npm run lint:primitives
npm run test:run                # vitest
npm run build                   # tsup
composer install && composer test   # phpunit; DB tests need TDS_TEST_DB_DSN
```

## Hard rules

- **Every push to `main` publishes a `@latest` patch** and dispatches a `tds-admin-frontend` **build** (`dev.yml`), never a deploy. A docs-only commit carries `[skip ci]`.
- The registry sync never clobbers an admin override.
- `/tools/catalog` and `/tools/guides` fail soft on the site, so breakage here must be caught by tests in this repo.
- Every declared setting needs a field in `islands/ToolsSettings.tsx`.
- **Bind container entries unconditionally.** Every class reference in `php/src` must resolve (`ClassReferencesTest`).
- Never declare `/tools/registry`, `/tools/entitlement` or `/tools/checkout` as site-key routes.
- Prices are edited in euros and stored in cents; nothing is saved until "Speichern".
- Call the API with `apiFetch`. Per-row outcomes are toasts. Never mount a `ToastHost`.
- Stay in the `0.5.x` line (admin product pins `^0.5.x`).

## Topic files

| File | Read before |
|---|---|
| [docs/agents/architecture.md](docs/agents/architecture.md) | Changing routes, settings, premium/Stripe or the admin islands |
| [docs/agents/site-integration.md](docs/agents/site-integration.md) | Touching the registry sync, site keys, pairing, public reads or cache refresh |
| [docs/agents/backend.md](docs/agents/backend.md) | Touching PHP, DI bindings, `use` statements, migrations or `php/docs/api.php` |
| [docs/agents/frontend-conventions.md](docs/agents/frontend-conventions.md) | Touching any island, page or widget markup |
| [docs/agents/testing.md](docs/agents/testing.md) | Writing or changing tests |
| [docs/agents/release.md](docs/agents/release.md) | Releasing or changing CI |

Workspace rules: `../CLAUDE.md`. Cross-repo state: `../MIGRATION-STATUS.md`.
