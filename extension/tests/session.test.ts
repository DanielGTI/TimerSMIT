import { beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("../src/lib/devops/sdk", () => ({
  getAppToken: vi.fn(async () => "fake-app-token"),
  getHostContext: vi.fn(async () => ({ id: "org-guid-123", name: "contoso" })),
  getUserContext: vi.fn(async () => ({ displayName: "Ada Lovelace", name: "ada@contoso.com" })),
}));

import { clearBackendSession, getBackendSession } from "../src/lib/auth/session";

describe("getBackendSession", () => {
  beforeEach(() => {
    clearBackendSession();
    vi.restoreAllMocks();
  });

  it("troca o token de app do host por uma sessão do backend", async () => {
    const fetchMock = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) =>
      new Response(
        JSON.stringify({ sessionToken: "session-123", expiresAt: "2030-01-01T00:00:00Z", tenantId: "1" }),
        { status: 200 },
      ),
    );
    vi.stubGlobal("fetch", fetchMock);

    const session = await getBackendSession("https://api.example.test");

    expect(fetchMock).toHaveBeenCalledWith(
      "https://api.example.test/api/auth/session",
      expect.objectContaining({ method: "POST" }),
    );
    const [, init] = fetchMock.mock.calls[0];
    const body = JSON.parse((init as RequestInit).body as string);
    expect(body).toEqual({
      appToken: "fake-app-token",
      claimedOrganizationId: "org-guid-123",
      claimedOrganizationName: "contoso",
      displayName: "Ada Lovelace",
    });
    expect(session.sessionToken).toBe("session-123");
  });

  it("reaproveita a sessão em cache enquanto não estiver perto de expirar", async () => {
    const farFuture = new Date(Date.now() + 60_000).toISOString();
    const fetchMock = vi.fn(async () =>
      new Response(JSON.stringify({ sessionToken: "session-abc", expiresAt: farFuture, tenantId: "1" }), {
        status: 200,
      }),
    );
    vi.stubGlobal("fetch", fetchMock);

    await getBackendSession("https://api.example.test");
    await getBackendSession("https://api.example.test");

    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it("propaga erro quando o backend rejeita a troca de sessão", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => new Response(null, { status: 422 })),
    );

    await expect(getBackendSession("https://api.example.test")).rejects.toThrow(/HTTP 422/);
  });
});
