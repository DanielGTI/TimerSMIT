import { beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("../src/lib/devops/sdk", () => ({
  getHostContext: async () => ({ name: "smitbr" }),
  getAccessToken: async () => "token-do-admin",
}));

import { fetchActiveDirectoryPeople, selectActivePeople } from "../src/lib/devops/directory";

const entitlement = (id: string, name: string, status = "active", license = "express", kind = "user", msdn?: string) => ({
  id,
  user: { displayName: name, subjectKind: kind },
  accessLevel: { status, accountLicenseType: license, ...(msdn ? { msdnLicenseType: msdn, licensingSource: "msdn" } : {}) },
});

const json = (body: unknown, status = 200) => ({ ok: status < 400, status, json: async () => body }) as Response;

describe("selectActivePeople", () => {
  it("mantém quem tem conta ativa (inclusive assinatura do Visual Studio) e tira o resto", () => {
    const people = selectActivePeople([
      entitlement("1", "Ana"),
      entitlement("2", "Beto", "disabled"),
      // Assinatura do Visual Studio: a licença da conta vem como "none".
      entitlement("3", "Caio", "active", "none", "user", "professional"),
      entitlement("4", "Robô", "active", "express", "servicePrincipal"),
      entitlement("5", "Duda", "pending"),
      entitlement("6", "Eva", "active", "stakeholder"),
      entitlement("8", "Fábio", "active", "advanced"),
      entitlement("9", "Gil", "none", "none"),
      { id: "7", user: { displayName: "Sem nível" } },
    ]);

    expect(people.map((person) => person.displayName)).toEqual(["Ana", "Caio", "Eva", "Fábio"]);
  });

  it("usa e-mail ou o id quando não há nome de exibição", () => {
    expect(
      selectActivePeople([
        { id: "9", user: { mailAddress: "x@smit.net.br" }, accessLevel: { status: "active", accountLicenseType: "express" } },
        { id: "10", accessLevel: { status: "active", accountLicenseType: "express" } },
      ]).map((person) => person.displayName),
    ).toEqual(["x@smit.net.br", "10"]);
  });
});

describe("fetchActiveDirectoryPeople", () => {
  const fetchMock = vi.fn();
  const run = () => fetchActiveDirectoryPeople(fetchMock as unknown as typeof fetch);

  beforeEach(() => fetchMock.mockReset());

  it("consulta a organização atual com o token do usuário e junta todas as páginas", async () => {
    fetchMock
      .mockResolvedValueOnce(json({ members: [entitlement("1", "Ana"), entitlement("2", "Beto", "disabled")], continuationToken: ["pagina-2"] }))
      .mockResolvedValueOnce(json({ members: [entitlement("3", "Caio")], continuationToken: null }));

    const people = await run();

    expect(people.map((person) => person.displayName)).toEqual(["Ana", "Caio"]);
    expect(fetchMock).toHaveBeenCalledTimes(2);
    const [firstUrl, firstInit] = fetchMock.mock.calls[0];
    expect(firstUrl).toContain("https://vsaex.dev.azure.com/smitbr/_apis/userentitlements?");
    expect(firstInit.headers.Authorization).toBe("Bearer token-do-admin");
    expect(fetchMock.mock.calls[1][0]).toContain("continuationToken=pagina-2");
  });

  it("aceita a lista com outro nome de campo e para se o servidor repetir a página", async () => {
    fetchMock.mockResolvedValue(json({ items: [entitlement("1", "Ana")], continuationToken: "sempre-igual" }));

    const people = await run();

    expect(people).toHaveLength(1);
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it("explica a recusa de permissão e os demais erros", async () => {
    fetchMock.mockResolvedValueOnce(json({}, 403));
    await expect(run()).rejects.toThrow(/não permitiu ler os usuários/);

    fetchMock.mockResolvedValueOnce(json({}, 500));
    await expect(run()).rejects.toThrow(/erro 500/);
  });
});
