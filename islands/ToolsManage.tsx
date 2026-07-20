import { useEffect, useState } from "react";

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

const api = (path: string, init?: RequestInit) => fetch(path, { credentials: "include", ...init });

/**
 * Tool-catalog management: one row per tool (enabled / login / premium / price),
 * saved to the backend which fires a rebuild of the public site. The tool list
 * is populated by the site build's registry sync, so the empty state points
 * there rather than offering a "create tool" action.
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
    setStatus(res.ok ? `„${tool.name}“ gespeichert — Rebuild ausgelöst.` : `Fehler (HTTP ${res.status}).`);
  };

  const rebuild = async () => {
    setBusy("__rebuild__");
    setStatus(null);
    const res = await api("/admin/tools/rebuild", { method: "POST" });
    setBusy(null);
    setStatus(res.ok ? "Rebuild der Website ausgelöst." : `Fehler (HTTP ${res.status}).`);
  };

  if (tools === null) return <p>Wird geladen …</p>;
  if (error) return <p className="status-pill status-pill--danger">{error}</p>;

  return (
    <div className="tools-manage space-y-4">
      <div className="flex items-center justify-between">
        <p className="text-sm opacity-70">{tools.length} Tool(s)</p>
        <button type="button" onClick={rebuild} disabled={busy === "__rebuild__"}>Website neu bauen</button>
      </div>

      {status ? <p className="status-pill status-pill--info">{status}</p> : null}

      {tools.length === 0 ? (
        <p className="text-sm opacity-70">
          Noch keine Tools. Sie erscheinen automatisch, sobald die Website (tds-tools) gebaut
          wurde und ihren Katalog synchronisiert hat.
        </p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left opacity-70">
                <th className="py-2 pr-3">Tool</th>
                <th className="px-2">Sichtbar</th>
                <th className="px-2">Login</th>
                <th className="px-2">Premium</th>
                <th className="px-2">Preis (€)</th>
                <th className="px-2"></th>
              </tr>
            </thead>
            <tbody>
              {tools.map((t) => (
                <tr key={t.tool_id} className="border-t border-[color:var(--color-border)]">
                  <td className="py-2 pr-3">
                    <div className="font-medium">{t.name}</div>
                    <div className="text-xs opacity-60">{t.tool_id} · {t.category}</div>
                  </td>
                  <td className="px-2 text-center">
                    <input type="checkbox" checked={t.enabled} onChange={(e) => patch(t.tool_id, { enabled: e.target.checked })} aria-label="Sichtbar" />
                  </td>
                  <td className="px-2 text-center">
                    <input type="checkbox" checked={t.requires_login} onChange={(e) => patch(t.tool_id, { requires_login: e.target.checked })} aria-label="Login erforderlich" />
                  </td>
                  <td className="px-2 text-center">
                    <input type="checkbox" checked={t.is_premium} onChange={(e) => patch(t.tool_id, { is_premium: e.target.checked })} aria-label="Premium" />
                  </td>
                  <td className="px-2">
                    <input
                      type="number"
                      min="0"
                      step="0.01"
                      className="w-24"
                      value={(t.price_cents / 100).toFixed(2)}
                      onChange={(e) => patch(t.tool_id, { price_cents: Math.max(0, Math.round(Number(e.target.value) * 100)) })}
                      disabled={!t.is_premium}
                    />
                  </td>
                  <td className="px-2 text-right">
                    <button type="button" onClick={() => save(t)} disabled={busy === t.tool_id}>Speichern</button>
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
