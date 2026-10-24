// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import ToolsTiers, { type Tier } from "./ToolsTiers";
import { TOAST_EVENT } from "@tracht-digital-solutions/tds-shared/toast";

/**
 * The premium-stage admin. What matters beyond "it renders":
 *
 *  - the monthly price is TYPED in euros and SENT in cents; a typo is
 *    refused in the form, never rounded into another price;
 *  - nothing is sent until Speichern;
 *  - a stage with subscriptions cannot be deleted from the UI;
 *  - every call goes to the absolute API host.
 */

type Call = { url: string; method: string; body: unknown };
let calls: Call[] = [];
let tiers: Tier[] = [];
let saveStatus = 201;
let toasts: Array<{ variant: string; message: string }> = [];
const collect = (e: Event) => toasts.push((e as CustomEvent<{ variant: string; message: string }>).detail);
const pathOf = (url: string) => String(url).replace(/^https?:\/\/[^/]+/i, "");

const TIER: Tier = {
  id: 1,
  slug: "buero",
  name: "Büro",
  name_en: "Office",
  tagline: "Alle PDF-Werkzeuge",
  tagline_en: null,
  features: ["Ohne Upload"],
  features_en: [],
  price_cents: 990,
  tool_ids: ["pdf-komprimieren"],
  active: true,
  highlight: false,
  sort_order: 0,
  subscribers: 3,
};

beforeEach(() => {
  calls = [];
  tiers = [];
  saveStatus = 201;
  toasts = [];
  window.addEventListener(TOAST_EVENT, collect);
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? "GET";
      const path = pathOf(url);
      calls.push({ url, method, body: typeof init?.body === "string" ? JSON.parse(init.body) : undefined });
      const reply = (status: number, body: unknown): Response => {
        const res = { ok: status < 300, status, json: async () => body, clone: () => res } as unknown as Response;
        return res;
      };
      if (path === "/admin/tools/tiers" && method === "GET") return reply(200, { tiers, currency: "EUR" });
      if (path === "/admin/tools") {
        return reply(200, { tools: [
          { tool_id: "pdf-komprimieren", name: "PDF komprimieren", is_premium: true },
          { tool_id: "qr-code", name: "QR-Code-Generator", is_premium: false },
        ] });
      }
      if (path.startsWith("/admin/tools/tiers")) return reply(saveStatus, saveStatus < 300 ? { ok: true } : { error: "Dieser Kurzname ist schon vergeben." });
      return reply(200, {});
    }),
  );
});

afterEach(() => {
  window.removeEventListener(TOAST_EVENT, collect);
  cleanup();
  vi.unstubAllGlobals();
});

const u = () => userEvent.setup({ delay: null });
const writes = () => calls.filter((c) => c.method !== "GET" && pathOf(c.url).startsWith("/admin/tools/tiers"));

async function openForm() {
  const user = u();
  render(<ToolsTiers />);
  await user.click(await screen.findByRole("button", { name: "Neue Stufe" }));
  return { user, form: screen.getByRole("form", { name: "Neue Premium-Stufe" }) };
}

describe("loading", () => {
  it("reads the stages from the absolute API host and lists them with their monthly price", async () => {
    tiers = [TIER];
    render(<ToolsTiers />);
    expect(await screen.findByText("Büro")).toBeTruthy();
    expect(screen.getByText(/9,90/)).toBeTruthy();
    expect(screen.getByText("PDF komprimieren")).toBeTruthy();
    const first = calls.find((c) => pathOf(c.url) === "/admin/tools/tiers")!;
    expect(first.url.startsWith("https://api.tracht-digital.de/")).toBe(true);
  });

  it("explains an empty list", async () => {
    render(<ToolsTiers />);
    expect(await screen.findByText("Noch keine Premium-Stufe.")).toBeTruthy();
  });
});

describe("creating a stage", () => {
  it("sends the euro price in cents with the chosen tools", async () => {
    const { user, form } = await openForm();
    await user.type(within(form).getByLabelText("Name"), "Büro");
    await user.type(within(form).getByLabelText("Monatspreis (€)"), "9,90");
    await user.type(within(form).getByLabelText("Leistungen (eine pro Zeile)"), "Ohne Upload{enter}Unbegrenzt");
    await user.click(within(form).getByRole("checkbox", { name: /PDF komprimieren/ }));
    expect(writes()).toHaveLength(0);
    await user.click(within(form).getByRole("button", { name: "Speichern" }));

    await waitFor(() => expect(writes()).toHaveLength(1));
    const body = writes()[0]!.body as Record<string, unknown>;
    expect(writes()[0]!.method).toBe("POST");
    expect(body.price_cents).toBe(990);
    expect(body.tool_ids).toEqual(["pdf-komprimieren"]);
    expect(body.features).toEqual(["Ohne Upload", "Unbegrenzt"]);
    await waitFor(() => expect(toasts.some((t) => t.variant === "success")).toBe(true));
  });

  it("refuses a price it cannot read instead of guessing", async () => {
    const { user, form } = await openForm();
    await user.type(within(form).getByLabelText("Name"), "Büro");
    await user.type(within(form).getByLabelText("Monatspreis (€)"), "9,999");
    await user.click(within(form).getByRole("checkbox", { name: /PDF komprimieren/ }));
    await user.click(within(form).getByRole("button", { name: "Speichern" }));

    expect((await within(form).findByRole("alert")).textContent).toContain("Monatspreis");
    expect(writes()).toHaveLength(0);
  });

  it("requires at least one tool", async () => {
    const { user, form } = await openForm();
    await user.type(within(form).getByLabelText("Name"), "Büro");
    await user.type(within(form).getByLabelText("Monatspreis (€)"), "5");
    await user.click(within(form).getByRole("button", { name: "Speichern" }));

    expect((await within(form).findByRole("alert")).textContent).toContain("Werkzeug");
    expect(writes()).toHaveLength(0);
  });

  it("shows the API's own refusal in the form", async () => {
    saveStatus = 409;
    const { user, form } = await openForm();
    await user.type(within(form).getByLabelText("Name"), "Büro");
    await user.type(within(form).getByLabelText("Monatspreis (€)"), "9,90");
    await user.click(within(form).getByRole("checkbox", { name: /PDF komprimieren/ }));
    await user.click(within(form).getByRole("button", { name: "Speichern" }));

    expect((await within(form).findByRole("alert")).textContent).toContain("schon vergeben");
  });
});

describe("an existing stage", () => {
  it("edits with a PUT and warns that running subscriptions keep their price", async () => {
    tiers = [TIER];
    const user = u();
    render(<ToolsTiers />);
    await user.click(await screen.findByRole("button", { name: "Bearbeiten" }));
    const form = screen.getByRole("form", { name: "Stufe „Büro“ bearbeiten" });
    expect((within(form).getByLabelText("Monatspreis (€)") as HTMLInputElement).value).toBe("9,90");
    expect(within(form).getByText(/Laufende Abos behalten den Preis/)).toBeTruthy();

    const price = within(form).getByLabelText("Monatspreis (€)");
    await user.clear(price);
    await user.type(price, "12,90");
    await user.click(within(form).getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(writes()).toHaveLength(1));
    expect(writes()[0]!.method).toBe("PUT");
    expect(pathOf(writes()[0]!.url)).toBe("/admin/tools/tiers/1");
    expect((writes()[0]!.body as { price_cents: number }).price_cents).toBe(1290);
  });

  it("cannot be deleted while it has subscriptions", async () => {
    tiers = [TIER];
    render(<ToolsTiers />);
    const button = (await screen.findByRole("button", { name: "Löschen" })) as HTMLButtonElement;
    expect(button.disabled).toBe(true);
  });

  it("deletes a stage without subscriptions only after the confirmation", async () => {
    tiers = [{ ...TIER, subscribers: 0 }];
    const user = u();
    render(<ToolsTiers />);
    await user.click(await screen.findByRole("button", { name: "Löschen" }));
    expect(writes()).toHaveLength(0);
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Löschen" }));
    await waitFor(() => expect(writes().map((c) => c.method)).toEqual(["DELETE"]));
  });
});
