// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { primeRuntimeConfig } from "@tracht-digital-solutions/tds-shared/api";
import { TOAST_EVENT } from "@tracht-digital-solutions/tds-shared/toast";
import ToolsSettings from "./ToolsSettings";

type Reply = { status: number; body: unknown };
type Call = { url: string; method: string; body: unknown };

const SETTINGS = "/admin/settings/tools";
const CONNECTION = "/admin/tools/connection";
const PAIRING = "/admin/tools/connection/pairing";
const CACHE = "/admin/tools/cache/rebuild";
const SETTING_KEYS = [
  "ads_enabled",
  "adsense_publisher_id",
  "adsense_slot_catalog",
  "adsense_slot_tool",
  "currency",
  "checkout_success_url",
  "checkout_cancel_url",
  "stripe_secret_key",
  "stripe_webhook_secret",
];

let calls: Call[] = [];
let settingsGet: Reply;
let settingsPut: Reply;
let connectionGet: Reply;
let pairingPost: Reply;
let cachePost: Reply;
let connectionDelete: Reply;
let toasts: Array<{ variant: string; message: string }> = [];

const pathOf = (url: string) => String(url).replace(/^https?:\/\/[^/]+/i, "");
const reply = ({ status, body }: Reply) =>
  ({ ok: status >= 200 && status < 300, status, json: async () => body }) as Response;

const collectToast = (event: Event) => {
  toasts.push((event as CustomEvent<{ variant: string; message: string }>).detail);
};

beforeEach(() => {
  primeRuntimeConfig(null);
  calls = [];
  toasts = [];
  settingsGet = { status: 200, body: { settings: [] } };
  settingsPut = { status: 200, body: {} };
  connectionGet = { status: 404, body: {} };
  pairingPost = { status: 201, body: { delivered: true, connected: true } };
  cachePost = { status: 202, body: { cached: true, cache_status: "refreshed" } };
  connectionDelete = { status: 204, body: {} };
  window.addEventListener(TOAST_EVENT, collectToast);

  vi.stubGlobal("fetch", vi.fn(async (url: string, init?: RequestInit) => {
    const method = init?.method ?? "GET";
    const body = typeof init?.body === "string" ? JSON.parse(init.body) : undefined;
    calls.push({ url: String(url), method, body });
    const path = pathOf(String(url));
    if (path === SETTINGS && method === "GET") return reply(settingsGet);
    if (path === SETTINGS && method === "PUT") return reply(settingsPut);
    if (path === CONNECTION && method === "GET") return reply(connectionGet);
    if (path === CONNECTION && method === "DELETE") return reply(connectionDelete);
    if (path === PAIRING && method === "POST") return reply(pairingPost);
    if (path === CACHE && method === "POST") return reply(cachePost);
    return reply({ status: 500, body: { error: "unexpected_test_request" } });
  }));
});

afterEach(() => {
  window.removeEventListener(TOAST_EVENT, collectToast);
  cleanup();
  vi.unstubAllGlobals();
});

const user = () => userEvent.setup({ delay: null });
const box = (name: string | RegExp) => screen.getByLabelText(name) as HTMLInputElement;
const findCall = (path: string, method: string) =>
  calls.find((call) => pathOf(call.url) === path && call.method === method);

async function open() {
  render(<ToolsSettings />);
  await screen.findByRole("button", { name: "Speichern" });
  await waitFor(() => expect(findCall(CONNECTION, "GET")).toBeDefined());
  return user();
}

describe("loading", () => {
  it("loads settings and the one tools-site connection from the API", async () => {
    await open();
    const settings = findCall(SETTINGS, "GET");
    expect(settings).toBeDefined();
    expect(settings!.url.startsWith("https://api.tracht-digital.de/")).toBe(true);
    expect(findCall(CONNECTION, "GET")).toBeDefined();
  });

  it("does not render GitHub, registry-token or cache-token fields", async () => {
    await open();
    expect(document.body.textContent).not.toContain("Repo (owner/name)");
    expect(document.body.textContent).not.toContain("Rebuild-Token");
    expect(document.body.textContent).not.toContain("Registry-Sync-Token");
    expect(document.body.textContent).not.toContain("Cache-Token");
  });

  it("loads ordinary values and exposes only masked Stripe hints", async () => {
    settingsGet = {
      status: 200,
      body: {
        settings: [
          { key: "ads_enabled", secret: false, value: "1" },
          { key: "adsense_publisher_id", secret: false, value: "ca-pub-42" },
          { key: "currency", secret: false, value: "usd" },
          { key: "stripe_secret_key", secret: true, configured: true, last4: "4242" },
          { key: "stripe_webhook_secret", secret: true, configured: false, last4: null },
        ],
      },
    };
    await open();
    expect(box("AdSense aktivieren").checked).toBe(true);
    expect(box("Publisher-ID").value).toBe("ca-pub-42");
    expect(box("Währung").value).toBe("usd");
    expect(screen.getByText("(konfiguriert (…4242))")).toBeTruthy();
    expect(screen.getByText("(nicht konfiguriert)")).toBeTruthy();
    expect(document.body.textContent).not.toContain("sk_live");
  });

  it("shows the existing paired origin", async () => {
    connectionGet = {
      status: 200,
      body: { connection: { origin: "https://tools.example", status: "connected" } },
    };
    await open();
    expect(await screen.findByText("Verbunden mit https://tools.example")).toBeTruthy();
    expect(box("Basis-URL der Tools-Site").value).toBe("https://tools.example");
  });
});

describe("saving", () => {
  it("writes only AdSense and Stripe settings, then emits a targeted cache event", async () => {
    const u = await open();
    await u.click(box("AdSense aktivieren"));
    await u.type(box("Publisher-ID"), "  ca-pub-42  ");
    await u.clear(box("Währung"));
    await u.type(box("Währung"), " usd ");
    await u.type(box(/Secret Key/), "  sk_test_42  ");
    await u.click(screen.getByRole("button", { name: "Speichern" }));

    await waitFor(() => expect(findCall(SETTINGS, "PUT")).toBeDefined());
    const payload = findCall(SETTINGS, "PUT")!.body as {
      settings: Array<{ key: string; secret: boolean; value: string }>;
    };
    expect(payload.settings.map((setting) => setting.key)).toEqual(SETTING_KEYS);
    expect(payload.settings.find((setting) => setting.key === "adsense_publisher_id")?.value).toBe("ca-pub-42");
    expect(payload.settings.find((setting) => setting.key === "currency")?.value).toBe("USD");
    expect(payload.settings.filter((setting) => setting.secret).map((setting) => setting.key)).toEqual([
      "stripe_secret_key",
      "stripe_webhook_secret",
    ]);
    expect(findCall(CACHE, "POST")?.body).toEqual({ event: "settings" });
    await waitFor(() => expect(toasts.some((toast) => toast.variant === "success")).toBe(true));
  });

  it("reports a missing cache connection without rolling back the saved settings", async () => {
    cachePost = { status: 503, body: { cached: false, cache_status: "not_configured" } };
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(findCall(CACHE, "POST")).toBeDefined());
    expect(findCall(SETTINGS, "PUT")).toBeDefined();
    expect(toasts.some((toast) => toast.variant === "warning" && toast.message.includes("noch nicht"))).toBe(true);
  });

  it("keeps a newly typed Stripe secret when saving fails", async () => {
    settingsPut = { status: 500, body: {} };
    const u = await open();
    await u.type(box(/Secret Key/), "sk_test_keep");
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(toasts.some((toast) => toast.variant === "danger")).toBe(true));
    expect(box(/Secret Key/).value).toBe("sk_test_keep");
    expect(findCall(CACHE, "POST")).toBeUndefined();
  });
});

describe("pairing", () => {
  it("posts the HTTPS origin and keeps the one-time token in the fallback fragment", async () => {
    pairingPost = {
      status: 201,
      body: {
        delivered: false,
        fallback_url: "https://tools.example/install#pairing_token=once-only-secret",
      },
    };
    const u = await open();
    await u.type(box("Basis-URL der Tools-Site"), "https://tools.example");
    await u.click(screen.getByRole("button", { name: "Mit API verbinden" }));

    await waitFor(() => expect(findCall(PAIRING, "POST")).toBeDefined());
    expect(findCall(PAIRING, "POST")!.body).toEqual({
      origin: "https://tools.example",
      profile: "tools",
    });
    expect(JSON.stringify(findCall(PAIRING, "POST")!.body)).not.toContain("once-only-secret");
    expect((await screen.findByRole("link", { name: "Einrichtungslink öffnen" })).getAttribute("href"))
      .toBe("https://tools.example/install#pairing_token=once-only-secret");
  });

  it("reloads and shows a directly delivered connection", async () => {
    let reads = 0;
    connectionGet = { status: 404, body: {} };
    const originalFetch = fetch as unknown as ReturnType<typeof vi.fn>;
    originalFetch.mockImplementation(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? "GET";
      const body = typeof init?.body === "string" ? JSON.parse(init.body) : undefined;
      calls.push({ url: String(url), method, body });
      const path = pathOf(String(url));
      if (path === SETTINGS && method === "GET") return reply(settingsGet);
      if (path === CONNECTION && method === "GET") {
        reads += 1;
        return reads === 1
          ? reply({ status: 404, body: {} })
          : reply({ status: 200, body: { connection: { origin: "https://tools.example", status: "connected" } } });
      }
      if (path === PAIRING && method === "POST") return reply(pairingPost);
      return reply({ status: 500, body: {} });
    });

    const u = await open();
    await u.type(box("Basis-URL der Tools-Site"), "https://tools.example");
    await u.click(screen.getByRole("button", { name: "Mit API verbinden" }));
    expect(await screen.findByText("Verbunden mit https://tools.example")).toBeTruthy();
    expect(toasts.some((toast) => toast.variant === "success")).toBe(true);
  });

  it("disconnects the active connection", async () => {
    connectionGet = {
      status: 200,
      body: { connection: { origin: "https://tools.example", status: "connected" } },
    };
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Verbindung trennen" }));
    await waitFor(() => expect(findCall(CONNECTION, "DELETE")).toBeDefined());
    expect(await screen.findByText("Noch nicht mit der API verbunden.")).toBeTruthy();
  });
});
