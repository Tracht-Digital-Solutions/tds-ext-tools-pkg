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

interface Connection {
  origin?: string;
  status?: string;
  connected_at?: string | null;
  last_seen_at?: string | null;
}

const api = apiFetch;
const NS = "/admin/settings/tools";

/**
 * Tools settings — one-click API connection, AdSense and the Stripe premium
 * layer. Runtime credentials for the public site are managed by pairing, never
 * by GitHub or editable token fields. Stripe secrets stay in the core settings
 * store and come back masked; a blank secret on save keeps the existing value.
 *
 * The cache and Stripe blocks were declared in `ToolsModule::settings()` from
 * the start and rendered by nothing, so the page-cache rebuild and the whole
 * premium layer could only be configured by editing `.env` on the host. On this
 * Plesk host that is the same as not being configurable — the lesson SMTP
 * (2026-08-14) and IMAP (2026-08-15) already paid for twice.
 */
export default function ToolsSettings() {
  const [loaded, setLoaded] = useState(false);
  const [status, setStatus] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const [adsEnabled, setAdsEnabled] = useState(false);
  const [publisherId, setPublisherId] = useState("");
  const [slotCatalog, setSlotCatalog] = useState("");
  const [slotTool, setSlotTool] = useState("");
  const [connection, setConnection] = useState<Connection | null>(null);
  const [origin, setOrigin] = useState("");
  const [connectionLoaded, setConnectionLoaded] = useState(false);
  const [connecting, setConnecting] = useState(false);
  const [connectionStatus, setConnectionStatus] = useState<string | null>(null);
  const [installUrl, setInstallUrl] = useState<string | null>(null);

  const [currency, setCurrency] = useState("EUR");
  const [successUrl, setSuccessUrl] = useState("");
  const [cancelUrl, setCancelUrl] = useState("");
  const [stripeKey, setStripeKey] = useState("");
  const [stripeKeyState, setStripeKeyState] = useState<Masked | null>(null);
  const [stripeHook, setStripeHook] = useState("");
  const [stripeHookState, setStripeHookState] = useState<Masked | null>(null);

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
    setCurrency(map.get("currency")?.value || "EUR");
    setSuccessUrl(map.get("checkout_success_url")?.value || "");
    setCancelUrl(map.get("checkout_cancel_url")?.value || "");
    setStripeKeyState(map.get("stripe_secret_key") ?? null);
    setStripeHookState(map.get("stripe_webhook_secret") ?? null);
    setLoaded(true);
  };

  const loadConnection = async () => {
    try {
      const res = await api("/admin/tools/connection");
      if (res.status === 404) {
        setConnection(null);
      } else if (res.ok) {
        const body = await res.json();
        const next = (body.connection ?? body) as Connection;
        setConnection(next);
        setOrigin(next.origin ?? "");
      } else {
        setConnectionStatus(`Verbindungsstatus konnte nicht geladen werden (HTTP ${res.status}).`);
      }
    } catch {
      setConnectionStatus("Verbindungsstatus konnte nicht geladen werden (Netzwerkfehler).");
    } finally {
      setConnectionLoaded(true);
    }
  };

  useEffect(() => {
    void load();
    void loadConnection();
  }, []);

  const save = async () => {
    setBusy(true);
    setStatus(null);
    const settings: Masked[] = [
      { key: "ads_enabled", secret: false, value: adsEnabled ? "1" : "0" },
      { key: "adsense_publisher_id", secret: false, value: publisherId.trim() },
      { key: "adsense_slot_catalog", secret: false, value: slotCatalog.trim() },
      { key: "adsense_slot_tool", secret: false, value: slotTool.trim() },
      { key: "currency", secret: false, value: currency.trim().toUpperCase() || "EUR" },
      { key: "checkout_success_url", secret: false, value: successUrl.trim() },
      { key: "checkout_cancel_url", secret: false, value: cancelUrl.trim() },
      { key: "stripe_secret_key", secret: true, value: stripeKey.trim() },
      { key: "stripe_webhook_secret", secret: true, value: stripeHook.trim() },
    ];
    const res = await api(NS, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ settings }),
    });
    if (res.ok) {
      setStripeKey("");
      setStripeHook("");
      const cacheRes = await api("/admin/tools/cache/rebuild", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ event: "settings" }),
      });
      const cacheBody = await cacheRes.json().catch(() => ({}));
      if (cacheRes.status === 202 && cacheBody.cached === true) {
        toast.success("Gespeichert — Seiten-Cache aktualisiert.");
      } else if (cacheRes.status === 503) {
        toast.warning("Gespeichert — die Tools-Site ist noch nicht für Cache-Aktualisierungen verbunden.");
      } else {
        toast.warning(`Gespeichert — Cache-Aktualisierung fehlgeschlagen (HTTP ${cacheRes.status}).`);
      }
      void load();
    } else {
      toast.danger(`Speichern fehlgeschlagen (HTTP ${res.status}).`);
    }
    setBusy(false);
  };

  const connect = async () => {
    setConnecting(true);
    setConnectionStatus(null);
    setInstallUrl(null);
    try {
      const res = await api("/admin/tools/connection/pairing", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ origin: origin.trim(), profile: "tools" }),
      });
      const body = await res.json().catch(() => ({}));
      if (!res.ok) {
        setConnectionStatus(res.status === 422
          ? (body.error ?? "Bitte eine reine HTTPS-Adresse ohne Pfad eingeben.")
          : `Verbinden fehlgeschlagen (HTTP ${res.status}).`);
        return;
      }
      setInstallUrl(body.fallback_url ?? body.install_url ?? null);
      if (body.delivered === true || body.connected === true) {
        toast.success("Tools-Site mit der API verbunden.");
        await loadConnection();
      } else {
        setConnectionStatus("Die Tools-Site war nicht direkt erreichbar. Öffnen Sie den Einrichtungslink auf dem Site-Server.");
      }
    } catch {
      setConnectionStatus("Verbinden fehlgeschlagen (Netzwerkfehler).");
    } finally {
      setConnecting(false);
    }
  };

  const disconnect = async () => {
    const res = await api("/admin/tools/connection", { method: "DELETE" });
    if (res.ok) {
      setConnection(null);
      setInstallUrl(null);
      toast.success("Verbindung getrennt.");
    } else {
      toast.danger(`Trennen fehlgeschlagen (HTTP ${res.status}).`);
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
        <legend className="text-sm font-semibold">API-Verbindung</legend>
        <p className="text-sm opacity-70">
          Die Tools-Site übernimmt API-Schlüssel und Cache-Zugang automatisch. Ein
          separates Registry-Token oder GitHub-Rebuild ist nicht erforderlich.
        </p>
        <label className="block">
          <span className="text-sm">Basis-URL der Tools-Site</span>
          <input className="field-boxed" type="url" value={origin} onChange={(e) => setOrigin(e.target.value)} placeholder="https://tools.tracht-digital.de" />
        </label>
        {!connectionLoaded ? <p><Spinner size="sm" /> Verbindungsstatus wird geladen …</p> : connection ? (
          <p className="tds-alert tds-alert--success" role="status">Verbunden mit {connection.origin ?? origin}</p>
        ) : <p className="tds-alert" role="status">Noch nicht mit der API verbunden.</p>}
        {connectionStatus ? <p className="tds-alert tds-alert--danger" role="alert">{connectionStatus}</p> : null}
        {installUrl ? <p><a className="btn btn-ghost" href={installUrl}>Einrichtungslink öffnen</a></p> : null}
        <div className="tds-toolbar">
          <button type="button" className="btn btn-primary" onClick={connect} disabled={connecting || origin.trim() === ""}>
            {connecting ? <Spinner size="sm" /> : connection ? "Neu verbinden" : "Mit API verbinden"}
          </button>
          {connection ? <button type="button" className="btn btn-ghost" onClick={disconnect}>Verbindung trennen</button> : null}
        </div>
      </fieldset>

      <fieldset className="space-y-3">
        <legend className="text-sm font-semibold">Premium (Stripe)</legend>
        <p className="text-sm opacity-70">
          Ohne Secret Key antwortet der Checkout mit 503 und kein Premium-Tool
          lässt sich kaufen. Der Webhook (…/tools/stripe-webhook) schaltet den
          Kauf frei — ohne sein Secret bleibt jede Zahlung ohne Freischaltung.
        </p>
        <label className="block">
          <span className="text-sm">Secret Key <em className="opacity-60">({hint(stripeKeyState)})</em></span>
          <input className="field-boxed" type="password" value={stripeKey} onChange={(e) => setStripeKey(e.target.value)} placeholder="sk_… (leer = behalten)" autoComplete="off" />
        </label>
        <label className="block">
          <span className="text-sm">Webhook Secret <em className="opacity-60">({hint(stripeHookState)})</em></span>
          <input className="field-boxed" type="password" value={stripeHook} onChange={(e) => setStripeHook(e.target.value)} placeholder="whsec_… (leer = behalten)" autoComplete="off" />
        </label>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <label className="block">
            <span className="text-sm">Währung</span>
            <input className="field-boxed" type="text" value={currency} onChange={(e) => setCurrency(e.target.value)} placeholder="EUR" />
          </label>
          <label className="block">
            <span className="text-sm">Success-URL</span>
            <input className="field-boxed" type="url" value={successUrl} onChange={(e) => setSuccessUrl(e.target.value)} placeholder="https://tools.tracht-digital.de/" />
          </label>
        </div>
        <label className="block">
          <span className="text-sm">Cancel-URL</span>
          <input className="field-boxed" type="url" value={cancelUrl} onChange={(e) => setCancelUrl(e.target.value)} placeholder="https://tools.tracht-digital.de/" />
        </label>
      </fieldset>

      {/* The load failure is persistent state and stays in-flow; the save
          outcome is a toast. Failures only, hence the danger hue. */}
      {status ? <p className="tds-alert tds-alert--danger" role="alert">{status}</p> : null}
      <button type="button" className="btn btn-primary" onClick={save} disabled={busy} aria-busy={busy}>Speichern</button>
    </div>
  );
}
