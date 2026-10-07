# Testing

```bash
npm run test:run   # vitest; islands opt into jsdom per file via a @vitest-environment docblock
composer test      # phpunit
```

CI (`_build.yml`) runs both suites, plus type-check, `lint:primitives` and build, on
every run.

## Test environment against the current tds-shared

tds-shared is a **peer** dependency, so a fresh standalone install may resolve an old
version while every product build composes the current one. Keep these in mind:

- `apiFetch` first consults the host runtime config (`/tds-runtime.json`), so
  `fetch.mock.calls[0]` is that probe. Call **`primeRuntimeConfig(null)`** in
  `beforeEach`; panel products never ship that file, so "absent" matches production.
- `apiFetch` is **async**. `await waitFor(() => expect(fetch).toHaveBeenCalled())`
  before reading `mock.calls`.
- A multipart upload carries an **empty** `headers` object. Assert "no content-type
  header", never "headers is undefined".
- Error-path tests answer with a **populated** body and a non-OK status. Against an
  empty error body the ok-check is unobservable.
- At least one assertion per suite pins the **absolute API host** (see
  [frontend-conventions.md](frontend-conventions.md#api-calls-apifetch-never-a-relative-fetch)).

## Suites

| Suite | Sharpest assertions |
|---|---|
| `islands/ToolsManage.test.tsx` | `enabled`; euros → cents with clamp and rounding at the edges; nothing saved before "Speichern"; PUT doesn't echo pack-owned fields; rows independently saveable; empty-state wording |
| `islands/ToolsSettings.test.tsx` | Posted keys equal `KEYS` both ways; secrets masked, blank keeps, kept apart; AdSense off by default |
| `islands/ToolGuides.test.tsx` | Guide editor per tool and language |
| `islands/WidgetBody.test.tsx` | `—` on failure, never `0 / 0` |
| `src/index.test.ts` + `tests/packaging.test.ts` | Manifest (one permission `tools:manage`); specifiers ship |
| `php/tests/ToolsModuleTest.php` | RBAC, registry credentials, site id from the key in both directions |
| `php/tests/ClassReferencesTest.php` | Every class reference in `php/src` resolves |
| `php/tests/ToolsApiDocsTest.php` | API-doc parity |

DB tests skip without `TDS_TEST_DB_DSN` and run against real MariaDB/MySQL (they drop and
recreate `tools_config`). The API-doc parity test compares only method and pattern, so it
can't catch a broken handler.

Two tests exist because the obvious versions were blind: a login-gated **free** tool (both
flags true on the premium fixture hides a swap), and a second row staying saveable while the
first is in flight.

Mutation check: 51 breakages, 51 caught.
