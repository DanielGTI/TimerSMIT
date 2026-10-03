import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("../src/lib/api/config", () => ({
  getApiBaseUrl: () => "https://api.example.test",
}));

vi.mock("../src/lib/devops/directory", () => ({
  fetchActiveDirectoryPeople: vi.fn(async () => []),
}));

const saveBlob = vi.fn();
vi.mock("../src/lib/download", () => ({
  saveBlob: (...args: unknown[]) => saveBlob(...args),
}));

const fetchProjectHours = vi.fn();
const downloadProjectHoursXlsx = vi.fn();
vi.mock("../src/lib/api/projectHours", () => ({
  fetchProjectHours: (...args: unknown[]) => fetchProjectHours(...args),
  downloadProjectHoursXlsx: (...args: unknown[]) => downloadProjectHoursXlsx(...args),
}));

const fetchSettings = vi.fn();
const setProjectCountsAsIdle = vi.fn();
vi.mock("../src/lib/api/settings", () => ({
  fetchSettings: (...args: unknown[]) => fetchSettings(...args),
  setProjectEnabled: vi.fn(),
  setProjectCountsAsIdle: (...args: unknown[]) => setProjectCountsAsIdle(...args),
}));

import type { ProjectHoursDto } from "../src/lib/api/projectHours";
import { SettingsPage } from "../src/pages/settings/SettingsPage";
import { clock } from "../src/pages/settings/ProjectHoursSection";

const H = 3600;
const hm = (hours: number, minutes: number) => (hours * 60 + minutes) * 60;

function report(overrides: Partial<ProjectHoursDto> = {}): ProjectHoursDto {
  return {
    month: "2026-07",
    from: "2026-07-01",
    to: "2026-07-31",
    dailyHours: 8,
    approvedOnly: false,
    weekdays: 23,
    holidays: [{ date: "2026-07-09", name: "Revolução Constitucionalista" }],
    calendarBaseSeconds: 176 * H,
    idleProjects: ["Laravel-Inspinia"],
    people: [
      { memberId: "1", name: "Gustavo Henrique", baseSeconds: 168 * H, timeOff: [], totalSeconds: hm(163, 6), idleProjectSeconds: 0, idleSeconds: hm(4, 54) },
      { memberId: "2", name: "Willian de Sena", baseSeconds: 168 * H, timeOff: [], totalSeconds: hm(61, 30), idleProjectSeconds: 0, idleSeconds: hm(106, 30) },
    ],
    projects: [
      { projectId: "10", name: "Laravel-Inspinia", countsAsIdle: true, seconds: { "1": 0, "2": 0 }, totalSeconds: 0 },
      { projectId: "11", name: "SARC", countsAsIdle: false, seconds: { "1": hm(163, 6), "2": hm(61, 30) }, totalSeconds: hm(224, 36) },
    ],
    totals: { totalSeconds: hm(224, 36), idleSeconds: hm(111, 24) },
    note: "Base de jornada: 168h (23 dias úteis; 09/07 feriado; 10/07 banco de horas). Horas Ociosas incluem Laravel-Inspinia.",
    ...overrides,
  };
}

const settings = {
  organization: { name: "smitbr", timezone: "America/Sao_Paulo" },
  policy: { durationIncrementMinutes: 1, dailyLimitHours: 24, retroactiveWindowDays: 30, commentRequired: false, version: 0, effectiveFrom: null },
  overtime: { enabled: true, workdayStart: "09:00", workdayEnd: "18:00", version: 1 },
  holidays: [],
  projects: [{ id: "10", name: "Laravel-Inspinia", enabled: true, countsAsIdle: false }],
  activityTypes: [],
  members: [],
  peopleSyncedAt: null,
  designations: [],
};

describe("Configuração: horas por projeto", () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date(2026, 7, 3, 12, 0, 0));
    saveBlob.mockReset();
    fetchProjectHours.mockReset().mockResolvedValue(report());
    downloadProjectHoursXlsx.mockReset().mockResolvedValue(new Blob(["x"]));
    fetchSettings.mockReset().mockResolvedValue(settings);
    setProjectCountsAsIdle.mockReset().mockResolvedValue({ ...settings, projects: [{ ...settings.projects[0], countsAsIdle: true }] });
  });
  afterEach(() => {
    cleanup();
    vi.useRealTimers();
  });

  async function openTab() {
    render(<SettingsPage />);
    fireEvent.click(await screen.findByRole("tab", { name: "Horas por projeto" }));
    return screen.findByRole("region", { name: "Horas por projeto" });
  }

  it("formata as horas como no Excel", () => {
    expect(clock(hm(163, 6))).toBe("163:06:00");
    expect(clock(0)).toBe("0:00:00");
    expect(clock(1999)).toBe("0:33:19");
  });

  it("abre no mês anterior com projetos nas linhas, pessoas nas colunas, ociosas e total", async () => {
    const card = await openTab();
    await waitFor(() => expect(fetchProjectHours).toHaveBeenCalledWith(expect.anything(), { month: "2026-07", dailyHours: 8, approvedOnly: false }));

    const table = await within(card).findByRole("table");
    expect(within(card).getByText(/Base de jornada: 168h \(23 dias úteis; 09\/07 feriado/)).toBeInTheDocument();
    expect(within(table).getAllByRole("columnheader").map((cell) => cell.textContent)).toEqual([
      "Projetos",
      "Gustavo Henrique",
      "Willian de Sena",
      "TOTAL",
    ]);

    const rows = within(table).getAllByRole("row");
    expect(rows[1]).toHaveTextContent("Laravel-Inspinia0:00:00");
    expect(rows[2]).toHaveTextContent("SARC163:06:0061:30:00224:36:00");
    expect(rows[3]).toHaveTextContent("Horas Ociosas4:54:00106:30:00111:24:00");
    expect(rows[4]).toHaveTextContent("TOTAL163:06:0061:30:00224:36:00");
  });

  it("muda os filtros e exporta o Excel", async () => {
    const card = await openTab();
    await within(card).findByRole("table");

    fireEvent.change(within(card).getByLabelText("Jornada diária (horas)"), { target: { value: "6" } });
    fireEvent.click(within(card).getByLabelText("Só semanas aprovadas"));
    await waitFor(() =>
      expect(fetchProjectHours).toHaveBeenLastCalledWith(expect.anything(), { month: "2026-07", dailyHours: 6, approvedOnly: true }),
    );

    await within(card).findByRole("table");
    fireEvent.click(within(card).getByRole("button", { name: "Exportar Excel" }));
    await waitFor(() => expect(saveBlob).toHaveBeenCalledWith(expect.any(Blob), "horas_por_projeto_2026-07.xlsx"));
    expect(downloadProjectHoursXlsx).toHaveBeenCalledWith(expect.anything(), { month: "2026-07", dailyHours: 6, approvedOnly: true });
  });

  it("mês sem lançamentos", async () => {
    fetchProjectHours.mockResolvedValue(report({ people: [], projects: [] }));
    const card = await openTab();
    expect(await within(card).findByText(/Nenhum lançamento em/)).toBeInTheDocument();
    expect(within(card).getByRole("button", { name: "Exportar Excel" })).toBeEnabled();
  });

  it("em Projetos, marca um projeto como hora ociosa", async () => {
    render(<SettingsPage />);
    fireEvent.click(await screen.findByRole("tab", { name: "Projetos" }));
    fireEvent.click(await screen.findByRole("switch", { name: "Laravel-Inspinia conta como hora ociosa" }));

    await waitFor(() => expect(setProjectCountsAsIdle).toHaveBeenCalledWith(expect.anything(), "10", true));
    expect(await screen.findByText("As horas de Laravel-Inspinia passam a contar como ociosas.")).toBeInTheDocument();
  });
});
