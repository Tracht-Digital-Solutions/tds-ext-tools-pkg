import { useEffect, useState } from "react";
import { Alert, Spinner, toast } from "@tracht-digital-solutions/tds-shared/components";
import { apiFetch, sendJson } from "@tracht-digital-solutions/tds-shared/api";

interface Tool {
  tool_id: string;
  name: string;
  category: string;
  enabled: boolean;
  requires_login: boolean;
  is_premium: boolean;
  price_cents: number;
  blog_slug: string | null;
  sort_order: number;
}

interface Article {
  slug: string;
  title: string;
  /** Published in German / in English (same slug in both languages). */
  de: boolean;
  en: boolean;
}

/** "DE + EN", "nur DE" or "nur EN": which tool pages will show the article. */
function articleLangs(a: Article): string {
  return a.de && a.en ? "DE + EN" : a.de ? "nur DE" : "nur EN";
}

/**
 * Tool-catalog management: one row per tool (enabled / login / premium /
 * Ratgeber article),
 * saved to the backend which refreshes the affected public pages. The tool list
 * is owned by the public site's composed packs. Pairing gives that site a
 * resource-bound key; its server then synchronises the built catalog on first
 * start and whenever the catalog hash changes.
 *
 * Prices are no longer set per tool: premium tools are sold as monthly
 * stages (ToolsTiers). `price_cents` is still sent back unchanged so an old
 * value is never zeroed by a save.
 *
 * The Ratgeber article comes from the blog CMS's public lists (German and
 * English, one slug for both) when that extension is composed; each option
 * says in which languages it exists, because the English tool page shows the
 * English version and links to the Ratgeber when there is none; without it (404, network) the field is a plain
 * slug input, and the tool page simply links to the blog.
 */
export default function ToolsManage() {
  const [tools, setTools] = useState<Tool[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [articles, setArticles] = useState<Article[] | null>(null);

  // After the catalog, so the table never waits for the blog. Fails soft to
  // the free-text field.
  const loadArticles = async () => {
    const list = async (lang: "de" | "en"): Promise<{ slug: string; title: string }[] | null> => {
      const res = await apiFetch(`/content/blog?lang=${lang}&limit=100`).catch(() => null);
      if (res === null || !res.ok) return null;
      const d = await res.json().catch(() => ({}));
      return Array.isArray(d.posts) ? (d.posts as { slug: unknown; title: unknown }[]).map((p) => ({ slug: String(p.slug), title: String(p.title) })) : [];
    };
    const [de, en] = await Promise.all([list("de"), list("en")]);
    if (de === null && en === null) return;
    // German title first; an English-only article keeps its English title.
    const merged = new Map<string, Article>();
    for (const p of de ?? []) merged.set(p.slug, { slug: p.slug, title: p.title, de: true, en: false });
    for (const p of en ?? []) {
      const known = merged.get(p.slug);
      if (known) known.en = true;
      else merged.set(p.slug, { slug: p.slug, title: p.title, de: false, en: true });
    }
    if (merged.size > 0) setArticles([...merged.values()]);
  };

  const load = async () => {
    // apiFetch rejects when the request never reaches the API; uncaught, the
    // table stayed on its spinner for good.
    const res = await apiFetch("/admin/tools").catch(() => null);
    if (res === null) {
      setError("Tools konnten nicht geladen werden — die API ist nicht erreichbar.");
      setTools([]);
      return;
    }
    if (!res.ok) {
      setError(res.status === 401 || res.status === 403 ? "Nur für Administratoren." : `Fehler (HTTP ${res.status}).`);
      setTools([]);
      return;
    }
    const d = await res.json();
    setTools(((d.tools ?? []) as Tool[]).map((t) => ({ ...t, blog_slug: t.blog_slug ?? null })));
    setError(null);
    void loadArticles();
  };

  useEffect(() => {
    void load();
  }, []);

  const patch = (id: string, patch: Partial<Tool>) =>
    setTools((prev) => prev?.map((t) => (t.tool_id === id ? { ...t, ...patch } : t)) ?? prev);

  const save = async (tool: Tool) => {
    setBusy(tool.tool_id);
    const res = await sendJson(`/admin/tools/${encodeURIComponent(tool.tool_id)}`, "PUT", {
        enabled: tool.enabled,
        requires_login: tool.requires_login,
        is_premium: tool.is_premium,
        price_cents: tool.price_cents,
        blog_slug: tool.blog_slug ?? "",
        sort_order: tool.sort_order,
      }).catch(() => null);
    setBusy(null);
    if (res === null) {
      toast.danger(`„${tool.name}“ konnte nicht gespeichert werden — die API ist nicht erreichbar.`);
      return;
    }
    // One `status` string for a whole TABLE of rows meant saving row 3 wiped
    // row 1's confirmation, and the banner sat at the top of the table while
    // the button that produced it was somewhere down the list. Per-row
    // outcomes belong in a toast, which also names the tool it is about.
    if (res.ok) {
      const body = await res.json().catch(() => ({}));
      if (body.cache_status === "refreshed" && body.cached === true) {
        toast.success(`„${tool.name}“ gespeichert — Seiten-Cache aktualisiert.`);
      } else if (body.cache_status === "not_configured") {
        toast.warning(`„${tool.name}“ gespeichert — Tools-Site noch nicht verbunden.`);
      } else {
        toast.warning(`„${tool.name}“ gespeichert — Cache-Aktualisierung fehlgeschlagen.`);
      }
    } else toast.danger(`„${tool.name}“ konnte nicht gespeichert werden (HTTP ${res.status}).`);
  };

  if (tools === null) return <p><Spinner /></p>;
  if (error) return <Alert>{error}</Alert>;

  // The catalog table uses `.tds-table` for the header treatment, cell padding,
  // row rules and hover. The hand-rolled utility strings it replaces also
  // referenced the non-existent `--color-border` token, so those row rules were
  // falling back to currentColor. Only genuine alignment intent
  // (text-center / text-right) stays as a utility.

  return (
    <div className="tools-manage space-y-4">
      <p className="text-sm opacity-70">{tools.length} Tool(s)</p>

      {tools.length === 0 ? (
        <div className="tds-empty">
          <p className="font-semibold">Noch keine Tools synchronisiert.</p>
          <p className="mt-2">
            Verbinden Sie die Tools-Site unter Einstellungen → Tools mit der API. Der
            veröffentlichte Katalog wird danach beim Serverstart automatisch über den
            Site-Key synchronisiert.
          </p>
          <p className="mt-2"><a href="/einstellungen">Verbindung einrichten</a></p>
        </div>
      ) : (
        <div className="overflow-x-auto">
          <table className="tds-table">
            <thead>
              <tr>
                <th>Tool</th>
                <th>Sichtbar</th>
                <th>Login</th>
                <th>Premium</th>
                <th>Ratgeber-Artikel</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {tools.map((t) => (
                <tr key={t.tool_id}>
                  <td>
                    <div className="font-medium">{t.name}</div>
                    <div className="text-xs opacity-60">{t.tool_id} · {t.category}</div>
                  </td>
                  <td className="text-center">
                    {/* Every control names its tool: a table of eighteen
                        identical "Sichtbar" checkboxes told a screen reader
                        nothing about which row it was in, and the price
                        field had no name at all. */}
                    <input type="checkbox" checked={t.enabled} onChange={(e) => patch(t.tool_id, { enabled: e.target.checked })} aria-label={`${t.name}: sichtbar`} />
                  </td>
                  <td className="text-center">
                    <input type="checkbox" checked={t.requires_login} onChange={(e) => patch(t.tool_id, { requires_login: e.target.checked })} aria-label={`${t.name}: Login erforderlich`} />
                  </td>
                  <td className="text-center">
                    <input type="checkbox" checked={t.is_premium} onChange={(e) => patch(t.tool_id, { is_premium: e.target.checked })} aria-label={`${t.name}: Premium`} />
                  </td>
                  <td>
                    {articles !== null ? (
                      <select
                        aria-label={`${t.name}: Ratgeber-Artikel`}
                        className="field-boxed"
                        value={t.blog_slug ?? ""}
                        onChange={(e) => patch(t.tool_id, { blog_slug: e.target.value || null })}
                      >
                        <option value="">— kein Artikel —</option>
                        {/* A stored slug the list does not hold (unpublished,
                            renamed) stays visible instead of silently vanishing. */}
                        {t.blog_slug && !articles.some((a) => a.slug === t.blog_slug) ? (
                          <option value={t.blog_slug}>{t.blog_slug} (nicht veröffentlicht)</option>
                        ) : null}
                        {articles.map((a) => (
                          <option key={a.slug} value={a.slug}>{a.title} · {articleLangs(a)}</option>
                        ))}
                      </select>
                    ) : (
                      <input
                        aria-label={`${t.name}: Ratgeber-Artikel`}
                        type="text"
                        className="field-boxed w-48"
                        placeholder="artikel-slug"
                        value={t.blog_slug ?? ""}
                        onChange={(e) => patch(t.tool_id, { blog_slug: e.target.value.trim().toLowerCase() || null })}
                      />
                    )}
                  </td>
                  <td className="text-right">
                    <button type="button" className="btn btn-primary" onClick={() => save(t)} disabled={busy === t.tool_id} aria-busy={busy === t.tool_id}>Speichern</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
