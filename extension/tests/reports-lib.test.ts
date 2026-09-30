import { beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("../src/lib/auth/session", () => ({
  getBackendSession: vi.fn(async () => ({ sessionToken: "session-1", expiresAt: "2030-01-01T00:00:00Z", tenantId: "1" })),
  clearBackendSession: vi.fn(),
}));

import { createApiClient } from "../src/lib/api/client";
import { reportQuery } from "../src/lib/api/reports";
import { periodPresets } from "../src/pages/reports/periods";

describe("reportQuery", () => {
  it("só envia os filtros preenchidos", () => {
    const query = new URLSearchParams(
      reportQuery({ from: "2026-09-01", to: "2026-09-30", projectId: "3", memberId: "", billable: "false" }),
    );

    expect(Object.fromEntries(query)).toEqual({ from: "2026-09-01", to: "2026-09-30", projectId: "3", billable: "false" });
  });

  it("acrescenta parâmetros extras (paginação)", () => {
    const query = new URLSearchParams(reportQuery({ from: "2026-09-01", to: "2026-09-30" }, { page: 2, perPage: 50 }));

    expect(query.get("page")).toBe("2");
    expect(query.get("perPage")).toBe("50");
  });
});

describe("periodPresets", () => {
  const byLabel = (today: string) => Object.fromEntries(periodPresets(today).map((p) => [p.label, [p.from, p.to]]));

  it("calcula os períodos prontos a partir de hoje", () => {
    expect(byLabel("2026-10-05")).toEqual({
      "Esta semana": ["2026-10-05", "2026-10-11"],
      "Semana passada": ["2026-09-28", "2026-10-04"],
      "Este mês": ["2026-10-01", "2026-10-31"],
      "Mês passado": ["2026-09-01", "2026-09-30"],
      "Últimos 30 dias": ["2026-09-06", "2026-10-05"],
    });
  });

  it("trata fevereiro bissexto e virada de ano", () => {
    expect(byLabel("2028-03-10")["Mês passado"]).toEqual(["2028-02-01", "2028-02-29"]);
    expect(byLabel("2027-01-15")["Mês passado"]).toEqual(["2026-12-01", "2026-12-31"]);
  });
});

describe("client.download", () => {
  const client = createApiClient({ apiBaseUrl: "https://api.example.test" });

  beforeEach(() => {
    vi.restoreAllMocks();
  });

  it("devolve o arquivo com a sessão do backend", async () => {
    const fetchMock = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => new Response("a;b\r\n", { status: 200 }));
    vi.stubGlobal("fetch", fetchMock);

    const blob = await client.download("/api/reports/time.csv?from=2026-09-01&to=2026-09-30");

    // jsdom não implementa Blob.text(), então confere o tamanho em bytes ("a;b\r\n" = 5).
    expect(blob.size).toBe(5);
    expect(fetchMock.mock.calls[0][0]).toBe("https://api.example.test/api/reports/time.csv?from=2026-09-01&to=2026-09-30");
    expect((fetchMock.mock.calls[0][1]?.headers as Record<string, string>).Authorization).toBe("Bearer session-1");
  });

  it("mostra o motivo quando o servidor recusa a exportação", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) =>
        new Response(JSON.stringify({ message: "Você não tem permissão para esta ação." }), { status: 403 }),
      ),
    );

    await expect(client.download("/api/reports/time.csv")).rejects.toThrow("Você não tem permissão para esta ação.");
  });
});
