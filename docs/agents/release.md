# Release and CI

Dual package: the TypeScript half publishes to GitHub Packages, the PHP half is released by
Git tag (the Composer release ref).

| Workflow | Trigger | Result |
|---|---|---|
| `ci.yml` | pull request | build and test only |
| `dev.yml` | **push to `main`** | **`@latest` patch release** + tag + dispatch of `tds-admin-frontend` `dev.yml` |
| `release.yml` | manual button | minor or major release |

- Unlike most extensions, **every push to `main` is a real release.** The bump commit carries
  `[skip ci]`, so it doesn't loop. Use `[skip ci]` yourself for a commit that must not publish
  (for example docs only).
- Don't bump versions by hand, and don't dispatch a second release after a push.
- The release bumps `package.json` **and** `composer.json` in lockstep and pushes an
  **annotated** tag (Composer resolves by tag; `--follow-tags` skips lightweight tags).
- The post-publish dispatch targets the admin product's **`dev.yml`** (a build), never its
  `release.yml` (a deploy). See [site-integration.md](site-integration.md#ci-rebuild-of-the-admin-product).
- CI installs with **`npm install --no-package-lock`**, never `npm ci`.
- `PACKAGE_TOKEN` installs the contract and publishes; CI sets `NPM_TOKEN` from it. Pruning old
  package versions is `continue-on-error` and needs `delete:packages`.
- Both `composer test` and `npm run test:run` gate CI.
- The admin product caret-pins `^0.5.x`; under 0.x that is minor-locked. A minor bump needs a
  repin there.
