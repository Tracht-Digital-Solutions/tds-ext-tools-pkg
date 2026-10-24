import { useEffect, useState } from "react";
import { Alert, ConfirmDialog, Spinner, toast } from "@tracht-digital-solutions/tds-shared/components";
import { apiFetch, describeFailure, describeFailureFrom, sendJson } from "@tracht-digital-solutions/tds-shared/api";
import { centsToEuroInput, formatCents, parseEuros } from "@tracht-digital-solutions/tds-shared/format";

export interface Tier {
  id: number;
  slug: string;
  name: string;
  name_en: string | null;
  tagline: string | null;
  tagline_en: string | null;
  features: string[];
  features_en: string[];
  price_cents: number;
  tool_ids: string[];
  active: boolean;
  highlight: boolean;
  sort_order: number;
  subscribers: number;
}

interface ToolRow {
  tool_id: string;
  name: string;
  is_premium: boolean;
}

/** What the form edits: text fields as typed, the price as euro text. */
interface Draft {
  id: number | null;
  slug: string;
  name: string;
  name_en: string;
  tagline: string;
  tagline_en: string;
  features: string;
  features_en: string;
  price: string;
  tool_ids: string[];
  active: boolean;
  highlight: boolean;
  sort_order: number;
}

/** The studio is a free tool whose Premium is a feature unlock; a stage can sell it too. */
const STUDIO = "design-system-studio";

const EMPTY: Draft = {
  id: null,
  slug: "",
  name: "",
  name_en: "",
  tagline: "",
  tagline_en: "",
  features: "",
  features_en: "",
  price: "",
  tool_ids: [],
  active: true,
  highlight: false,
  sort_order: 0,
};

const toDraft = (t: Tier): Draft => ({
  id: t.id,
  slug: t.slug,
  name: t.name,
  name_en: t.name_en ?? "",
  tagline: t.tagline ?? "",
  tagline_en: t.tagline_en ?? "",
  features: t.features.join("\n"),
  features_en: t.features_en.join("\n"),
  price: centsToEuroInput(t.price_cents),
  tool_ids: t.tool_ids,
  active: t.active,
  highlight: t.highlight,
  sort_order: t.sort_order,
});

/**
 * The premium stages (`/tools-verwaltung`): name, FIXED monthly price, the
 * advertising copy in both languages and the tools each stage unlocks. The
 * public site shows exactly these prices; Stripe bills them monthly.
 *
 * - The price is typed in euros and sent in cents (`parseEuros`). A typo is
 *   refused in the form, never rounded into a different price.
 * - Nothing is saved until "Speichern". A price change applies to new
 *   subscriptions; running ones keep the price they started with — said in
 *   the form, because it is the one surprise here.
 * - A stage with subscriptions cannot be deleted (the API answers 409); it is
 *   taken off sale with "Im Verkauf".
 */
export default function ToolsTiers() {
  const [tiers, setTiers] = useState<Tier[] | null>(null);
  const [tools, setTools] = useState<ToolRow[]>([]);
  const [currency, setCurrency] = useState("EUR");
  const [error, setError] = useState<string | null>(null);
  const [draft, setDraft] = useState<Draft | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [pendingDelete, setPendingDelete] = useState<Tier | null>(null);

  const load = async () => {
    const res = await apiFetch("/admin/tools/tiers").catch(() => null);
    if (res === null || !res.ok) {
      setError(describeFailure(res, "Premium-Stufen konnten nicht geladen werden", { adminOnly: true }));
      setTiers([]);
      return;
    }
    const d = await res.json().catch(() => ({}));
    setTiers((d.tiers ?? []) as Tier[]);
    setCurrency(typeof d.currency === "string" ? d.currency : "EUR");
    setError(null);
  };

  const loadTools = async () => {
    const res = await apiFetch("/admin/tools").catch(() => null);
    if (res === null || !res.ok) return;
    const d = await res.json().catch(() => ({}));
    setTools((d.tools ?? []) as ToolRow[]);
  };

  useEffect(() => {
    void load();
    void loadTools();
  }, []);

  const set = (patch: Partial<Draft>) => setDraft((prev) => (prev ? { ...prev, ...patch } : prev));
  const toggleTool = (id: string) =>
    setDraft((prev) =>
      prev ? { ...prev, tool_ids: prev.tool_ids.includes(id) ? prev.tool_ids.filter((t) => t !== id) : [...prev.tool_ids, id] } : prev,
    );

  const save = async () => {
    if (!draft) return;
    setFormError(null);
    const cents = parseEuros(draft.price);
    if (draft.name.trim() === "") return setFormError("Bitte einen Namen angeben.");
    if (cents === null || cents < 50) return setFormError("Bitte einen Monatspreis von mindestens 0,50 € angeben (z. B. 9,90).");
    if (draft.tool_ids.length === 0) return setFormError("Bitte mindestens ein Werkzeug auswählen.");
    setBusy(true);
    const payload = {
      slug: draft.slug.trim(),
      name: draft.name.trim(),
      name_en: draft.name_en.trim(),
      tagline: draft.tagline.trim(),
      tagline_en: draft.tagline_en.trim(),
      features: draft.features.split("\n"),
      features_en: draft.features_en.split("\n"),
      price_cents: cents,
      tool_ids: draft.tool_ids,
      active: draft.active,
      highlight: draft.highlight,
      sort_order: draft.sort_order,
    };
    const res = await (draft.id === null
      ? sendJson("/admin/tools/tiers", "POST", payload)
      : sendJson(`/admin/tools/tiers/${draft.id}`, "PUT", payload)
    ).catch(() => null);
    setBusy(false);
    if (res === null || !res.ok) {
      setFormError(await describeFailureFrom(res, "Speichern fehlgeschlagen"));
      return;
    }
    toast.success(`Stufe „${payload.name}“ gespeichert.`);
    setDraft(null);
    await load();
  };

  const remove = async () => {
    const tier = pendingDelete;
    if (!tier) return;
    setBusy(true);
    const res = await apiFetch(`/admin/tools/tiers/${tier.id}`, { method: "DELETE" }).catch(() => null);
    setBusy(false);
    setPendingDelete(null);
    if (res === null || !res.ok) {
      toast.danger(await describeFailureFrom(res, `„${tier.name}“ konnte nicht gelöscht werden`));
      return;
    }
    toast.success(`Stufe „${tier.name}“ gelöscht.`);
    await load();
  };

  if (tiers === null) return <p><Spinner /></p>;
  if (error) return <Alert>{error}</Alert>;

  // Premium tools first, then the studio unlock, then everything else (a
  // stage may bundle a free tool's future premium too).
  const choices = [...tools].sort((a, b) => Number(b.is_premium) - Number(a.is_premium) || a.name.localeCompare(b.name, "de"));
  const nameOf = (id: string) => tools.find((t) => t.tool_id === id)?.name ?? id;

  return (
    <div className="tds-stack" id="premium-tiers">
      {tiers.length === 0 ? (
        <div className="tds-empty">
          <p className="font-semibold">Noch keine Premium-Stufe.</p>
          <p className="mt-2">
            Ohne Stufe lässt sich kein Premium-Werkzeug freischalten. Eine Stufe hat einen festen Monatspreis und
            schaltet die ausgewählten Werkzeuge frei.
          </p>
        </div>
      ) : (
        <div className="overflow-x-auto">
          <table className="tds-table">
            <thead>
              <tr>
                <th>Stufe</th>
                <th>Monatspreis</th>
                <th>Werkzeuge</th>
                <th>Abos</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {tiers.map((t) => (
                <tr key={t.id}>
                  <td>
                    <div className="font-medium">{t.name}</div>
                    <div className="text-xs opacity-60">{t.slug}{t.tagline ? ` · ${t.tagline}` : ""}</div>
                  </td>
                  <td>{formatCents(t.price_cents, { currency })}</td>
                  <td className="text-sm">{t.tool_ids.map(nameOf).join(", ")}</td>
                  <td className="text-center">{t.subscribers}</td>
                  <td>
                    {t.active ? <span className="chip chip--success">Im Verkauf</span> : <span className="chip chip--neutral">Pausiert</span>}
                    {t.highlight ? <span className="chip chip--info ml-1">Empfohlen</span> : null}
                  </td>
                  <td className="text-right">
                    <div className="tds-toolbar">
                      <button type="button" className="btn btn-ghost" onClick={() => { setFormError(null); setDraft(toDraft(t)); }}>
                        Bearbeiten
                      </button>
                      <button
                        type="button"
                        className="btn btn-danger"
                        onClick={() => setPendingDelete(t)}
                        disabled={t.subscribers > 0}
                        title={t.subscribers > 0 ? "Stufe mit Abos: bitte pausieren statt löschen" : undefined}
                      >
                        Löschen
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {draft === null ? (
        <div className="tds-toolbar">
          <button type="button" className="btn btn-primary" onClick={() => { setFormError(null); setDraft({ ...EMPTY }); }}>
            Neue Stufe
          </button>
        </div>
      ) : (
        <form
          className="tds-card tds-stack"
          aria-label={draft.id === null ? "Neue Premium-Stufe" : `Stufe „${draft.name}“ bearbeiten`}
          onSubmit={(e) => {
            e.preventDefault();
            void save();
          }}
        >
          {formError ? <Alert tone="danger">{formError}</Alert> : null}
          <div className="tds-row">
            <label className="tds-field-row">
              <span>Name</span>
              <input className="field-boxed" value={draft.name} onChange={(e) => set({ name: e.target.value })} maxLength={120} required />
            </label>
            <label className="tds-field-row">
              <span>Name (englisch)</span>
              <input className="field-boxed" value={draft.name_en} onChange={(e) => set({ name_en: e.target.value })} maxLength={120} />
            </label>
            <label className="tds-field-row">
              <span>Kurzname</span>
              <input className="field-boxed" value={draft.slug} onChange={(e) => set({ slug: e.target.value })} placeholder="aus dem Namen" maxLength={60} />
            </label>
          </div>
          <div className="tds-row">
            <label className="tds-field-row">
              <span>Monatspreis (€)</span>
              <input className="field-boxed w-32" inputMode="decimal" value={draft.price} onChange={(e) => set({ price: e.target.value })} placeholder="9,90" required />
            </label>
            <label className="tds-field-row">
              <span>Reihenfolge</span>
              <input className="field-boxed w-24" type="number" min="0" value={draft.sort_order} onChange={(e) => set({ sort_order: Math.max(0, Number(e.target.value) || 0) })} />
            </label>
          </div>
          {draft.id !== null ? (
            <p className="marginalia">
              Eine Preisänderung gilt für neue Abos. Laufende Abos behalten den Preis, mit dem sie abgeschlossen wurden.
            </p>
          ) : null}
          <label className="tds-field-row">
            <span>Werbetext</span>
            <input className="field-boxed" value={draft.tagline} onChange={(e) => set({ tagline: e.target.value })} maxLength={255} placeholder="Ein Satz, der den Nutzen nennt" />
          </label>
          <label className="tds-field-row">
            <span>Werbetext (englisch)</span>
            <input className="field-boxed" value={draft.tagline_en} onChange={(e) => set({ tagline_en: e.target.value })} maxLength={255} />
          </label>
          <div className="tds-row">
            <label className="tds-field-row">
              <span>Leistungen (eine pro Zeile)</span>
              <textarea className="field-boxed" rows={4} value={draft.features} onChange={(e) => set({ features: e.target.value })} />
            </label>
            <label className="tds-field-row">
              <span>Leistungen (englisch)</span>
              <textarea className="field-boxed" rows={4} value={draft.features_en} onChange={(e) => set({ features_en: e.target.value })} />
            </label>
          </div>
          <fieldset className="tds-stack tds-stack--tight">
            <legend className="font-medium">Freigeschaltete Werkzeuge</legend>
            {choices.length === 0 ? <p className="marginalia">Noch keine Werkzeuge synchronisiert.</p> : null}
            {choices.map((t) => (
              <label key={t.tool_id} className="tds-toggle-row">
                <input type="checkbox" checked={draft.tool_ids.includes(t.tool_id)} onChange={() => toggleTool(t.tool_id)} />
                <span>
                  {t.name}
                  {t.is_premium ? <span className="chip chip--warning ml-1">Premium</span> : null}
                  {!t.is_premium && t.tool_id === STUDIO ? <span className="chip chip--info ml-1">Premium-Funktionen</span> : null}
                </span>
              </label>
            ))}
          </fieldset>
          <label className="tds-toggle-row">
            <input type="checkbox" checked={draft.active} onChange={(e) => set({ active: e.target.checked })} />
            <span>Im Verkauf</span>
          </label>
          <label className="tds-toggle-row">
            <input type="checkbox" checked={draft.highlight} onChange={(e) => set({ highlight: e.target.checked })} />
            <span>Als Empfehlung hervorheben</span>
          </label>
          <div className="tds-toolbar">
            <button type="submit" className="btn btn-primary" disabled={busy} aria-busy={busy}>
              {busy ? <Spinner size="sm" /> : "Speichern"}
            </button>
            <button type="button" className="btn btn-ghost" onClick={() => setDraft(null)} disabled={busy}>
              Abbrechen
            </button>
          </div>
        </form>
      )}

      <ConfirmDialog
        open={pendingDelete !== null}
        title={`Stufe „${pendingDelete?.name ?? ""}“ wirklich löschen?`}
        message="Die Stufe verschwindet sofort von der Tools-Site."
        confirmLabel="Löschen"
        destructive
        busy={busy}
        onConfirm={() => void remove()}
        onCancel={() => setPendingDelete(null)}
      />
    </div>
  );
}
