import { useEffect, useState } from "react";
import { Spinner, toast } from "@tracht-digital-solutions/tds-shared/components";
import { apiFetch } from "@tracht-digital-solutions/tds-shared/api";

interface Masked {
  key: string;
  secret: boolean;
  configured?: boolean;
  last4?: string | null;
  value?: string;
}

const api = apiFetch;
const NS = "/admin/settings/tools";

/**
 * Tools settings — AdSense (publisher id + slots + master switch), the public
 * site rebuild target (repo/workflow/token), and the registry-sync token. Stored
 * in the core runtime settings store (admin-only). Secrets come back masked; a
 * blank secret on save keeps the existing value.
 */
export default function ToolsSettings() {
  const [loaded, setLoaded] = useState(false);
  const [status, setStatus] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const [adsEnabled, setAdsEnabled] = useState(false);
  const [publisherId, setPublisherId] = useState("");
  const [slotCatalog, setSlotCatalog] = useState("");
  const [slotTool, setSlotTool] = useState("");
  const [rebuildRepo, setRebuildRepo] = useState("");
  const [rebuildWorkflow, setRebuildWorkflow] = useState("dev.yml");
  const [rebuildToken, setRebuildToken] = useState("");
  const [registryToken, setRegistryToken] = useState("");
  const [rebuildState, setRebuildState] = useState<Masked | null>(null);
  const [registryState, setRegistryState] = useState<Masked | null>(null);

  const load = async () => {
    const res = await api(NS);
    if (!res.ok) {
      setStatus(res.status === 401 || res.status === 403 ? "Nur für Administratoren." : `Fehler (HTTP ${res.status}).`);
      setLoaded(true);
      return;
    }
    const d = await res.json();
    const map = new Map<string, Masked>((d.settings ?? []).map((s: Masked) => [s.key, s]));
    setAdsEnabled((map.get("ads_enabled")?.value || "0") === "1");
    setPublisherId(map.get("adsense_publisher_id")?.value || "");
    setSlotCatalog(map.get("adsense_slot_catalog")?.value || "");
    setSlotTool(map.get("adsense_slot_tool")?.value || "");
    setRebuildRepo(map.get("rebuild_repo")?.value || "");
    setRebuildWorkflow(map.get("rebuild_workflow")?.value || "dev.yml");
    setRebuildState(map.get("rebuild_token") ?? null);
    setRegistryState(map.get("registry_token") ?? null);
    setLoaded(true);
  };

  useEffect(() => {
    void load();
  }, []);

  const save = async () => {
    setBusy(true);
    setStatus(null);
    const settings: Masked[] = [
      { key: "ads_enabled", secret: false, value: adsEnabled ? "1" : "0" },
      { key: "adsense_publisher_id", secret: false, value: publisherId.trim() },
      { key: "adsense_slot_catalog", secret: false, value: slotCatalog.trim() },
      { key: "adsense_slot_tool", secret: false, value: slotTool.trim() },
      { key: "rebuild_repo", secret: false, value: rebuildRepo.trim() },
      { key: "rebuild_workflow", secret: false, value: rebuildWorkflow.trim() || "dev.yml" },
      { key: "rebuild_token", secret: true, value: rebuildToken.trim() },
      { key: "registry_token", secret: true, value: registryToken.trim() },
    ];
    const res = await api(NS, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ settings }),
    });
    setBusy(false);
    if (res.ok) {
      setRebuildToken("");
      setRegistryToken("");
      toast.success("Gespeichert.");
      void load();
    } else {
      toast.danger(`Speichern fehlgeschlagen (HTTP ${res.status}).`);
    }
  };

  const hint = (s: Masked | null) => (s?.configured ? `konfiguriert (…${s.last4 ?? "????"})` : "nicht konfiguriert");

  if (!loaded) return <p><Spinner /></p>;

  return (
    <div className="tools-settings space-y-5">
      <fieldset className="space-y-3">
        <legend className="text-sm font-semibold">Google AdSense</legend>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={adsEnabled} onChange={(e) => setAdsEnabled(e.target.checked)} />
          AdSense aktivieren
        </label>
        <label className="block">
          <span className="text-sm">Publisher-ID</span>
          <input className="field-boxed" type="text" value={publisherId} onChange={(e) => setPublisherId(e.target.value)} placeholder="ca-pub-…" />
        </label>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <label className="block">
            <span className="text-sm">Slot (Übersicht)</span>
            <input className="field-boxed" type="text" value={slotCatalog} onChange={(e) => setSlotCatalog(e.target.value)} placeholder="123…" />
          </label>
          <label className="block">
            <span className="text-sm">Slot (Tool-Seite)</span>
            <input className="field-boxed" type="text" value={slotTool} onChange={(e) => setSlotTool(e.target.value)} placeholder="123…" />
          </label>
        </div>
      </fieldset>

      <fieldset className="space-y-3">
        <legend className="text-sm font-semibold">Website-Rebuild</legend>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <label className="block">
            <span className="text-sm">Repo (owner/name)</span>
            <input className="field-boxed" type="text" value={rebuildRepo} onChange={(e) => setRebuildRepo(e.target.value)} placeholder="Tracht-Digital-Solutions/tds-tools-frontend" />
          </label>
          <label className="block">
            <span className="text-sm">Workflow</span>
            <input className="field-boxed" type="text" value={rebuildWorkflow} onChange={(e) => setRebuildWorkflow(e.target.value)} placeholder="dev.yml" />
          </label>
        </div>
        <label className="block">
          <span className="text-sm">Rebuild-Token (GitHub PAT) <em className="opacity-60">({hint(rebuildState)})</em></span>
          <input className="field-boxed" type="password" value={rebuildToken} onChange={(e) => setRebuildToken(e.target.value)} placeholder="ghp_… (leer = behalten)" autoComplete="off" />
        </label>
        <label className="block">
          <span className="text-sm">Registry-Sync-Token <em className="opacity-60">({hint(registryState)})</em></span>
          <input className="field-boxed" type="password" value={registryToken} onChange={(e) => setRegistryToken(e.target.value)} placeholder="(leer = behalten)" autoComplete="off" />
        </label>
      </fieldset>

      {/* The load failure is persistent state and stays in-flow; the save
          outcome is a toast. Failures only, hence the danger hue. */}
      {status ? <p className="tds-alert tds-alert--danger" role="alert">{status}</p> : null}
      <button type="button" className="btn btn-primary" onClick={save} disabled={busy} aria-busy={busy}>Speichern</button>
    </div>
  );
}
