import { useEffect, useState } from "react";
import { Spinner, toast } from "@tracht-digital-solutions/tds-shared/components";
import { apiFetch } from "@tracht-digital-solutions/tds-shared/api";

const api = apiFetch;

type Lang = "de" | "en";

/** A step or an FAQ entry — the two list shapes that carry two fields. */
interface Pair {
  a: string;
  b: string;
}

interface GuideDraft {
  name: string;
  description: string;
  seo_title: string;
  seo_description: string;
  intro: string[];
  use_cases: Pair[];
  steps: Pair[];
  faq: Pair[];
  related: string[];
  privacy: string;
}

interface StoredGuide {
  tool_id: string;
  lang: string;
  name?: string;
  description?: string;
  seo_title?: string;
  seo_description?: string;
  intro?: string[];
  use_cases?: { title: string; text: string }[];
  steps?: { title: string; description: string }[];
  faq?: { q: string; a: string }[];
  related?: string[];
  privacy?: string;
}

interface Tool {
  tool_id: string;
  name: string;
  category: string;
}

const EMPTY: GuideDraft = {
  name: "",
  description: "",
  seo_title: "",
  seo_description: "",
  intro: [],
  use_cases: [],
  steps: [],
  faq: [],
  related: [],
  privacy: "",
};

/**
 * The tool pages' text, edited in the panel.
 *
 * ### Everything here is an OVERRIDE, and the empty state is the normal state
 *
 * The tool list, the German manifest copy and the guides committed in
 * `tds-tools-frontend/src/content/guides` remain the source of truth. A field
 * left blank means "use the text that shipped with the site", which is why
 * this form opens empty even for a tool whose page is full of prose — and why
 * clearing a field is how you take an edit back. Saying so in the interface
 * matters: an editor who reads an empty *Einleitung* as "there is no intro"
 * will paste one in and quietly detach the page from the repository copy.
 *
 * ### Why the SEO fields carry their budgets in the label
 *
 * A meta description has no visible failure mode: nothing renders wrong,
 * nothing errors, and an over-long tail is simply absent from a search result
 * nobody is looking at. The site's own tests fail outside 80–160 characters, so
 * the counter here is the earliest place the number can be seen.
 */
export default function ToolGuides() {
  const [tools, setTools] = useState<Tool[] | null>(null);
  const [stored, setStored] = useState<StoredGuide[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [toolId, setToolId] = useState("");
  const [lang, setLang] = useState<Lang>("de");
  const [draft, setDraft] = useState<GuideDraft>(EMPTY);
  const [busy, setBusy] = useState(false);

  const load = async () => {
    const [toolsRes, guidesRes] = await Promise.all([
      api("/admin/tools"),
      api("/admin/tools/guides"),
    ]);
    if (!toolsRes.ok) {
      setError(
        toolsRes.status === 401 || toolsRes.status === 403
          ? "Nur für Administratoren."
          : `Tools konnten nicht geladen werden (HTTP ${toolsRes.status}).`,
      );
      setTools([]);
      return;
    }
    const toolsData = await toolsRes.json().catch(() => ({ tools: [] }));
    const guidesData = guidesRes.ok
      ? await guidesRes.json().catch(() => ({ guides: [] }))
      : { guides: [] };
    setTools((toolsData.tools ?? []) as Tool[]);
    setStored((guidesData.guides ?? []) as StoredGuide[]);
    setError(null);
  };

  useEffect(() => {
    void load();
  }, []);

  // Load whatever is stored for the selected tool + language into the form.
  useEffect(() => {
    if (!toolId) {
      setDraft(EMPTY);
      return;
    }
    const row = stored.find((g) => g.tool_id === toolId && g.lang === lang);
    setDraft(
      row
        ? {
            name: row.name ?? "",
            description: row.description ?? "",
            seo_title: row.seo_title ?? "",
            seo_description: row.seo_description ?? "",
            intro: row.intro ?? [],
            use_cases: (row.use_cases ?? []).map((u) => ({ a: u.title, b: u.text })),
            steps: (row.steps ?? []).map((s) => ({ a: s.title, b: s.description })),
            faq: (row.faq ?? []).map((f) => ({ a: f.q, b: f.a })),
            related: row.related ?? [],
            privacy: row.privacy ?? "",
          }
        : EMPTY,
    );
  }, [toolId, lang, stored]);

  const save = async () => {
    if (!toolId) return;
    setBusy(true);
    const res = await api(`/admin/tools/guides/${encodeURIComponent(toolId)}/${lang}`, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        name: draft.name,
        description: draft.description,
        seo_title: draft.seo_title,
        seo_description: draft.seo_description,
        intro: draft.intro.filter((p) => p.trim() !== ""),
        // Back to the API's shape. The form keeps two neutral fields per row so
        // one set of list controls serves all three, and the naming is restored
        // here rather than leaking `a`/`b` into the payload.
        use_cases: draft.use_cases.filter((u) => u.a.trim() !== "").map((u) => ({ title: u.a, text: u.b })),
        steps: draft.steps.filter((s) => s.a.trim() !== "").map((s) => ({ title: s.a, description: s.b })),
        faq: draft.faq.filter((f) => f.a.trim() !== "").map((f) => ({ q: f.a, a: f.b })),
        related: draft.related.filter((r) => r.trim() !== ""),
        privacy: draft.privacy,
      }),
    });
    setBusy(false);
    if (res.ok) {
      const body = await res.json().catch(() => ({}));
      if (body.cache_status === "refreshed" && body.cached === true) {
        toast.success("Gespeichert — Seiten-Cache aktualisiert.");
      } else if (body.cache_status === "not_configured") {
        toast.warning("Gespeichert — Tools-Site noch nicht verbunden.");
      } else {
        toast.warning("Gespeichert — Cache-Aktualisierung fehlgeschlagen.");
      }
      void load();
    } else {
      // The status code is what tells "session expired" from "service down".
      toast.danger(`Speichern fehlgeschlagen (HTTP ${res.status}).`);
    }
  };

  const reset = async () => {
    if (!toolId) return;
    setBusy(true);
    const res = await api(`/admin/tools/guides/${encodeURIComponent(toolId)}/${lang}`, {
      method: "DELETE",
    });
    setBusy(false);
    if (res.ok) {
      const body = await res.json().catch(() => ({}));
      if (body.cache_status === "refreshed" && body.cached === true) {
        toast.success("Übersteuerung entfernt — Seiten-Cache aktualisiert.");
      } else {
        toast.warning("Übersteuerung entfernt — Cache-Aktualisierung steht noch aus.");
      }
      setDraft(EMPTY);
      void load();
    } else {
      toast.danger(`Zurücksetzen fehlgeschlagen (HTTP ${res.status}).`);
    }
  };

  if (tools === null) return <p><Spinner /></p>;
  if (error) return <p className="tds-alert tds-alert--danger" role="alert">{error}</p>;

  const hasOverride = stored.some((g) => g.tool_id === toolId && g.lang === lang);

  return (
    <div className="tool-guides tds-stack">
      <p className="marginalia">
        Hier steht nur, was den mitgelieferten Text <strong>ersetzen</strong> soll. Ein
        leeres Feld heißt „den Text aus dem Repository verwenden" — deshalb ist dieses
        Formular auch bei einer Tool-Seite leer, die voller Text ist. Ein Feld zu leeren
        nimmt die Übersteuerung wieder zurück.
      </p>

      <div className="tds-row">
        <label className="field">
          <span>Tool</span>
          <select className="field-boxed" value={toolId} onChange={(e) => setToolId(e.target.value)}>
            <option value="">— auswählen —</option>
            {tools.map((t) => (
              <option key={t.tool_id} value={t.tool_id}>{t.name}</option>
            ))}
          </select>
        </label>
        <label className="field">
          <span>Sprache</span>
          <select className="field-boxed" value={lang} onChange={(e) => setLang(e.target.value as Lang)}>
            <option value="de">Deutsch</option>
            <option value="en">English</option>
          </select>
        </label>
      </div>

      {toolId === "" ? (
        <div className="tds-empty">
          <p>Wählen Sie ein Tool, um seinen Text zu bearbeiten.</p>
        </div>
      ) : (
        <>
          {hasOverride ? (
            <p className="tds-alert" role="status">
              Für dieses Tool ist in dieser Sprache ein eigener Text hinterlegt.
            </p>
          ) : null}

          <label className="field">
            <span>Name</span>
            <input className="field-boxed" value={draft.name}
              onChange={(e) => setDraft({ ...draft, name: e.target.value })}
              placeholder="leer = Name aus dem Paket" />
          </label>

          <label className="field">
            <span>Kurzbeschreibung</span>
            <textarea className="field-boxed" rows={2} value={draft.description}
              onChange={(e) => setDraft({ ...draft, description: e.target.value })} />
          </label>

          <label className="field">
            <span>SEO-Titel ({draft.seo_title.length}/60)</span>
            <input className="field-boxed" value={draft.seo_title}
              onChange={(e) => setDraft({ ...draft, seo_title: e.target.value })} />
          </label>

          <label className="field">
            <span>SEO-Beschreibung ({draft.seo_description.length}, Ziel 80–160)</span>
            <textarea className="field-boxed" rows={2} value={draft.seo_description}
              onChange={(e) => setDraft({ ...draft, seo_description: e.target.value })} />
          </label>

          <StringList label="Einleitung (Absätze)" itemLabel="Absatz" multiline
            values={draft.intro} onChange={(intro) => setDraft({ ...draft, intro })} />

          <PairList label="Anwendungsfälle" itemLabel="Anwendungsfall"
            aLabel="Situation" bLabel="Text"
            values={draft.use_cases} onChange={(use_cases) => setDraft({ ...draft, use_cases })} />

          <PairList label="Schritte" itemLabel="Schritt"
            aLabel="Titel" bLabel="Beschreibung"
            hint="Diese Schritte erscheinen auch als HowTo-Auszeichnung für Suchmaschinen. Sie müssen zu dem passen, was auf der Seite steht."
            values={draft.steps} onChange={(steps) => setDraft({ ...draft, steps })} />

          <PairList label="Häufige Fragen" itemLabel="Frage"
            aLabel="Frage" bLabel="Antwort"
            hint="Auch diese werden als FAQ-Auszeichnung ausgeliefert. Google verwirft das Ergebnis, wenn die Antwort dort von der sichtbaren abweicht."
            values={draft.faq} onChange={(faq) => setDraft({ ...draft, faq })} />

          <label className="field">
            <span>Datenschutzhinweis</span>
            <textarea className="field-boxed" rows={3} value={draft.privacy}
              onChange={(e) => setDraft({ ...draft, privacy: e.target.value })} />
          </label>

          <StringList label="Verwandte Tools (Slugs)" itemLabel="Slug"
            values={draft.related} onChange={(related) => setDraft({ ...draft, related })} />

          <div className="tds-row">
            <button type="button" className="btn btn-primary" onClick={save} disabled={busy} aria-busy={busy}>
              Speichern
            </button>
            {hasOverride ? (
              <button type="button" className="btn btn-ghost" onClick={reset} disabled={busy}>
                Auf mitgelieferten Text zurücksetzen
              </button>
            ) : null}
          </div>
        </>
      )}
    </div>
  );
}

/** A repeatable list of single strings. */
function StringList({
  label,
  itemLabel,
  values,
  onChange,
  multiline = false,
}: {
  label: string;
  itemLabel: string;
  values: string[];
  onChange: (next: string[]) => void;
  multiline?: boolean;
}) {
  const set = (i: number, v: string) => onChange(values.map((x, n) => (n === i ? v : x)));
  return (
    <fieldset className="tds-stack">
      <legend>{label}</legend>
      {values.map((v, i) => (
        <div className="tds-row" key={i}>
          {multiline ? (
            <textarea className="field-boxed" rows={2} value={v} onChange={(e) => set(i, e.target.value)} aria-label={`${itemLabel} ${i + 1}`} />
          ) : (
            <input className="field-boxed" value={v} onChange={(e) => set(i, e.target.value)} aria-label={`${itemLabel} ${i + 1}`} />
          )}
          <button type="button" className="btn btn-ghost" onClick={() => onChange(values.filter((_, n) => n !== i))}>
            Entfernen
          </button>
        </div>
      ))}
      <button type="button" className="btn btn-ghost" onClick={() => onChange([...values, ""])}>
        {itemLabel} hinzufügen
      </button>
    </fieldset>
  );
}

/** A repeatable list of two-field rows — steps, use cases and FAQ share it. */
function PairList({
  label,
  itemLabel,
  aLabel,
  bLabel,
  hint,
  values,
  onChange,
}: {
  label: string;
  itemLabel: string;
  aLabel: string;
  bLabel: string;
  hint?: string;
  values: Pair[];
  onChange: (next: Pair[]) => void;
}) {
  const set = (i: number, patch: Partial<Pair>) =>
    onChange(values.map((x, n) => (n === i ? { ...x, ...patch } : x)));

  return (
    <fieldset className="tds-stack">
      <legend>{label}</legend>
      {hint ? <p className="marginalia">{hint}</p> : null}
      {values.map((v, i) => (
        <div className="tds-stack" key={i}>
          <div className="tds-row">
            <input className="field-boxed" value={v.a} onChange={(e) => set(i, { a: e.target.value })}
              aria-label={`${aLabel} ${i + 1}`} placeholder={aLabel} />
            <button type="button" className="btn btn-ghost" onClick={() => onChange(values.filter((_, n) => n !== i))}>
              Entfernen
            </button>
          </div>
          <textarea className="field-boxed" rows={2} value={v.b} onChange={(e) => set(i, { b: e.target.value })}
            aria-label={`${bLabel} ${i + 1}`} placeholder={bLabel} />
        </div>
      ))}
      <button type="button" className="btn btn-ghost" onClick={() => onChange([...values, { a: "", b: "" }])}>
        {itemLabel} hinzufügen
      </button>
    </fieldset>
  );
}
