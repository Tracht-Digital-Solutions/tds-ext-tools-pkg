// @vitest-environment jsdom
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import ToolGuides from "./ToolGuides.tsx";
import { TOAST_EVENT } from "@tracht-digital-solutions/tds-shared/toast";

const TOOLS = [
  { tool_id: "qr", name: "QR-Code-Generator", category: "web" },
  { tool_id: "pdf", name: "PDF-Werkzeuge", category: "office" },
];

let guides: unknown[] = [];
let calls: Array<{ url: string; init?: RequestInit }> = [];

function mockFetch() {
  return vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input);
    calls.push({ url, init });
    if (url.includes("/admin/tools/guides")) {
      if (init?.method === "PUT" || init?.method === "DELETE") {
        return new Response(JSON.stringify({ ok: true }), { status: 200 });
      }
      return new Response(JSON.stringify({ guides }), { status: 200 });
    }
    if (url.includes("/admin/tools")) {
      return new Response(JSON.stringify({ tools: TOOLS }), { status: 200 });
    }
    return new Response("{}", { status: 404 });
  });
}

/** The body of the last PUT, parsed. */
function lastPut(): Record<string, unknown> {
  const put = [...calls].reverse().find((c) => c.init?.method === "PUT");
  return JSON.parse(String(put?.init?.body ?? "{}"));
}

describe("ToolGuides", () => {
  beforeEach(() => {
    calls = [];
    guides = [];
    vi.stubGlobal("fetch", mockFetch());
  });

  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
  });

  it("asks which tool before showing a form", async () => {
    render(<ToolGuides />);
    expect(await screen.findByText("Wählen Sie ein Tool, um seinen Text zu bearbeiten.")).toBeTruthy();
  });

  it("says out loud that an empty field means 'use the shipped text'", async () => {
    // The single most important sentence on this screen. Without it an editor
    // reads an empty Einleitung as "there is no intro", pastes one in, and
    // quietly detaches the page from the copy committed in the site's repo.
    render(<ToolGuides />);
    const hint = await screen.findByText(/leeres Feld heißt/i);
    expect(hint.textContent).toContain("Repository");
  });

  it("opens empty for a tool with no override", async () => {
    const u = userEvent.setup();
    render(<ToolGuides />);
    await u.selectOptions(await screen.findByLabelText("Tool"), "qr");

    expect((screen.getByLabelText(/^Name$/) as HTMLInputElement).value).toBe("");
    expect(screen.queryByText(/eigener Text hinterlegt/i)).toBeNull();
  });

  it("loads a stored override and flags that one exists", async () => {
    guides = [
      { tool_id: "qr", lang: "de", name: "Eigener Name", intro: ["Erster Absatz."] },
    ];
    const u = userEvent.setup();
    render(<ToolGuides />);
    await u.selectOptions(await screen.findByLabelText("Tool"), "qr");

    await waitFor(() => {
      expect((screen.getByLabelText(/^Name$/) as HTMLInputElement).value).toBe("Eigener Name");
    });
    expect(screen.getByText(/eigener Text hinterlegt/i)).toBeTruthy();
  });

  it("keeps the two language trees apart", async () => {
    guides = [{ tool_id: "qr", lang: "de", name: "Deutscher Name" }];
    const u = userEvent.setup();
    render(<ToolGuides />);
    await u.selectOptions(await screen.findByLabelText("Tool"), "qr");
    await waitFor(() => {
      expect((screen.getByLabelText(/^Name$/) as HTMLInputElement).value).toBe("Deutscher Name");
    });

    await u.selectOptions(screen.getByLabelText("Sprache"), "en");
    await waitFor(() => {
      expect((screen.getByLabelText(/^Name$/) as HTMLInputElement).value).toBe("");
    });
  });

  it("posts the API's field names, not the form's", async () => {
    // The form keeps two neutral fields per row so one set of list controls can
    // serve steps, use cases and FAQ. That convenience must not leak into the
    // payload — the site reads `{title, description}` and `{q, a}`.
    const u = userEvent.setup();
    render(<ToolGuides />);
    await u.selectOptions(await screen.findByLabelText("Tool"), "qr");

    await u.click(screen.getByRole("button", { name: "Schritt hinzufügen" }));
    await u.type(screen.getByLabelText("Titel 1"), "Datei wählen");
    await u.type(screen.getByLabelText("Beschreibung 1"), "Ziehen Sie die Datei ins Feld.");
    await u.click(screen.getByRole("button", { name: "Speichern" }));

    await waitFor(() => {
      expect(lastPut().steps).toEqual([
        { title: "Datei wählen", description: "Ziehen Sie die Datei ins Feld." },
      ]);
    });
  });

  it("drops blank rows instead of storing them", async () => {
    // An empty row is what an "add" click leaves behind when somebody changes
    // their mind. Stored, it would render as a blank step on the public page
    // and as an empty HowTo entry in the structured data.
    const u = userEvent.setup();
    render(<ToolGuides />);
    await u.selectOptions(await screen.findByLabelText("Tool"), "qr");

    await u.click(screen.getByRole("button", { name: "Absatz hinzufügen" }));
    await u.click(screen.getByRole("button", { name: "Speichern" }));

    await waitFor(() => {
      expect(lastPut().intro).toEqual([]);
    });
  });

  it("hides the reset when there is nothing to reset", async () => {
    // Offering it against the committed text would promise an action that does
    // nothing — and invite the reading that the shipped copy can be deleted.
    const u = userEvent.setup();
    render(<ToolGuides />);
    await u.selectOptions(await screen.findByLabelText("Tool"), "qr");
    expect(screen.queryByRole("button", { name: /zurücksetzen/i })).toBeNull();
  });

  it("offers the reset for a tool that has an override", async () => {
    guides = [{ tool_id: "pdf", lang: "de", name: "X" }];
    const u = userEvent.setup();
    render(<ToolGuides />);
    await u.selectOptions(await screen.findByLabelText("Tool"), "pdf");

    await waitFor(() => {
      expect(screen.getByRole("button", { name: /zurücksetzen/i })).toBeTruthy();
    });
  });

  it("counts the SEO fields, whose only failure mode is invisible", async () => {
    const u = userEvent.setup();
    render(<ToolGuides />);
    await u.selectOptions(await screen.findByLabelText("Tool"), "qr");

    await u.type(screen.getByLabelText(/SEO-Beschreibung/), "Kurz.");
    expect(screen.getByText(/SEO-Beschreibung \(5, Ziel 80–160\)/)).toBeTruthy();
  });

  describe("when the API is unreachable", () => {
    // fetch rejects offline; unhandled, loading stayed on its spinner and
    // saving or resetting ended without a word.
    let messages: string[] = [];
    const collect = (e: Event) => messages.push((e as CustomEvent<{ message: string }>).detail.message);

    /** Keep the stub's answers, except that `method` never reaches the API. */
    const failing = (method: string) => {
      const base = mockFetch();
      vi.stubGlobal("fetch", vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        if (init?.method === method) throw new TypeError("Failed to fetch");
        return base(input, init);
      }));
    };

    beforeEach(() => {
      messages = [];
      window.addEventListener(TOAST_EVENT, collect);
    });

    afterEach(() => window.removeEventListener(TOAST_EVENT, collect));

    it("says so instead of loading forever", async () => {
      vi.stubGlobal("fetch", vi.fn(async () => { throw new TypeError("Failed to fetch"); }));
      render(<ToolGuides />);
      expect(await screen.findByText("Tools konnten nicht geladen werden — die API ist nicht erreichbar.")).toBeTruthy();
    });

    it("keeps the typed text when the save fails", async () => {
      failing("PUT");
      const u = userEvent.setup();
      render(<ToolGuides />);
      await u.selectOptions(await screen.findByLabelText("Tool"), "qr");
      await u.type(screen.getByLabelText(/^Name$/), "Eigener Name");
      await u.click(screen.getByRole("button", { name: "Speichern" }));
      await waitFor(() => expect(messages.some((m) => m.includes("nicht erreichbar"))).toBe(true));
      expect((screen.getByLabelText(/^Name$/) as HTMLInputElement).value).toBe("Eigener Name");
    });

    it("says so when the reset fails", async () => {
      guides = [{ tool_id: "pdf", lang: "de", name: "X" }];
      failing("DELETE");
      const u = userEvent.setup();
      render(<ToolGuides />);
      await u.selectOptions(await screen.findByLabelText("Tool"), "pdf");
      await u.click(await screen.findByRole("button", { name: /zurücksetzen/i }));
      await waitFor(() => expect(messages.some((m) => m.includes("Zurücksetzen") && m.includes("nicht erreichbar"))).toBe(true));
    });
  });
});
