
/**
 * Path + query of a request. The island calls an ABSOLUTE URL now (via
 * `apiFetch`); a relative one would hit the product's own static host and come
 * back as SPA-fallback HTML with a 200. Matching on the path keeps the route
 * matchers below anchored.
 */
const pathOf = (url: string) => String(url).replace(/^https?:\/\/[^/]+/i, "");
// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import ToolsSettings from "./ToolsSettings";
import { TOAST_EVENT } from "@tracht-digital-solutions/tds-shared/toast";

/**
 * AdSense config + the public-site rebuild target + the registry-sync token,
 * in the core runtime settings store.
 *
 * TWO secrets live here (the GitHub rebuild PAT and the registry token), and
 * both follow the store's contract: they come back MASKED (`configured` +
 * `last4`, never the value) and **a blank field on save means "keep the
 * existing one"**. Sending a mask or an empty-meaning-erase would silently
 * break the rebuild pipeline, whose only symptom is a public site that stops
 * updating.
 */

let calls: Array<{ url: string; method: string; body: unknown }> = [];
let getReply: { status: number; body: unknown } = { status: 200, body: { settings: [] } };
let putReply: { status: number; body: unknown } = { status: 200, body: {} };

const NS = "/admin/settings/tools";
const KEYS = [
  "ads_enabled",
  "adsense_publisher_id",
  "adsense_slot_catalog",
  "adsense_slot_tool",
  "rebuild_repo",
  "rebuild_workflow",
  "rebuild_token",
  "registry_token",
];

/** Outcomes are toasts now — collected off the `tds:toast` bus. */
let toasts: Array<{ variant: string; message: string }> = [];
const collectToast = (e: Event) => {
  toasts.push((e as CustomEvent<{ variant: string; message: string }>).detail);
};

beforeEach(() => {
  toasts = [];
  window.addEventListener(TOAST_EVENT, collectToast);
  calls = [];
  getReply = { status: 200, body: { settings: [] } };
  putReply = { status: 200, body: {} };
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? "GET";
      calls.push({ url, method, body: typeof init?.body === "string" ? JSON.parse(init.body) : undefined });
      const reply = method === "PUT" ? putReply : getReply;
      return { ok: reply.status < 300, status: reply.status, json: async () => reply.body } as Response;
    }),
  );
});

afterEach(() => {
  window.removeEventListener(TOAST_EVENT, collectToast);
  cleanup();
});

const user = () => userEvent.setup({ delay: null });
const stored = (pairs: Record<string, string>) => ({
  status: 200,
  body: { settings: Object.entries(pairs).map(([key, value]) => ({ key, secret: false, value })) },
});

async function open() {
  render(<ToolsSettings />);
  const u = user();
  await waitFor(() => expect(calls.length).toBeGreaterThan(0));
  await screen.findByRole("button", { name: "Speichern" });
  return u;
}

const box = (name: string) => screen.getByLabelText(name) as HTMLInputElement;
const rebuildTokenBox = () => screen.getByPlaceholderText("ghp_… (leer = behalten)") as HTMLInputElement;
const registryTokenBox = () => screen.getByPlaceholderText("(leer = behalten)") as HTMLInputElement;
const put = () => calls.find((c) => c.method === "PUT");
const saved = () => (put()!.body as { settings: Array<{ key: string; secret: boolean; value: string }> }).settings;
const setting = (key: string) => saved().find((s) => s.key === key)!;

describe("loading", () => {
  it("reads its own namespace of the settings store", async () => {
    await open();
    const fetchMock = fetch as unknown as ReturnType<typeof vi.fn>;
    expect(pathOf(fetchMock.mock.calls[0]![0] as string)).toBe(NS);
    // Absolute, on the API host. Every other assertion here matches the PATH,
    // which a relative fetch satisfies too — so this is the one that fails if
    // the call ever goes back to the product's own origin (whose SPA fallback
    // answers 200 + HTML and turns into a silent empty state).
    expect(String(fetchMock.mock.calls[0]![0]).startsWith("https://api.tracht-digital.de/")).toBe(true);
    expect(fetchMock.mock.calls[0]![1]).toMatchObject({ credentials: "include" });
  });

  it("shows a loading line until the settings arrive", () => {
    render(<ToolsSettings />);
    expect(screen.getByLabelText("Wird geladen")).toBeTruthy();
  });

  it("leaves AdSense OFF until it is switched on", async () => {
    // Ads render on a public, indexable site — nothing goes live unattended.
    await open();
    expect(box("AdSense aktivieren").checked).toBe(false);
  });

  it("reflects AdSense switched on", async () => {
    getReply = stored({ ads_enabled: "1" });
    await open();
    expect(box("AdSense aktivieren").checked).toBe(true);
  });

  it("treats a stored 0 as off", async () => {
    getReply = stored({ ads_enabled: "0" });
    await open();
    expect(box("AdSense aktivieren").checked).toBe(false);
  });

  it("fills the AdSense fields from the store", async () => {
    getReply = stored({
      adsense_publisher_id: "ca-pub-42",
      adsense_slot_catalog: "111",
      adsense_slot_tool: "222",
    });
    await open();
    expect(box("Publisher-ID").value).toBe("ca-pub-42");
    expect(box("Slot (Übersicht)").value).toBe("111");
    expect(box("Slot (Tool-Seite)").value).toBe("222");
  });

  it("fills the rebuild target from the store", async () => {
    getReply = stored({ rebuild_repo: "Tracht-Digital-Solutions/tds-tools-frontend", rebuild_workflow: "release.yml" });
    await open();
    expect(box("Repo (owner/name)").value).toBe("Tracht-Digital-Solutions/tds-tools-frontend");
    expect(box("Workflow").value).toBe("release.yml");
  });

  it("defaults the workflow to dev.yml", async () => {
    await open();
    expect(box("Workflow").value).toBe("dev.yml");
  });

  it("leaves the repo empty rather than guessing one", async () => {
    // A wrong repo here dispatches a rebuild of somebody else's site.
    await open();
    expect(box("Repo (owner/name)").value).toBe("");
  });

  it("reports BOTH secrets as unconfigured when neither is set", async () => {
    await open();
    expect(screen.getAllByText("(nicht konfiguriert)")).toHaveLength(2);
  });

  it("shows only the last four characters of a configured secret", async () => {
    getReply = {
      status: 200,
      body: {
        settings: [
          { key: "rebuild_token", secret: true, configured: true, last4: "aa11" },
          { key: "registry_token", secret: true, configured: true, last4: "bb22" },
        ],
      },
    };
    await open();
    expect(screen.getByText("(konfiguriert (…aa11))")).toBeTruthy();
    expect(screen.getByText("(konfiguriert (…bb22))")).toBeTruthy();
  });

  it("keeps the two secrets' hints apart", async () => {
    // One shared hint would make a configured registry token look like a
    // configured rebuild token, and vice versa.
    getReply = {
      status: 200,
      body: { settings: [{ key: "registry_token", secret: true, configured: true, last4: "bb22" }] },
    };
    await open();
    expect(screen.getByText("(konfiguriert (…bb22))")).toBeTruthy();
    expect(screen.getByText("(nicht konfiguriert)")).toBeTruthy();
  });

  it("copes with a configured secret that reports no last4", async () => {
    getReply = {
      status: 200,
      body: { settings: [{ key: "rebuild_token", secret: true, configured: true, last4: null }] },
    };
    await open();
    expect(screen.getByText("(konfiguriert (…????))")).toBeTruthy();
  });

  it("reports a KNOWN-BUT-EMPTY secret as unconfigured", async () => {
    getReply = {
      status: 200,
      body: { settings: [{ key: "rebuild_token", secret: true, configured: false, last4: null }] },
    };
    await open();
    expect(screen.getAllByText("(nicht konfiguriert)")).toHaveLength(2);
  });

  it("never renders a secret value even if the API leaks one", async () => {
    getReply = {
      status: 200,
      body: { settings: [{ key: "rebuild_token", secret: true, configured: true, last4: "aa11", value: "ghp_secret" }] },
    };
    await open();
    expect(document.body.textContent).not.toContain("ghp_secret");
    expect(rebuildTokenBox().value).toBe("");
  });

  it("keeps both token fields masked", async () => {
    await open();
    expect(rebuildTokenBox().getAttribute("type")).toBe("password");
    expect(registryTokenBox().getAttribute("type")).toBe("password");
    expect(rebuildTokenBox().getAttribute("autocomplete")).toBe("off");
  });

  it("names the reason when the user is not an admin", async () => {
    getReply = { status: 403, body: {} };
    render(<ToolsSettings />);
    expect(await screen.findByText("Nur für Administratoren.")).toBeTruthy();
  });

  it("treats an expired session the same way", async () => {
    getReply = { status: 401, body: {} };
    render(<ToolsSettings />);
    expect(await screen.findByText("Nur für Administratoren.")).toBeTruthy();
  });

  it("reports any other failure with its status", async () => {
    getReply = { status: 500, body: {} };
    render(<ToolsSettings />);
    expect(await screen.findByText("Fehler (HTTP 500).")).toBeTruthy();
  });

  it("leaves the loading state even when the request fails", async () => {
    getReply = { status: 500, body: {} };
    render(<ToolsSettings />);
    await screen.findByText("Fehler (HTTP 500).");
    expect(screen.queryByLabelText("Wird geladen")).toBeNull();
  });

  it("does NOT apply values carried by a non-OK response", async () => {
    getReply = { status: 403, body: { settings: [{ key: "ads_enabled", secret: false, value: "1" }] } };
    render(<ToolsSettings />);
    await screen.findByText("Nur für Administratoren.");
    expect(box("AdSense aktivieren").checked).toBe(false);
  });

  it("tolerates a response with no settings array", async () => {
    getReply = { status: 200, body: {} };
    await open();
    expect(box("Publisher-ID")).toBeTruthy();
  });
});

describe("saving", () => {
  it("writes back to the same namespace as JSON", async () => {
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(put()).toBeDefined());
    expect(pathOf(put()!.url)).toBe(NS);
    const fetchMock = fetch as unknown as ReturnType<typeof vi.fn>;
    const call = fetchMock.mock.calls.find((c) => (c[1] as RequestInit)?.method === "PUT")!;
    expect((call[1] as RequestInit).headers).toMatchObject({ "Content-Type": "application/json" });
  });

  it("sends the whole key set", async () => {
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(put()).toBeDefined());
    expect(saved().map((s) => s.key).sort()).toEqual([...KEYS].sort());
  });

  it("sends EMPTY tokens when neither field was touched", async () => {
    // The store's "keep the existing secret" signal. Anything else here
    // overwrites a working PAT and silently kills the rebuild pipeline.
    getReply = {
      status: 200,
      body: { settings: [{ key: "rebuild_token", secret: true, configured: true, last4: "aa11" }] },
    };
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(put()).toBeDefined());
    expect(setting("rebuild_token").value).toBe("");
    expect(setting("registry_token").value).toBe("");
  });

  it("sends a newly typed token", async () => {
    const u = await open();
    await u.type(rebuildTokenBox(), "ghp_new");
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(put()).toBeDefined());
    expect(setting("rebuild_token").value).toBe("ghp_new");
  });

  it("keeps the two tokens apart when only one is typed", async () => {
    const u = await open();
    await u.type(registryTokenBox(), "reg_new");
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(put()).toBeDefined());
    expect(setting("registry_token").value).toBe("reg_new");
    expect(setting("rebuild_token").value).toBe("");
  });

  it("marks BOTH tokens as secrets and nothing else", async () => {
    // A token stored with secret:false lands in the DB in plaintext.
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(put()).toBeDefined());
    for (const s of saved()) {
      expect(s.secret, `${s.key}`).toBe(s.key === "rebuild_token" || s.key === "registry_token");
    }
  });

  it("writes the AdSense switch as the string 1 or 0", async () => {
    const u = await open();
    await u.click(box("AdSense aktivieren"));
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(put()).toBeDefined());
    expect(setting("ads_enabled").value).toBe("1");
  });

  it("writes 0 when AdSense is switched back off", async () => {
    getReply = stored({ ads_enabled: "1" });
    const u = await open();
    await u.click(box("AdSense aktivieren"));
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(put()).toBeDefined());
    expect(setting("ads_enabled").value).toBe("0");
  });

  it("trims whitespace off every value", async () => {
    // A padded repo slug dispatches to a repository that does not exist.
    const u = await open();
    await u.type(box("Repo (owner/name)"), "  Tracht-Digital-Solutions/tds-tools-frontend  ");
    await u.type(box("Publisher-ID"), "  ca-pub-42  ");
    await u.type(rebuildTokenBox(), "  ghp_padded  ");
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(put()).toBeDefined());
    expect(setting("rebuild_repo").value).toBe("Tracht-Digital-Solutions/tds-tools-frontend");
    expect(setting("adsense_publisher_id").value).toBe("ca-pub-42");
    expect(setting("rebuild_token").value).toBe("ghp_padded");
  });

  it("falls back to dev.yml rather than saving an empty workflow", async () => {
    // An empty workflow name makes every rebuild dispatch 404.
    const u = await open();
    await u.clear(box("Workflow"));
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(put()).toBeDefined());
    expect(setting("rebuild_workflow").value).toBe("dev.yml");
  });

  it("keeps an explicitly chosen workflow", async () => {
    const u = await open();
    await u.clear(box("Workflow"));
    await u.type(box("Workflow"), "release.yml");
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(put()).toBeDefined());
    expect(setting("rebuild_workflow").value).toBe("release.yml");
  });

  it("confirms and re-reads the masked state after a save", async () => {
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(toasts.some((t) => t.variant === "success" && t.message.includes("Gespeichert"))).toBe(true));
    await waitFor(() => expect(calls.filter((c) => c.method === "GET")).toHaveLength(2));
  });

  it("clears both typed tokens after a successful save", async () => {
    const u = await open();
    await u.type(rebuildTokenBox(), "ghp_new");
    await u.type(registryTokenBox(), "reg_new");
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(toasts.some((t) => t.variant === "success" && t.message.includes("Gespeichert"))).toBe(true));
    expect(rebuildTokenBox().value).toBe("");
    expect(registryTokenBox().value).toBe("");
  });

  it("KEEPS the typed tokens when the save fails", async () => {
    putReply = { status: 500, body: {} };
    const u = await open();
    await u.type(rebuildTokenBox(), "ghp_new");
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(toasts.some((t) => t.variant === "danger" && t.message.includes("500"))).toBe(true));
    expect(rebuildTokenBox().value).toBe("ghp_new");
  });

  it("does not re-read after a failed save", async () => {
    putReply = { status: 403, body: {} };
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(toasts.some((t) => t.variant === "danger" && t.message.includes("403"))).toBe(true));
    expect(calls.filter((c) => c.method === "GET")).toHaveLength(1);
  });

  it("re-enables the save button afterwards", async () => {
    const u = await open();
    const button = screen.getByRole("button", { name: "Speichern" }) as HTMLButtonElement;
    await u.click(button);
    await waitFor(() => expect(toasts.some((t) => t.variant === "success" && t.message.includes("Gespeichert"))).toBe(true));
    expect(button.disabled).toBe(false);
  });
});
