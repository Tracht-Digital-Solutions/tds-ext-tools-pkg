import { defineExtension } from "@tracht-digital-solutions/tds-frontend-contract";

/**
 * Public tools platform admin manifest. Manages the tool catalog config (which
 * tools are enabled / require login / are premium + price), AdSense, API
 * pairing and targeted cache refreshes for the public `tds-tools` site. The
 * tool *list* itself is owned by the frontend `tds-tool-*` packs and syncs via
 * the paired site server; this extension edits the overrides.
 */
export default defineExtension({
  id: "tools",
  name: "Tools",
  // Bumped by `scripts/sync-version.mjs` from package.json during a release —
  // do not hand-edit. Nothing used to bump it, so it drifted to 0.1.0 against a
  // package on 0.1.22, and this is the string the panel's Module page reports.
  version: "0.4.0",
  permissions: [{ id: "tools:manage", label: "Tools verwalten", group: "tools" }],
  nav: [
    {
      id: "tools",
      label: "Tools",
      href: "/tools-verwaltung",
      icon: "wrench",
      group: "tools",
      order: 50,
      permission: "tools:manage",
    },
  ],
  widgets: [
    {
      id: "tools-status",
      title: "Tools",
      island: "@tracht-digital-solutions/tds-ext-tools/widgets/Widget.astro",
      size: "sm",
      permission: "tools:manage",
      dataEndpoint: "/tools/summary",
      order: 50,
    },
  ],
  settings: [
    {
      id: "tools",
      label: "Tools / AdSense",
      island: "@tracht-digital-solutions/tds-ext-tools/islands/Settings.astro",
      order: 50,
    },
  ],
  routes: [
    {
      pattern: "/tools-verwaltung",
      entrypoint: "@tracht-digital-solutions/tds-ext-tools/pages/Index.astro",
      permission: "tools:manage",
    },
  ],
  i18n: {
    de: { "tools.title": "Tools" },
    en: { "tools.title": "Tools" },
  },
});
