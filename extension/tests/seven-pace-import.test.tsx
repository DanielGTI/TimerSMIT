import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("../src/lib/api/config", () => ({
  getApiBaseUrl: () => "https://api.example.test",
}));

vi.mock("../src/lib/devops/sdk", () => ({
  getHostContext: async () => ({ name: "smitbr" }),
  getAccessToken: async () => "token",
  getWebContext: async () => ({ project: { id: "p", name: "P" } }),
}));

vi.mock("../src/lib/devops/directory", () => ({
  fetchActiveDirectoryPeople: vi.fn(async () => []),
}));

const importSevenPace = vi.fn();
vi.mock("../src/lib/api/sevenPaceImport", () => ({
  importSevenPace: (...args: unknown[]) => importSevenPace(...args),
  fileToBase64: async () => "QkFTRTY0",
}));

const resolveProjectIds = vi.fn();
vi.mock("../src/lib/devops/workItemSearch", async (importOriginal) => ({
  ...(await importOriginal<typeof import("../src/lib/devops/workItemSearch")>()),
  resolveProjectIds: (...args: unknown[]) => resolveProjectIds(...args),
}));

const fetchSettings = vi.fn();
vi.mock("../src/lib/api/settings", () => ({
  fetchSettings: (...args: unknown[]) => fetchSettings(...args),
}));

import type { ImportPreviewDto } from "../src/lib/api/sevenPaceImport";
import { SettingsPage } from "../src/pages/settings/SettingsPage";

const H = 3600;
const GUID = "0f8fad5b-d9cb-469f-a165-70867728950e";

function preview(overrides: Partial<ImportPreviewDto> = {}): ImportPreviewDto {
  return {
    dryRun: true,
    rows: 3,
    totalSeconds: 5 * H,
    from: "2026-09-01",
    to: "2026-09-30",
    people: [
      { name: "Jhones", rows: 2, seconds: 4 * H, memberId: "11", memberName: "Jhones Michael Santana Vieira", match: "prefix" },
      { name: "Fulano", rows: 1, seconds: H, memberId: null, memberName: null, match: null },
    ],
    projects: [
      { name: "Reuniões SMIT", rows: 1, seconds: H, sampleWorkItemId: 15703, projectId: "1", devopsProjectId: "x", status: "existing" },
      { name: "SARC", rows: 2, seconds: 4 * H, sampleWorkItemId: 15800, projectId: null, devopsProjectId: null, status: "missing" },
    ],
    activities: [{ name: "Pesquisa Nova", rows: 1, status: "create" }],
    toImport: 1,
    toImportSeconds: H,
    skipped: { unknownPerson: 1, unknownProject: 2, alreadyImported: 0, alreadyLogged: 0, lockedWeek: 0 },
    warnings: [],
    imported: 0,
    ...overrides,
  };
}

const settings = {
  organization: { name: "smitbr", timezone: "America/Sao_Paulo" },
  policy: { durationIncrementMinutes: 1, dailyLimitHours: 24, retroactiveWindowDays: 30, commentRequired: false, version: 0, effectiveFrom: null },
  overtime: { enabled: true, workdayStart: "09:00", workdayEnd: "18:00", version: 1 },
  holidays: [],
  projects: [],
  activityTypes: [],
  members: [
    { id: "10", name: "Fulano de Tal", directoryActive: true, hoursRegime: "clt", roles: [] },
    { id: "11", name: "Jhones Michael Santana Vieira", directoryActive: true, hoursRegime: "clt", roles: [] },
  ],
  peopleSyncedAt: null,
  designations: [],
};

describe("Configuração: importar do 7pace", () => {
  beforeEach(() => {
    fetchSettings.mockReset().mockResolvedValue(settings);
    importSevenPace.mockReset();
    resolveProjectIds.mockReset().mockResolvedValue({ SARC: GUID });
  });
  afterEach(cleanup);

  async function chooseFile() {
    render(<SettingsPage />);
    fireEvent.click(await screen.findByRole("tab", { name: "Importar 7pace" }));
    const card = await screen.findByRole("region", { name: "Importar do 7pace" });
    const file = new File(["x"], "TimerBKP.xlsx");
    fireEvent.change(within(card).getByLabelText("Planilha do 7pace"), { target: { files: [file] } });
    return card;
  }

  it("simula, descobre no Azure DevOps o projeto que falta e mostra o que vai entrar", async () => {
    const resolved = preview({
      projects: [
        preview().projects[0],
        { ...preview().projects[1], status: "create", devopsProjectId: GUID },
      ],
      toImport: 2,
      toImportSeconds: 5 * H,
      skipped: { unknownPerson: 1, unknownProject: 0, alreadyImported: 0, alreadyLogged: 0, lockedWeek: 0 },
    });
    importSevenPace.mockResolvedValueOnce(preview()).mockResolvedValueOnce(resolved);

    const card = await chooseFile();

    await waitFor(() => expect(importSevenPace).toHaveBeenCalledTimes(2));
    expect(importSevenPace.mock.calls[0][1]).toEqual({ file: "QkFTRTY0", personMap: {}, projectIds: {}, dryRun: true });
    expect(resolveProjectIds).toHaveBeenCalledWith([{ name: "SARC", workItemId: 15800 }]);
    expect(importSevenPace.mock.calls[1][1]).toMatchObject({ projectIds: { SARC: GUID }, dryRun: true });

    expect(await within(card).findByText("Será criado")).toBeInTheDocument();
    expect(within(card).getByText(/Vão entrar/)).toHaveTextContent("Vão entrar 2 lançamento(s), 05:00.");
    expect(within(card).getByRole("list", { name: "Linhas que ficam de fora" })).toHaveTextContent("1 de pessoa sem correspondência");
    expect(within(card).getByText("pelo começo do nome")).toBeInTheDocument();
    expect(within(card).getByText(/Atividades novas que serão criadas: Pesquisa Nova/)).toBeInTheDocument();
  });

  it("escolher a pessoa refaz a simulação e importar grava", async () => {
    const all = preview({
      projects: [preview().projects[0]],
      toImport: 3,
      toImportSeconds: 5 * H,
      skipped: { unknownPerson: 0, unknownProject: 0, alreadyImported: 0, alreadyLogged: 0, lockedWeek: 0 },
    });
    importSevenPace
      .mockResolvedValueOnce(preview({ projects: [preview().projects[0]] }))
      .mockResolvedValueOnce(all)
      .mockResolvedValueOnce({ ...all, dryRun: false, imported: 3 })
      .mockResolvedValueOnce({ ...all, toImport: 0, toImportSeconds: 0, skipped: { ...all.skipped, alreadyImported: 3 } });

    const card = await chooseFile();
    const select = await within(card).findByLabelText("Pessoa no TimerSMIT para Fulano");
    fireEvent.change(select, { target: { value: "10" } });

    await waitFor(() => expect(importSevenPace).toHaveBeenCalledTimes(2));
    expect(importSevenPace.mock.calls[1][1]).toMatchObject({ personMap: { Fulano: "10" }, dryRun: true });

    fireEvent.click(await within(card).findByRole("button", { name: "Importar 3 lançamento(s)" }));

    await waitFor(() => expect(importSevenPace).toHaveBeenCalledTimes(4));
    expect(importSevenPace.mock.calls[2][1]).toMatchObject({ personMap: { Fulano: "10" }, dryRun: false });
    expect(await within(card).findByRole("status")).toHaveTextContent("3 lançamento(s) importado(s), 05:00.");
    expect(within(card).getByRole("button", { name: "Nada a importar" })).toBeDisabled();
  });
});

describe("resolveProjectIds", () => {
  it("lê o GUID do projeto no endereço do work item e só aceita o nome que bate", async () => {
    const fetchImpl = vi.fn(async () =>
      new Response(
        JSON.stringify({
          value: [
            { id: 15800, fields: { "System.TeamProject": "SARC" }, url: `https://dev.azure.com/smitbr/${GUID.toUpperCase()}/_apis/wit/workItems/15800` },
            { id: 99, fields: { "System.TeamProject": "Outro" }, url: "https://dev.azure.com/smitbr/11111111-2222-3333-4444-555555555555/_apis/wit/workItems/99" },
            null,
          ],
        }),
        { status: 200 },
      ),
    );

    const { resolveProjectIds: realResolveProjectIds } = await vi.importActual<typeof import("../src/lib/devops/workItemSearch")>(
      "../src/lib/devops/workItemSearch",
    );
    const result = await realResolveProjectIds(
      [
        { name: "SARC", workItemId: 15800 },
        { name: "Biotriagem", workItemId: 99 },
      ],
      fetchImpl as unknown as typeof fetch,
    );

    expect(result).toEqual({ SARC: GUID });
    const url = String((fetchImpl.mock.calls[0] as unknown[])[0]);
    expect(url).toContain("ids=15800%2C99");
    expect(url).toContain("fields=System.TeamProject");
  });
});
