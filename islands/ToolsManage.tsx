import { useEffect, useState } from "react";
import { Spinner, toast } from "@tracht-digital-solutions/tds-shared/components";
import { apiFetch } from "@tracht-digital-solutions/tds-shared/api";

interface Tool {
  tool_id: string;
  name: string;
  category: string;
  enabled: boolean;
  requires_login: boolean;
  is_premium: boolean;
  price_cents: number;
  sort_order: number;
}

const api = apiFetch;

/**
 * Tool-catalog management: one row per tool (enabled / login / premium / price),
 * saved to the backend which fires a rebuild of the public site. The tool list
 * is owned by the public site's composed packs, so the empty state points at the
 * transfer rather than offering a "create tool" action.
 *
 * ### The empty state is the whole feature when the table is empty
 *
 * It used to read "Sie erscheinen automatisch, sobald die Website gebaut wurde
 * und ihren Katalog synchronisiert hat." That was false, and it is why this
 * page sat empty for the platform's entire life: the site's build-time sync was
 * gated on `TOOLS_REGISTRY_TOKEN`, which no workflow exported and which Vite
 * would never have injected anyway (no `PUBLIC_` prefix, no `envField` schema).
 * So the operator was told to wait for something that could not happen, and the
 * sync fails soft by design, so nothing anywhere went red.
 *
 * The transfer is host-side now (`/_setup/install.php` posts
 * `dist/tools-catalog.json`), and it needs two steps IN ORDER — the registry
 * answers 503 until the token exists in the panel. Naming both, in order, is
 * the difference between a dead page and a five-minute task.
 */
export default function ToolsManage() {
  const [tools, setTools] = useState<Tool[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [status, setStatus] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const load = async () => {
    const res = await api("/admin/tools");
    if (!res.ok) {
      setError(res.status === 401 || res.status === 403 ? "Nur für Administratoren." : `Fehler (HTTP ${res.status}).`);
      setTools([]);
      return;
    }
    const d = await res.json();
    setTools((d.tools ?? []) as Tool[]);
    setError(null);
  };

  useEffect(() => {
    void load();
  }, []);

  const patch = (id: string, patch: Partial<Tool>) =>
    setTools((prev) => prev?.map((t) => (t.tool_id === id ? { ...t, ...patch } : t)) ?? prev);

  const save = async (tool: Tool) => {
    setBusy(tool.tool_id);
    setStatus(null);
    const res = await api(`/admin/tools/${encodeURIComponent(tool.tool_id)}`, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        enabled: tool.enabled,
        requires_login: tool.requires_login,
        is_premium: tool.is_premium,
        price_cents: tool.price_cents,
        sort_order: tool.sort_order,
      }),
    });
    setBusy(null);
    // One `status` string for a whole TABLE of rows meant saving row 3 wiped
    // row 1's confirmation, and the banner sat at the top of the table while
    // the button that produced it was somewhere down the list. Per-row
    // outcomes belong in a toast, which also names the tool it is about.
    if (res.ok) toast.success(`„${tool.name}“ gespeichert — Rebuild ausgelöst.`);
    else toast.danger(`„${tool.name}“ konnte nicht gespeichert werden (HTTP ${res.status}).`);
  };

  const rebuild = async () => {
    setBusy("__rebuild__");
    setStatus(null);
    const res = await api("/admin/tools/rebuild", { method: "POST" });
    setBusy(null);
    if (res.ok) toast.success("Rebuild der Website ausgelöst.");
    else toast.danger(`Rebuild fehlgeschlagen (HTTP ${res.status}).`);
  };

  if (tools === null) return <p><Spinner /></p>;
  if (error) return <p className="tds-alert tds-alert--danger" role="alert">{error}</p>;

  // The catalog table uses `.tds-table` for the header treatment, cell padding,
  // row rules and hover. The hand-rolled utility strings it replaces also
  // referenced the non-existent `--color-border` token, so those row rules were
  // falling back to currentColor. Only genuine alignment intent
  // (text-center / text-right) stays as a utility.

  return (
    <div className="tools-manage space-y-4">
      <div className="flex items-center justify-between">
        <p className="text-sm opacity-70">{tools.length} Tool(s)</p>
        <button type="button" className="btn btn-ghost" onClick={rebuild} disabled={busy === "__rebuild__"} aria-busy={busy === "__rebuild__"}>Website neu bauen</button>
      </div>

      {/* Outcomes are toasts now; nothing else writes `status`, so this is
          the empty-state hint only. */}
      {status ? <p className="tds-alert" role="status">{status}</p> : null}

      {tools.length === 0 ? (
        <div className="tds-empty">
          <p className="font-semibold">Noch keine Tools übertragen.</p>
          <p className="mt-2">
            Der Tool-Katalog wird nicht automatisch übertragen — er muss einmal vom Host
            der Tools-Website an diese API geschickt werden. Zwei Schritte, in dieser
            Reihenfolge:
          </p>
          <ol className="mt-2 ml-5 list-decimal space-y-1 text-left">
            <li>
              Unter <a href="/einstellungen">Einstellungen → Tools / AdSense</a> einen
              <strong> Registry-Sync-Token</strong> setzen und speichern.
            </li>
            <li>
              <code>https://tools.tracht-digital.de/_setup</code> aufrufen, denselben Token
              eintragen und den Schritt <strong>„Tool-Katalog übertragen"</strong> ausführen.
            </li>
          </ol>
          <p className="mt-2">
            Ohne Schritt 1 lehnt die Registry die Übertragung ab (HTTP 503). Danach
            erscheinen die Tools hier und lassen sich einzeln steuern.
          </p>
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
                <th>Preis (€)</th>
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
                    <input type="checkbox" checked={t.enabled} onChange={(e) => patch(t.tool_id, { enabled: e.target.checked })} aria-label="Sichtbar" />
                  </td>
                  <td className="text-center">
                    <input type="checkbox" checked={t.requires_login} onChange={(e) => patch(t.tool_id, { requires_login: e.target.checked })} aria-label="Login erforderlich" />
                  </td>
                  <td className="text-center">
                    <input type="checkbox" checked={t.is_premium} onChange={(e) => patch(t.tool_id, { is_premium: e.target.checked })} aria-label="Premium" />
                  </td>
                  <td>
                    <input
                      type="number"
                      min="0"
                      step="0.01"
                      className="field-boxed w-24"
                      value={(t.price_cents / 100).toFixed(2)}
                      onChange={(e) => patch(t.tool_id, { price_cents: Math.max(0, Math.round(Number(e.target.value) * 100)) })}
                      disabled={!t.is_premium}
                    />
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
