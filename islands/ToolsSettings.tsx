import { useEffect, useState } from "react";
import { Alert, ConfirmDialog, Spinner, toast } from "@tracht-digital-solutions/tds-shared/components";
import { sendJson } from "@tracht-digital-solutions/tds-shared/api";
import { SecretField, usePairing, useRuntimeSettings } from "@tracht-digital-solutions/tds-shared/admin";
import { centsToEuroInput, parseEuros } from "@tracht-digital-solutions/tds-shared/format";

interface Connection {
  origin?: string;
  status?: string;
  connected_at?: string | null;
  last_seen_at?: string | null;
}


/**
 * Designsystem-Studio export formats, in the order the studio lists them. Must
 * match `StudioPolicy::FORMATS` (PHP) and `EXPORT_FORMATS` (tds-tool-devkit).
 */
export const STUDIO_FORMATS: Array<[string, string]> = [
  ["css", "CSS"],
  ["variables", "CSS Custom Properties"],
  ["html", "HTML-Beispiele"],
  ["scss", "SCSS"],
  ["json", "JSON Design Tokens"],
  ["tailwind", "Tailwind CSS"],
  ["bootstrap", "Bootstrap 5.3"],
  ["zip", "Komplettpaket (ZIP)"],
];
const STUDIO_DEFAULT_PREMIUM = "scss,tailwind,bootstrap,zip";

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
  const settings = useRuntimeSettings("tools");
  const pairing = usePairing({
    base: "/admin/tools",
    profile: "tools",
    subject: "Die Tools-Site",
    ambiguousHint: "Bitte eine reine HTTPS-Adresse ohne Pfad eingeben.",
  });
  const connection = pairing.connection as Connection | null;
  const [adsEnabled, setAdsEnabled] = useState(false);
  const [publisherId, setPublisherId] = useState("");
  const [slotCatalog, setSlotCatalog] = useState("");
  const [slotTool, setSlotTool] = useState("");
  const [origin, setOrigin] = useState("");
  const [confirmDisconnect, setConfirmDisconnect] = useState(false);
  const [disconnecting, setDisconnecting] = useState(false);
  const [saving, setSaving] = useState(false);

  const [currency, setCurrency] = useState("EUR");
  const [successUrl, setSuccessUrl] = useState("");
  const [cancelUrl, setCancelUrl] = useState("");
  const [stripeKey, setStripeKey] = useState("");
  const [stripeHook, setStripeHook] = useState("");

  const [studioPremium, setStudioPremium] = useState<Set<string>>(new Set(STUDIO_DEFAULT_PREMIUM.split(",")));
  const [studioPrice, setStudioPrice] = useState("9,00");
  const [studioLimit, setStudioLimit] = useState("50");
  const [studioAnalysis, setStudioAnalysis] = useState(true);
  const [studioPerHour, setStudioPerHour] = useState("20");
  const [studioSheets, setStudioSheets] = useState("12");

  useEffect(() => {
    const v = settings.value;
    setAdsEnabled(v("ads_enabled", "0") === "1");
    setPublisherId(v("adsense_publisher_id"));
    setSlotCatalog(v("adsense_slot_catalog"));
    setSlotTool(v("adsense_slot_tool"));
    setCurrency(v("currency", "EUR"));
    setSuccessUrl(v("checkout_success_url"));
    setCancelUrl(v("checkout_cancel_url"));
    const premium = settings.get("studio_premium_formats")?.value ?? STUDIO_DEFAULT_PREMIUM;
    setStudioPremium(new Set(premium.split(",").map((f) => f.trim()).filter(Boolean)));
    const cents = Number(v("studio_price_cents", "900"));
    setStudioPrice(centsToEuroInput(Number.isFinite(cents) ? cents : 900));
    setStudioLimit(v("studio_project_limit", "50"));
    setStudioAnalysis(v("studio_analysis_enabled", "1") === "1");
    setStudioPerHour(v("studio_analysis_per_hour", "20"));
    setStudioSheets(v("studio_analysis_max_stylesheets", "12"));
  }, [settings.settings]);

  useEffect(() => {
    if (connection?.origin) setOrigin(connection.origin);
  }, [connection?.origin]);

  const save = async () => {
    const cents = parseEuros(studioPrice);
    if (cents === null) {
      toast.danger("Bitte einen gültigen Preis für das Designsystem-Studio eingeben (z. B. 9,00).");
      return;
    }
    const whole = (v: string, min: number, max: number, fallback: number) => {
      const n = Math.round(Number(v));
      return String(Number.isFinite(n) ? Math.min(max, Math.max(min, n)) : fallback);
    };
    setSaving(true);
    // No success toast from the hook: the one below also reports the cache refresh.
    const saved = await settings.save(
      [
        { key: "ads_enabled", value: adsEnabled ? "1" : "0" },
        { key: "adsense_publisher_id", value: publisherId.trim() },
        { key: "adsense_slot_catalog", value: slotCatalog.trim() },
        { key: "adsense_slot_tool", value: slotTool.trim() },
        { key: "currency", value: currency.trim().toUpperCase() || "EUR" },
        { key: "checkout_success_url", value: successUrl.trim() },
        { key: "checkout_cancel_url", value: cancelUrl.trim() },
        { key: "stripe_secret_key", secret: true, value: stripeKey.trim() },
        { key: "stripe_webhook_secret", secret: true, value: stripeHook.trim() },
        { key: "studio_premium_formats", value: STUDIO_FORMATS.map(([f]) => f).filter((f) => studioPremium.has(f)).join(",") },
        { key: "studio_price_cents", value: String(cents) },
        { key: "studio_project_limit", value: whole(studioLimit, 1, 1000, 50) },
        { key: "studio_analysis_enabled", value: studioAnalysis ? "1" : "0" },
        { key: "studio_analysis_per_hour", value: whole(studioPerHour, 1, 1000, 20) },
        { key: "studio_analysis_max_stylesheets", value: whole(studioSheets, 1, 30, 12) },
      ],
      null,
    );
    if (saved) {
      // The typed secrets stay in their fields after a failure, for another attempt.
      setStripeKey("");
      setStripeHook("");
      const cacheRes = await sendJson("/admin/tools/cache/rebuild", "POST", { event: "settings" }).catch(() => null);
      const cacheBody = cacheRes === null ? {} : await cacheRes.json().catch(() => ({}));
      if (cacheRes === null) {
        // The settings are stored; only the follow-up refresh was lost.
        toast.warning("Gespeichert — Cache-Aktualisierung fehlgeschlagen, die API ist nicht erreichbar.");
      } else if (cacheRes.status === 202 && cacheBody.cached === true) {
        toast.success("Gespeichert — Seiten-Cache aktualisiert.");
      } else if (cacheRes.status === 503) {
        toast.warning("Gespeichert — die Tools-Site ist noch nicht für Cache-Aktualisierungen verbunden.");
      } else {
        toast.warning(`Gespeichert — Cache-Aktualisierung fehlgeschlagen (HTTP ${cacheRes.status}).`);
      }
    }
    setSaving(false);
  };

  const disconnect = async () => {
    setDisconnecting(true);
    await pairing.disconnect();
    setDisconnecting(false);
    setConfirmDisconnect(false);
  };

  if (!settings.loaded) return <p><Spinner /></p>;

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
        {pairing.loading ? <p><Spinner size="sm" /> Verbindungsstatus wird geladen …</p> : connection ? (
          <Alert tone="success">Verbunden mit {connection.origin ?? origin}</Alert>
        ) : <Alert tone="neutral">Noch nicht mit der API verbunden.</Alert>}
        <Alert>{pairing.status}</Alert>
        {pairing.installUrl ? <p><a className="btn btn-ghost" href={pairing.installUrl}>Einrichtungslink öffnen</a></p> : null}
        <div className="tds-toolbar">
          <button type="button" className="btn btn-primary" onClick={() => void pairing.connect(origin)} disabled={pairing.connecting || origin.trim() === ""}>
            {pairing.connecting ? <Spinner size="sm" /> : connection ? "Neu verbinden" : "Mit API verbinden"}
          </button>
          {connection ? <button type="button" className="btn btn-ghost" onClick={() => setConfirmDisconnect(true)}>Verbindung trennen</button> : null}
        </div>
        <ConfirmDialog
          open={confirmDisconnect}
          title="Verbindung trennen?"
          message="Die Tools-Site verliert ihren API-Zugang, bis sie neu verbunden wird."
          confirmLabel="Trennen"
          busy={disconnecting}
          onConfirm={() => void disconnect()}
          onCancel={() => setConfirmDisconnect(false)}
        />
      </fieldset>

      <fieldset className="space-y-3">
        <legend className="text-sm font-semibold">Premium (Stripe)</legend>
        <p className="text-sm opacity-70">
          Ohne Stripe-Konto (zentral oder hier) antwortet der Checkout mit 503
          und kein Premium-Tool lässt sich kaufen. Der Webhook (…/tools/stripe-webhook) schaltet den
          Kauf frei — ohne sein Secret bleibt jede Zahlung ohne Freischaltung.
        </p>
        <p className="text-sm opacity-70">Nur nötig, wenn dieses Modul ein eigenes Stripe-Konto nutzen soll. Ohne eigenen Schlüssel gilt das zentrale Konto unter Einstellungen → Zahlungen (Stripe); dort stehen auch alle Webhook-Adressen.</p>
        <SecretField label="Secret Key, optional" setting={settings.get("stripe_secret_key")} value={stripeKey} onChange={setStripeKey} placeholder="sk_… (leer = behalten)" />
        <SecretField label="Webhook Secret" setting={settings.get("stripe_webhook_secret")} value={stripeHook} onChange={setStripeHook} placeholder="whsec_… (leer = behalten)" />
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

      <fieldset className="space-y-3">
        <legend className="text-sm font-semibold">Designsystem-Studio</legend>
        <p className="text-sm opacity-70">
          Das Studio selbst ist kostenlos. Premium (einmaliger Kauf, Freischaltung
          „design-system-studio“) schaltet das Speichern von Projekten und die
          unten als Premium markierten Exportformate frei. Ein- und ausgeschaltet
          wird das Tool in der Tool-Verwaltung.
        </p>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          {STUDIO_FORMATS.map(([format, label]) => (
            <label key={format} className="block">
              <span className="text-sm">{label}</span>
              <select
                className="field-boxed"
                value={studioPremium.has(format) ? "premium" : "free"}
                onChange={(e) => {
                  const next = new Set(studioPremium);
                  if (e.target.value === "premium") next.add(format);
                  else next.delete(format);
                  setStudioPremium(next);
                }}
              >
                <option value="free">Kostenlos</option>
                <option value="premium">Premium</option>
              </select>
            </label>
          ))}
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <label className="block">
            <span className="text-sm">Premium-Preis (Euro)</span>
            <input className="field-boxed" type="text" inputMode="decimal" value={studioPrice} onChange={(e) => setStudioPrice(e.target.value)} placeholder="9,00" />
          </label>
          <label className="block">
            <span className="text-sm">Gespeicherte Projekte pro Nutzer</span>
            <input className="field-boxed" type="number" min={1} max={1000} value={studioLimit} onChange={(e) => setStudioLimit(e.target.value)} />
          </label>
        </div>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={studioAnalysis} onChange={(e) => setStudioAnalysis(e.target.checked)} />
          Website-Analyse per HTTPS erlauben
        </label>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <label className="block">
            <span className="text-sm">Analysen pro Stunde und Besucher-Adresse</span>
            <input className="field-boxed" type="number" min={1} max={1000} value={studioPerHour} onChange={(e) => setStudioPerHour(e.target.value)} />
          </label>
          <label className="block">
            <span className="text-sm">Stylesheets pro Analyse (höchstens 30)</span>
            <input className="field-boxed" type="number" min={1} max={30} value={studioSheets} onChange={(e) => setStudioSheets(e.target.value)} />
          </label>
        </div>
      </fieldset>

      {/* The load failure is persistent state and stays in-flow; the save
          outcome is a toast. Failures only, hence the danger hue. */}
      <Alert>{settings.error}</Alert>
      <button type="button" className="btn btn-primary" onClick={() => void save()} disabled={saving} aria-busy={saving}>Speichern</button>
    </div>
  );
}
