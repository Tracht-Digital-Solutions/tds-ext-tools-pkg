/**
 * Copy package.json's version into the two other places that carry it.
 *
 * Run by `_build.yml` right after `npm version <bump>`, and usable by hand.
 *
 * ### Why this is a script and not an inline `node -e`
 *
 * The release step already rewrote `composer.json` with a one-liner. Adding the
 * manifest to it meant a regex inside a double-quoted `node -e` inside a YAML
 * block scalar — three levels of escaping for something nobody would review.
 *
 * ### Why the manifest is in here at all
 *
 * `src/index.ts`'s `version` is what the panel's Module page reports, so a stale
 * value misstates what is deployed. It was never bumped by anything and sat at
 * `0.1.0` while the package reached `0.1.22`. `tests/packaging.test.ts` asserts
 * all three agree, which only stays true while this runs.
 */
import { readFileSync, writeFileSync } from "node:fs";

const pkg = JSON.parse(readFileSync("package.json", "utf8"));
const version = pkg.version;
if (!/^\d+\.\d+\.\d+/.test(version)) {
  throw new Error(`package.json version looks wrong: ${version}`);
}

// composer.json — the Composer half of the same release.
const composerRaw = readFileSync("composer.json", "utf8");
const composer = JSON.parse(composerRaw);
composer.version = version;
writeFileSync(
  "composer.json",
  JSON.stringify(composer, null, 2) + (composerRaw.endsWith("\n") ? "\n" : ""),
);

// src/index.ts — the extension manifest the panel reads.
const manifestPath = "src/index.ts";
const before = readFileSync(manifestPath, "utf8");
const after = before.replace(/(\n\s*version:\s*")[^"]*(")/, `$1${version}$2`);
if (after === before) {
  throw new Error(`could not find a version field in ${manifestPath}`);
}
writeFileSync(manifestPath, after);

console.log(`synced version ${version} -> composer.json, ${manifestPath}`);
