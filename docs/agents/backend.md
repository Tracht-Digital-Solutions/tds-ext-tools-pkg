# Backend

The PHP half is a `Module` (`php/src/ToolsModule.php`, extends `AbstractModule`) that the
composed `tds-core-frontend-api` mounts in one process with every other extension.

Classes: `Domain\{ToolConfigRepository,ToolGuideRepository,EntitlementRepository}`,
`Service\StripeClient`. The module implements `ApiDocSource`, `SiteKeyProtected` and
`StripeWebhookSource`.

## Bind container entries unconditionally

Never guard a binding with `!$c->has(X::class)`. PHP-DI answers `has()` from its
definition sources, and **autowiring is one of them**: for any concrete class the answer is
always `true`, bound or not. A guard around the bindings never runs, and the container
autowires instead. For the repositories that is invisible; for `StripeClient` (which needs a
`StripeApi`) it is fatal, and the premium checkout and webhook answered 500.
`ExtensionBindingsTest` in `tds-core-frontend-api` pins this. (`has()` on an **interface** the
core may bind, like `SiteCache`, is fine.)

## Every class reference must resolve

`ToolsModule.php` is in `namespace Tds\Ext\Tools`, and an unqualified class name resolves
against that namespace. A missing `use` statement is silent until something resolves it:

- a wrong repository FQCN makes the DI factory throw *Class not found*;
- `Throwable` becomes `Tds\Ext\Tools\Throwable`, so a fail-soft `catch` matches nothing;
- `$c->has()` on a non-existent class is permanently false, so a feature silently no-ops.

`X::class` is a compile-time string, so a wrong name costs nothing until runtime.
**`php/tests/ClassReferencesTest.php` walks every class reference in `php/src` with PHP's own
tokenizer and asserts it resolves.** It uses the lexer, not a regex: a regex version once
stripped `//` inside a URL, swallowed most of the file and reported it clean.

## Local development

`composer.json` depends on the contract VCS-only. For local work, use a temporary `path` repo
or a throwaway `composer.local.json` pointing at `../tds-frontend-contract-pkg`, and never
commit it.

## Migrations (Phinx)

All composed extensions share **one** `phinxlog`, so:

- The migration **class name** and the **numeric version prefix** must be unique across
  every extension. Put the module id in the class name.
- The **file name must map to the class** (`<version>_<snake_case_class>.php`). A
  mismatch throws `Could not find class …` during the scan and aborts **every**
  extension's migrations, not just this one.
- Migrations must survive MySQL 8 (production): no nullable primary-key columns, and
  unsigned ids where the other side is unsigned.

## API reference (`php/docs/api.php`)

The module implements the contract's optional `ApiDocSource`. `php/docs/api.php` returns
one entry per route (summary, params, responses, required permission). The admin
frontend's API reference joins it onto the introspected Slim routes by
`"<METHOD> <pattern>"`.

- **`pattern` must be the Slim pattern verbatim**, inline regex included. A prettified
  path silently produces an orphan doc and an undocumented route.
- **`php/tests/ToolsApiDocsTest.php` asserts both directions:** documented and registered
  routes are the same set, every path placeholder is described, and a named permission
  exists in `permissions()`. Adding or renaming a route without touching `docs/api.php`
  fails there.

## Contract dependency

The published `tds-frontend-contract` is consumed VCS-only on the Composer side
(`type: vcs`) and from GitHub Packages on the npm side. Never use a `path` repository;
Composer fails on a missing path repo in CI.
