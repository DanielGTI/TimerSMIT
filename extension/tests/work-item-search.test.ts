import { beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("../src/lib/devops/sdk", () => ({
  getHostContext: async () => ({ name: "smitbr" }),
  getAccessToken: async () => "token-do-usuario",
  getWebContext: async () => ({ project: { id: "guid-projeto-aberto", name: "SMIT LEARN IA" } }),
}));

import { buildSearchWiql, fetchWorkItemDetails, searchWorkItems } from "../src/lib/devops/workItemSearch";

const json = (body: unknown, status = 200) => ({ ok: status < 400, status, json: async () => body }) as Response;
const GUID = "6ce954b1-ce1f-45d1-b94d-e6bf2464ba2c";

const item = (id: number, title: string, extra: Record<string, unknown> = {}) => ({
  id,
  fields: { "System.Title": title, "System.WorkItemType": "Task", "System.TeamProject": "McCain", "System.State": "Active", ...extra },
});

describe("buildSearchWiql", () => {
  it("número busca o ID exato e todo ID que começa com ele, além do título", () => {
    const wiql = buildSearchWiql("15")!;

    expect(wiql).toContain("[System.Id] = 15");
    expect(wiql).toContain("([System.Id] >= 150 AND [System.Id] <= 159)");
    expect(wiql).toContain("([System.Id] >= 15000 AND [System.Id] <= 15999)");
    expect(wiql).toContain("([System.Id] >= 1500000 AND [System.Id] <= 1599999)");
    expect(wiql).not.toContain("15000000");
    expect(wiql).toContain("[System.Title] CONTAINS '15'");
    expect(wiql).toContain("[System.State] <> 'Removed'");
    expect(wiql).toContain("ORDER BY [System.ChangedDate] DESC");
  });

  it("aceita # na frente do número", () => {
    expect(buildSearchWiql("#15596")).toContain("[System.Id] = 15596");
  });

  it("texto busca no título, com aspas escapadas", () => {
    const wiql = buildSearchWiql("d'água")!;

    expect(wiql).toContain("[System.Title] CONTAINS 'd''água'");
    expect(wiql).not.toContain("[System.Id] =");
  });

  it("sem texto, lista o que está atribuído a mim; uma letra só não busca", () => {
    expect(buildSearchWiql("  ")).toContain("[System.AssignedTo] = @Me");
    expect(buildSearchWiql("a")).toBeNull();
  });
});

describe("searchWorkItems", () => {
  const fetchMock = vi.fn();
  beforeEach(() => fetchMock.mockReset());

  it("busca na organização com o token do usuário e põe o ID exato primeiro", async () => {
    fetchMock
      .mockResolvedValueOnce(json({ workItems: [{ id: 15796 }, { id: 15 }, { id: 1500 }] }))
      .mockResolvedValueOnce(json({ value: [item(15796, "Reunião"), item(15, "Antigo"), null] }));

    const hits = await searchWorkItems("15", fetchMock as unknown as typeof fetch);

    expect(hits.map((hit) => hit.id)).toEqual([15, 15796]);
    expect(hits[1]).toEqual({ id: 15796, title: "Reunião", workItemType: "Task", projectName: "McCain", state: "Active" });

    const [wiqlUrl, wiqlInit] = fetchMock.mock.calls[0];
    expect(wiqlUrl).toBe("https://dev.azure.com/smitbr/_apis/wit/wiql?$top=50&api-version=7.1");
    expect(wiqlInit.method).toBe("POST");
    expect(wiqlInit.headers.Authorization).toBe("Bearer token-do-usuario");
    expect(JSON.parse(wiqlInit.body).query).toContain("[System.Id] = 15");

    const batchUrl = new URL(fetchMock.mock.calls[1][0]);
    expect(batchUrl.searchParams.get("ids")).toBe("15796,15,1500");
    expect(batchUrl.searchParams.get("errorPolicy")).toBe("omit");
  });

  it("sem resultado não pede os campos", async () => {
    fetchMock.mockResolvedValueOnce(json({ workItems: [] }));

    expect(await searchWorkItems("xyz", fetchMock as unknown as typeof fetch)).toEqual([]);
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it("explica a recusa de permissão", async () => {
    fetchMock.mockResolvedValueOnce(json({}, 401));

    await expect(searchWorkItems("15", fetchMock as unknown as typeof fetch)).rejects.toThrow(/não permitiu buscar/);
  });
});

describe("fetchWorkItemDetails", () => {
  const fetchMock = vi.fn();
  beforeEach(() => fetchMock.mockReset());

  it("tira o GUID do projeto dos links e lê o pai", async () => {
    fetchMock
      .mockResolvedValueOnce(
        json({
          ...item(15596, "(Reunião)(Alinhamento) Com o cliente", { "System.Parent": 15550, "System.IterationPath": "McCain\\Sprint 8" }),
          url: "https://dev.azure.com/smitbr/_apis/wit/workItems/15596",
          _links: { workItemType: { href: `https://dev.azure.com/smitbr/${GUID}/_apis/wit/workItemTypes/Task` } },
        }),
      )
      .mockResolvedValueOnce(json({ value: [item(15550, "08 Agosto 2026 Suporte ao Cliente", { "System.WorkItemType": "User Story" })] }));

    const details = await fetchWorkItemDetails(15596, fetchMock as unknown as typeof fetch);

    expect(details).toMatchObject({
      id: 15596,
      projectId: GUID,
      projectName: "McCain",
      iterationPath: "McCain\\Sprint 8",
      parent: { id: 15550, title: "08 Agosto 2026 Suporte ao Cliente", workItemType: "User Story" },
      webUrl: "https://dev.azure.com/smitbr/McCain/_workitems/edit/15596",
    });
    expect(fetchMock.mock.calls[0][0]).toContain("/_apis/wit/workitems/15596?$expand=all");
  });

  it("sem GUID nos links, usa o projeto aberto se o nome bate; senão, fica sem projeto", async () => {
    fetchMock.mockResolvedValueOnce(json(item(1, "A", { "System.TeamProject": "SMIT LEARN IA" })));
    expect((await fetchWorkItemDetails(1, fetchMock as unknown as typeof fetch)).projectId).toBe("guid-projeto-aberto");

    fetchMock.mockResolvedValueOnce(json(item(2, "B")));
    expect((await fetchWorkItemDetails(2, fetchMock as unknown as typeof fetch)).projectId).toBeNull();
  });

  it("pai inacessível só não aparece", async () => {
    fetchMock
      .mockResolvedValueOnce(json({ ...item(3, "C", { "System.Parent": 9 }), _links: { fields: { href: `https://x/${GUID}/_apis/wit/fields` } } }))
      .mockResolvedValueOnce(json({}, 403));

    const details = await fetchWorkItemDetails(3, fetchMock as unknown as typeof fetch);

    expect(details.parent).toBeNull();
    expect(details.projectId).toBe(GUID);
  });
});
