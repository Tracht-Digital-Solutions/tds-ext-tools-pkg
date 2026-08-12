
/**
 * Path + query of a request. The island calls an ABSOLUTE URL now (via
 * `apiFetch`); a relative one would hit the product's own static host and come
 * back as SPA-fallback HTML with a 200. Matching on the path keeps the route
 * matchers below anchored.
 */
const pathOf = (url: string) => String(url).replace(/^https?:\/\/[^/]+/i, "");
// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, render, screen } from "@testing-library/react";
import WidgetBody from "./WidgetBody";

/**
 * The dashboard widget: enabled/total tools, the premium count, AdSense on/off.
 *
 * The failed/loaded distinction is the point: rendering `0 / 0` on a failed
 * request says every tool is hidden — a different and wrong claim from `—`.
 */

let reply: { status: number; body: unknown } | "reject" = {
  status: 200,
  body: { total: 0, enabled: 0, premium: 0, ads: false },
};

beforeEach(() => {
  reply = { status: 200, body: { total: 0, enabled: 0, premium: 0, ads: false } };
  vi.stubGlobal(
    "fetch",
    vi.fn(async () => {
      if (reply === "reject") throw new TypeError("offline");
      return {
        ok: reply.status < 300,
        status: reply.status,
        json: async () => (reply === "reject" ? {} : reply.body),
      } as Response;
    }),
  );
});

afterEach(() => cleanup());

describe("the widget", () => {
  it("fetches its summary endpoint with credentials", () => {
    render(<WidgetBody />);
    const fetchMock = fetch as unknown as ReturnType<typeof vi.fn>;
    expect(pathOf(fetchMock.mock.calls[0]![0] as string)).toBe("/tools/summary");
    // Absolute, on the API host. Every other assertion here matches the PATH,
    // which a relative fetch satisfies too — so this is the one that fails if
    // the call ever goes back to the product's own origin (whose SPA fallback
    // answers 200 + HTML and turns into a silent empty state).
    expect(String(fetchMock.mock.calls[0]![0]).startsWith("https://api.tracht-digital.de/")).toBe(true);
    expect(fetchMock.mock.calls[0]![1]).toMatchObject({ credentials: "include" });
  });

  it("shows a loading line before the request resolves", () => {
    render(<WidgetBody />);
    expect(screen.getByLabelText("Wird geladen")).toBeTruthy();
  });

  it("renders the visible count against the total", async () => {
    reply = { status: 200, body: { total: 9, enabled: 4, premium: 2, ads: true } };
    render(<WidgetBody />);
    expect(await screen.findByText("4")).toBeTruthy();
    expect(screen.getByText("/ 9")).toBeTruthy();
  });

  it("does not confuse the enabled count with the total", async () => {
    // Swapping them would report 9 of 4 tools visible.
    reply = { status: 200, body: { total: 9, enabled: 4, premium: 2, ads: true } };
    render(<WidgetBody />);
    const metric = await screen.findByText("4");
    expect(metric.textContent).toBe("4 / 9");
  });

  it("reports AdSense as on", async () => {
    reply = { status: 200, body: { total: 9, enabled: 4, premium: 2, ads: true } };
    render(<WidgetBody />);
    expect(await screen.findByText("2 Premium · AdSense an")).toBeTruthy();
  });

  it("reports AdSense as off", async () => {
    reply = { status: 200, body: { total: 9, enabled: 4, premium: 2, ads: false } };
    render(<WidgetBody />);
    expect(await screen.findByText("2 Premium · AdSense aus")).toBeTruthy();
  });

  it("renders a real zero when nothing is published", async () => {
    render(<WidgetBody />);
    expect(await screen.findByText("0 Premium · AdSense aus")).toBeTruthy();
  });

  it("shows a dash on an error rather than claiming everything is hidden", async () => {
    reply = { status: 500, body: {} };
    render(<WidgetBody />);
    expect(await screen.findByText("—")).toBeTruthy();
  });

  it("shows a dash when the request rejects", async () => {
    reply = "reject";
    render(<WidgetBody />);
    expect(await screen.findByText("—")).toBeTruthy();
  });

  it("does not render counts carried by a NON-OK response", async () => {
    reply = { status: 403, body: { total: 99, enabled: 99, premium: 99, ads: true } };
    render(<WidgetBody />);
    expect(await screen.findByText("—")).toBeTruthy();
    expect(screen.queryByText(/99/)).toBeNull();
  });
});
