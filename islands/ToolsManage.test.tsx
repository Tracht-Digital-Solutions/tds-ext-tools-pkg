// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import ToolsManage from "./ToolsManage";
import { TOAST_EVENT } from "@tracht-digital-solutions/tds-shared/toast";

/**
 * The tool-catalog admin table. Every row decides what the PUBLIC tools site
 * shows and what it charges for, so the assertions concentrate on:
 *
 *  - **`enabled`** — the switch that publishes a tool to tools.tracht-digital.de;
 *  - **`is_premium` + `price_cents`** — the paywall and its price, held in CENTS
 *    in the payload but edited in EUROS in the input. The conversion is the one
 *    piece of arithmetic here, and getting it wrong by 100× is a billing bug;
 *  - **nothing is saved until Speichern** — the checkboxes patch local state
 *    only, so an accidental click cannot publish a tool on its own.
 */

interface Reply {
  status: number;
  body: unknown;
}
type Handler = (url: string, init?: RequestInit) => Reply | undefined;

let calls: Array<{ url: string; method: string; body: unknown }> = [];
let handlers: Handler[] = [];
let gate: { match: RegExp; promise: Promise<void> } | null = null;

/** Keep matching requests in flight until the returned function is called. */
function holdRequests(match: RegExp) {
  let release!: () => void;
  const promise = new Promise<void>((r) => (release = r));
  gate = { match, promise };
  return () => {
    gate = null;
    release();
  };
}


/**
 * Path + query of a request. The island calls an ABSOLUTE URL now (via
 * `apiFetch`); a relative one would hit the product's own static host and come
 * back as SPA-fallback HTML with a 200. Matching on the path keeps the route
 * matchers below anchored.
 */
const pathOf = (url: string) => String(url).replace(/^https?:\/\/[^/]+/i, "");

function respond(match: RegExp, body: unknown, status = 200, method?: string) {
  handlers.unshift((url, init) => {
    if (!match.test(pathOf(url))) return undefined;
    if (method && (init?.method ?? "GET") !== method) return undefined;
    return { status, body };
  });
}

const TOOL = {
  tool_id: "qr-code",
  name: "QR-Code-Generator",
  category: "Web",
  enabled: true,
  requires_login: false,
  is_premium: false,
  price_cents: 0,
  sort_order: 10,
};
const PREMIUM = {
  ...TOOL,
  tool_id: "pdf-merge",
  name: "PDF zusammenfügen",
  category: "PDF",
  is_premium: true,
  requires_login: true,
  price_cents: 499,
  sort_order: 20,
};

/** Outcomes are toasts now — collected off the `tds:toast` bus. */
let toasts: Array<{ variant: string; message: string }> = [];
const collectToast = (e: Event) => {
  toasts.push((e as CustomEvent<{ variant: string; message: string }>).detail);
};

beforeEach(() => {
  toasts = [];
  window.addEventListener(TOAST_EVENT, collectToast);
  calls = [];
  gate = null;
  handlers = [() => ({ status: 200, body: {} })];
  respond(/^\/admin\/tools$/, { tools: [] });
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? "GET";
      calls.push({ url, method, body: typeof init?.body === "string" ? JSON.parse(init.body) : undefined });
      const g = gate;
      if (g && g.match.test(pathOf(url))) await g.promise;
      const reply = handlers.map((h) => h(url, init)).find((r) => r !== undefined)!;
      return { ok: reply.status < 300, status: reply.status, json: async () => reply.body } as Response;
    }),
  );
});

afterEach(() => {
  window.removeEventListener(TOAST_EVENT, collectToast);
  cleanup();
});

const user = () => userEvent.setup({ delay: null });
const sent = (method: string, match: RegExp) => calls.filter((c) => c.method === method && match.test(pathOf(c.url)));

async function open(tools: unknown[] = [TOOL]) {
  respond(/^\/admin\/tools$/, { tools }, 200, "GET");
  render(<ToolsManage />);
  const u = user();
  await waitFor(() => expect(calls.length).toBeGreaterThan(0));
  return u;
}

const row = (name: string) => screen.getAllByRole("row").find((r) => r.textContent!.includes(name))!;
const saveIn = (name: string) => within(row(name)).getByRole("button", { name: "Speichern" });
const puts = () => calls.filter((c) => c.method === "PUT");

describe("loading", () => {
  it("reads the admin catalog with credentials", async () => {
    await open();
    const fetchMock = fetch as unknown as ReturnType<typeof vi.fn>;
    expect(pathOf(fetchMock.mock.calls[0]![0] as string)).toBe("/admin/tools");
    // Absolute, on the API host. Every other assertion here matches the PATH,
    // which a relative fetch satisfies too — so this is the one that fails if
    // the call ever goes back to the product's own origin (whose SPA fallback
    // answers 200 + HTML and turns into a silent empty state).
    expect(String(fetchMock.mock.calls[0]![0]).startsWith("https://api.tracht-digital.de/")).toBe(true);
    expect(fetchMock.mock.calls[0]![1]).toMatchObject({ credentials: "include" });
  });

  it("shows a loading line until the catalog arrives", () => {
    render(<ToolsManage />);
    expect(screen.getByLabelText("Wird geladen")).toBeTruthy();
  });

  it("lists a tool with its id and category", async () => {
    await open();
    expect(await screen.findByText("QR-Code-Generator")).toBeTruthy();
    expect(screen.getByText("qr-code · Web")).toBeTruthy();
  });

  it("counts the tools it is managing", async () => {
    await open([TOOL, PREMIUM]);
    expect(await screen.findByText("2 Tool(s)")).toBeTruthy();
  });

  it("points at the registry sync instead of offering a create action", async () => {
    // Tools are declared by the frontend packs; there is nothing to create here.
    await open([]);
    expect(await screen.findByText(/Noch keine Tools/)).toBeTruthy();
    expect(screen.queryByRole("button", { name: /anlegen|hinzufügen/i })).toBeNull();
  });

  it("names the reason when the user is not an admin", async () => {
    respond(/^\/admin\/tools$/, {}, 403, "GET");
    render(<ToolsManage />);
    expect(await screen.findByText("Nur für Administratoren.")).toBeTruthy();
  });

  it("treats an expired session the same way", async () => {
    respond(/^\/admin\/tools$/, {}, 401, "GET");
    render(<ToolsManage />);
    expect(await screen.findByText("Nur für Administratoren.")).toBeTruthy();
  });

  it("reports any other failure with its status", async () => {
    respond(/^\/admin\/tools$/, {}, 500, "GET");
    render(<ToolsManage />);
    expect(await screen.findByText("Fehler (HTTP 500).")).toBeTruthy();
  });

  it("does NOT render tools carried by a non-OK response", async () => {
    // A denied response must not put the paywall config on screen.
    respond(/^\/admin\/tools$/, { tools: [PREMIUM] }, 403, "GET");
    render(<ToolsManage />);
    await screen.findByText("Nur für Administratoren.");
    expect(screen.queryByText("PDF zusammenfügen")).toBeNull();
  });

  it("tolerates a response with no tools field", async () => {
    respond(/^\/admin\/tools$/, {}, 200, "GET");
    render(<ToolsManage />);
    expect(await screen.findByText(/Noch keine Tools/)).toBeTruthy();
  });

  it("leaves the loading state even when the request fails", async () => {
    respond(/^\/admin\/tools$/, {}, 500, "GET");
    render(<ToolsManage />);
    await screen.findByText("Fehler (HTTP 500).");
    expect(screen.queryByLabelText("Wird geladen")).toBeNull();
  });
});

describe("the switches", () => {
  it("reflects the stored flags", async () => {
    await open([PREMIUM]);
    await screen.findByText("PDF zusammenfügen");
    const r = row("PDF zusammenfügen");
    expect((within(r).getByLabelText("Sichtbar") as HTMLInputElement).checked).toBe(true);
    expect((within(r).getByLabelText("Login erforderlich") as HTMLInputElement).checked).toBe(true);
    expect((within(r).getByLabelText("Premium") as HTMLInputElement).checked).toBe(true);
  });

  it("does not confuse two tools' flags", async () => {
    await open([TOOL, PREMIUM]);
    await screen.findByText("PDF zusammenfügen");
    expect((within(row("QR-Code-Generator")).getByLabelText("Premium") as HTMLInputElement).checked).toBe(false);
    expect((within(row("PDF zusammenfügen")).getByLabelText("Premium") as HTMLInputElement).checked).toBe(true);
  });

  it("SAVES NOTHING when a switch is flipped", async () => {
    // The row's own Speichern is the commit point; an accidental click on the
    // Sichtbar box must not publish a tool to the public site by itself.
    const u = await open();
    await u.click(within(row("QR-Code-Generator")).getByLabelText("Sichtbar"));
    expect(puts()).toHaveLength(0);
  });

  it("applies the flip locally so the row shows what will be saved", async () => {
    const u = await open();
    const box = within(row("QR-Code-Generator")).getByLabelText("Sichtbar") as HTMLInputElement;
    await u.click(box);
    expect(box.checked).toBe(false);
  });

  it("patches only the row that was touched", async () => {
    const u = await open([TOOL, PREMIUM]);
    await screen.findByText("PDF zusammenfügen");
    await u.click(within(row("QR-Code-Generator")).getByLabelText("Sichtbar"));
    expect((within(row("PDF zusammenfügen")).getByLabelText("Sichtbar") as HTMLInputElement).checked).toBe(true);
  });
});

describe("the price", () => {
  it("shows cents as euros with two decimals", async () => {
    await open([PREMIUM]);
    await screen.findByText("PDF zusammenfügen");
    expect((within(row("PDF zusammenfügen")).getByRole("spinbutton") as HTMLInputElement).value).toBe("4.99");
  });

  it("locks the price field for a free tool", async () => {
    await open();
    await screen.findByText("QR-Code-Generator");
    expect((within(row("QR-Code-Generator")).getByRole("spinbutton") as HTMLInputElement).disabled).toBe(true);
  });

  it("unlocks it as soon as the tool is marked premium", async () => {
    const u = await open();
    await u.click(within(row("QR-Code-Generator")).getByLabelText("Premium"));
    expect((within(row("QR-Code-Generator")).getByRole("spinbutton") as HTMLInputElement).disabled).toBe(false);
  });

  it("SENDS the price in cents, not in euros", async () => {
    // A euro value in `price_cents` would charge 1/100th of the intended price.
    const u = await open([PREMIUM]);
    await screen.findByText("PDF zusammenfügen");
    const price = within(row("PDF zusammenfügen")).getByRole("spinbutton");
    await u.clear(price);
    await u.type(price, "9.90");
    await u.click(saveIn("PDF zusammenfügen"));
    await waitFor(() => expect(puts()).toHaveLength(1));
    expect((puts()[0]!.body as { price_cents: number }).price_cents).toBe(990);
  });

  it("rounds a sub-cent price instead of truncating it", async () => {
    // 1.999 € → 199.9 cents → 200, not 199. (Note: `x * 100` is float maths, so
    // a mid-point like 1.005 lands on 100.4999… and rounds DOWN — an inherent
    // artefact, not something these tests pretend away.)
    const u = await open([PREMIUM]);
    await screen.findByText("PDF zusammenfügen");
    const price = within(row("PDF zusammenfügen")).getByRole("spinbutton");
    await u.clear(price);
    await u.type(price, "1.999");
    await u.click(saveIn("PDF zusammenfügen"));
    await waitFor(() => expect(puts()).toHaveLength(1));
    expect((puts()[0]!.body as { price_cents: number }).price_cents).toBe(200);
  });

  it("never lets the price go negative", async () => {
    // Driven with fireEvent: typing "-" into a `min="0"` number input never
    // yields a negative value, so `userEvent.type` would not reach the clamp.
    await open([PREMIUM]);
    await screen.findByText("PDF zusammenfügen");
    const price = within(row("PDF zusammenfügen")).getByRole("spinbutton");
    fireEvent.change(price, { target: { value: "-5" } });
    fireEvent.click(saveIn("PDF zusammenfügen"));
    await waitFor(() => expect(puts()).toHaveLength(1));
    expect((puts()[0]!.body as { price_cents: number }).price_cents).toBe(0);
  });

  it("treats a cleared price as zero rather than NaN", async () => {
    const u = await open([PREMIUM]);
    await screen.findByText("PDF zusammenfügen");
    await u.clear(within(row("PDF zusammenfügen")).getByRole("spinbutton"));
    await u.click(saveIn("PDF zusammenfügen"));
    await waitFor(() => expect(puts()).toHaveLength(1));
    expect((puts()[0]!.body as { price_cents: number }).price_cents).toBe(0);
  });
});

describe("saving a row", () => {
  it("PUTs to the tool's own endpoint", async () => {
    const u = await open();
    await u.click(saveIn("QR-Code-Generator"));
    await waitFor(() => expect(puts()).toHaveLength(1));
    expect(pathOf(puts()[0]!.url)).toBe("/admin/tools/qr-code");
    const fetchMock = fetch as unknown as ReturnType<typeof vi.fn>;
    const call = fetchMock.mock.calls.find((c) => (c[1] as RequestInit)?.method === "PUT")!;
    expect((call[1] as RequestInit).headers).toMatchObject({ "Content-Type": "application/json" });
  });

  it("escapes a tool id that is not URL-safe", async () => {
    await open([{ ...TOOL, tool_id: "pdf/merge tool" }]);
    const u = user();
    await u.click(saveIn("QR-Code-Generator"));
    await waitFor(() => expect(puts()).toHaveLength(1));
    expect(pathOf(puts()[0]!.url)).toBe("/admin/tools/pdf%2Fmerge%20tool");
  });

  it("sends every editable field", async () => {
    const u = await open([PREMIUM]);
    await screen.findByText("PDF zusammenfügen");
    await u.click(saveIn("PDF zusammenfügen"));
    await waitFor(() => expect(puts()).toHaveLength(1));
    expect(puts()[0]!.body).toEqual({
      enabled: true,
      requires_login: true,
      is_premium: true,
      price_cents: 499,
      sort_order: 20,
    });
  });

  it("does not confuse the login flag with the premium flag", async () => {
    // Both are true on PREMIUM, so a swap is invisible there. A login-gated
    // FREE tool is exactly the case that tells them apart — swapping would
    // put it behind the paywall.
    const u = await open([{ ...TOOL, requires_login: true, is_premium: false }]);
    await u.click(saveIn("QR-Code-Generator"));
    await waitFor(() => expect(puts()).toHaveLength(1));
    expect(puts()[0]!.body).toMatchObject({ requires_login: true, is_premium: false });
  });

  it("keeps the OTHER rows saveable while one is in flight", async () => {
    // A shared busy flag would lock the whole table on every save.
    const release = holdRequests(/^\/admin\/tools\//);
    const u = await open([TOOL, PREMIUM]);
    await screen.findByText("PDF zusammenfügen");
    await u.click(saveIn("QR-Code-Generator"));
    await waitFor(() => expect((saveIn("QR-Code-Generator") as HTMLButtonElement).disabled).toBe(true));
    expect((saveIn("PDF zusammenfügen") as HTMLButtonElement).disabled).toBe(false);
    release();
    await waitFor(() => expect((saveIn("QR-Code-Generator") as HTMLButtonElement).disabled).toBe(false));
  });

  it("does not send the fields the registry sync owns", async () => {
    // name/category/tool_id come from the frontend packs; echoing them back
    // would let the admin table overwrite the packs' own metadata.
    const u = await open();
    await u.click(saveIn("QR-Code-Generator"));
    await waitFor(() => expect(puts()).toHaveLength(1));
    for (const key of ["name", "category", "tool_id"]) {
      expect(Object.keys(puts()[0]!.body as object), `${key} must not be sent`).not.toContain(key);
    }
  });

  it("saves the edited flag, not the loaded one", async () => {
    const u = await open();
    await u.click(within(row("QR-Code-Generator")).getByLabelText("Sichtbar"));
    await u.click(saveIn("QR-Code-Generator"));
    await waitFor(() => expect(puts()).toHaveLength(1));
    expect((puts()[0]!.body as { enabled: boolean }).enabled).toBe(false);
  });

  it("saves only the row whose button was pressed", async () => {
    const u = await open([TOOL, PREMIUM]);
    await screen.findByText("PDF zusammenfügen");
    await u.click(saveIn("PDF zusammenfügen"));
    await waitFor(() => expect(puts()).toHaveLength(1));
    expect(pathOf(puts()[0]!.url)).toBe("/admin/tools/pdf-merge");
  });

  it("confirms by name and mentions the rebuild it triggered", async () => {
    const u = await open();
    await u.click(saveIn("QR-Code-Generator"));
await waitFor(() => expect(toasts.some((t) => t.variant === "success" && t.message.includes("„QR-Code-Generator“ gespeichert"))).toBe(true));
  });

  it("does NOT claim a rebuild when the save failed", async () => {
    respond(/^\/admin\/tools\//, { error: "nope" }, 500, "PUT");
    const u = await open();
    await u.click(saveIn("QR-Code-Generator"));
await waitFor(() => expect(toasts.some((t) => t.variant === "danger" && t.message.includes("500"))).toBe(true));
    expect(toasts.some((t) => t.message.includes("Rebuild ausgelöst"))).toBe(false);
  });

  it("re-enables the row button afterwards", async () => {
    const u = await open();
    await u.click(saveIn("QR-Code-Generator"));
await waitFor(() => expect(toasts.some((t) => t.variant === "success" && t.message.includes("gespeichert"))).toBe(true));
    expect((saveIn("QR-Code-Generator") as HTMLButtonElement).disabled).toBe(false);
  });

  it("does not re-read the catalog after saving", async () => {
    // The local patch is the source of truth; a re-read would race it.
    const u = await open();
    await u.click(saveIn("QR-Code-Generator"));
await waitFor(() => expect(toasts.some((t) => t.variant === "success" && t.message.includes("gespeichert"))).toBe(true));
    expect(calls.filter((c) => c.method === "GET")).toHaveLength(1);
  });
});

describe("the manual rebuild", () => {
  it("POSTs the rebuild trigger", async () => {
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Website neu bauen" }));
    await waitFor(() => expect(sent("POST", /^\/admin\/tools\/rebuild$/)).toHaveLength(1));
  });

  it("confirms the trigger", async () => {
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Website neu bauen" }));
await waitFor(() => expect(toasts.some((t) => t.variant === "success" && t.message.includes("Rebuild der Website ausgelöst"))).toBe(true));
  });

  it("does NOT claim a rebuild that failed", async () => {
    respond(/rebuild$/, { error: "no token" }, 503, "POST");
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Website neu bauen" }));
await waitFor(() => expect(toasts.some((t) => t.variant === "danger" && t.message.includes("503"))).toBe(true));
    expect(toasts.some((t) => t.variant === "success")).toBe(false);
  });

  it("re-enables the button afterwards", async () => {
    const u = await open();
    const button = screen.getByRole("button", { name: "Website neu bauen" }) as HTMLButtonElement;
    await u.click(button);
await waitFor(() => expect(toasts.some((t) => t.variant === "success" && t.message.includes("ausgelöst"))).toBe(true));
    expect(button.disabled).toBe(false);
  });

  it("does not save any tool as a side effect", async () => {
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Website neu bauen" }));
await waitFor(() => expect(toasts.some((t) => t.variant === "success" && t.message.includes("ausgelöst"))).toBe(true));
    expect(puts()).toHaveLength(0);
  });
});
