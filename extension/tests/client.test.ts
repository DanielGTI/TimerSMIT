import { beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("../src/lib/auth/session", () => ({
  getBackendSession: vi.fn(async () => ({ sessionToken: "session-1", expiresAt: "2030-01-01T00:00:00Z", tenantId: "1" })),
  clearBackendSession: vi.fn(),
}));

import { ApiError, createApiClient } from "../src/lib/api/client";

function respondWith(response: Response) {
  vi.stubGlobal(
    "fetch",
    vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => response),
  );
}

describe("createApiClient", () => {
  const client = createApiClient({ apiBaseUrl: "https://api.example.test" });

  beforeEach(() => {
    vi.restoreAllMocks();
  });

  it("devolve a primeira mensagem de validação em 422", async () => {
    respondWith(
      new Response(JSON.stringify({ message: "Dados inválidos.", errors: { durationSeconds: ["Limite diário excedido."] } }), {
        status: 422,
      }),
    );

    await expect(client.request("/api/entries")).rejects.toMatchObject({
      name: "ApiError",
      status: 422,
      message: "Limite diário excedido.",
    });
  });

  it("devolve a mensagem do backend em 409", async () => {
    respondWith(new Response(JSON.stringify({ message: "Já existe timer ativo para outro item." }), { status: 409 }));

    await expect(client.request("/api/me/timer")).rejects.toThrow("Já existe timer ativo para outro item.");
  });

  it("mantém mensagem genérica em 5xx, sem expor detalhe do servidor", async () => {
    respondWith(new Response(JSON.stringify({ message: "SQLSTATE[42P01]: tabela ausente" }), { status: 500 }));

    const failure = await client.request("/api/me/timer").catch((error: unknown) => error);

    expect(failure).toBeInstanceOf(ApiError);
    expect((failure as ApiError).message).toBe("Falha na chamada à API (/api/me/timer): HTTP 500");
  });

  it("cai na mensagem genérica quando o corpo de erro não é JSON", async () => {
    respondWith(new Response("<html>bad gateway</html>", { status: 403 }));

    await expect(client.request("/api/entries")).rejects.toThrow("HTTP 403");
  });

  it("aceita resposta sem corpo (204)", async () => {
    respondWith(new Response(null, { status: 204 }));

    await expect(client.request("/api/entries/1", { method: "DELETE" })).resolves.toBeUndefined();
  });

  it("devolve null quando o backend responde o literal null", async () => {
    respondWith(new Response("null", { status: 200 }));

    await expect(client.request("/api/me/timer")).resolves.toBeNull();
  });
});
