import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("../src/lib/api/config", () => ({
  getApiBaseUrl: () => "https://api.example.test",
}));

vi.mock("../src/lib/devops/directory", () => ({
  fetchActiveDirectoryPeople: vi.fn(async () => []),
}));

const api = {
  fetchSettings: vi.fn(),
  updateOvertimeRules: vi.fn(),
  addHoliday: vi.fn(),
  addNationalHolidays: vi.fn(),
  removeHoliday: vi.fn(),
  setHoursRegime: vi.fn(),
};

vi.mock("../src/lib/api/settings", () => ({
  fetchSettings: (...args: unknown[]) => api.fetchSettings(...args),
  updateOvertimeRules: (...args: unknown[]) => api.updateOvertimeRules(...args),
  addHoliday: (...args: unknown[]) => api.addHoliday(...args),
  addNationalHolidays: (...args: unknown[]) => api.addNationalHolidays(...args),
  removeHoliday: (...args: unknown[]) => api.removeHoliday(...args),
  setHoursRegime: (...args: unknown[]) => api.setHoursRegime(...args),
}));

const fetchAdditionalHours = vi.fn();
const classifyAdditionalHours = vi.fn();

vi.mock("../src/lib/api/additionalHours", async (importOriginal) => ({
  ...(await importOriginal<typeof import("../src/lib/api/additionalHours")>()),
  fetchAdditionalHours: (...args: unknown[]) => fetchAdditionalHours(...args),
  classifyAdditionalHours: (...args: unknown[]) => classifyAdditionalHours(...args),
}));

import type { AdditionalHoursItemDto } from "../src/lib/api/additionalHours";
import type { SettingsDto } from "../src/lib/api/settings";
import { SettingsPage } from "../src/pages/settings/SettingsPage";

function settings(overrides: Partial<SettingsDto> = {}): SettingsDto {
  return {
    organization: { name: "smitbr", timezone: "America/Sao_Paulo" },
    policy: { durationIncrementMinutes: 1, dailyLimitHours: 24, retroactiveWindowDays: 30, commentRequired: false, version: 0, effectiveFrom: null },
    overtime: {
      enabled: true,
      workdayStart: "09:00",
      workdayEnd: "18:00",
      factorWeekday: 1.5,
      factorSaturday: 1.5,
      factorSunday: 2,
      factorHoliday: 2,
      nightStart: "22:00",
      nightEnd: "05:00",
      nightPercent: 20,
      nightReducedHour: true,
      requireTimeOfDay: true,
      bankValidityMonths: 6,
      bankWeighted: true,
      version: 1,
      effectiveFrom: "2026-10-02T12:00:00Z",
    },
    holidays: [{ id: "1", date: "2026-11-20", name: "Consciência Negra" }],
    projects: [],
    activityTypes: [],
    members: [
      { id: "10", name: "Ana CLT", directoryActive: true, hoursRegime: "clt", roles: [] },
      { id: "11", name: "Paulo PJ", directoryActive: true, hoursRegime: "pj", roles: [] },
    ],
    peopleSyncedAt: null,
    designations: [],
    ...overrides,
  };
}

function item(overrides: Partial<AdditionalHoursItemDto>): AdditionalHoursItemDto {
  return {
    entryId: "5",
    memberId: "10",
    memberName: "Ana CLT",
    regime: "clt",
    localDate: "2026-10-03",
    startTime: "09:00",
    endTime: "19:00",
    projectName: "McCain",
    workItemId: 15596,
    workItemTitle: "Suporte",
    note: null,
    durationSeconds: 36000,
    additional: { seconds: 36000, weightedSeconds: 54000, nightSeconds: 0, dayType: "saturday", status: "pending", denied: null },
    classifiedAt: null,
    classificationNote: null,
    ...overrides,
  };
}

async function openTab(name: string) {
  render(<SettingsPage />);
  fireEvent.click(await screen.findByRole("tab", { name }));
}

describe("Configuração: horas extras", () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date(2026, 9, 2, 12, 0, 0));
    Object.values(api).forEach((fn) => fn.mockReset());
    api.fetchSettings.mockResolvedValue(settings());
    fetchAdditionalHours.mockReset().mockResolvedValue({ from: "2026-07-04", to: "2026-10-02", items: [] });
    classifyAdditionalHours.mockReset().mockResolvedValue({ updated: 1 });
  });

  afterEach(() => {
    cleanup();
    vi.useRealTimers();
  });

  it("mostra as regras em vigor e salva uma nova versão com os valores editados", async () => {
    api.updateOvertimeRules.mockResolvedValue(settings());
    await openTab("Horas extras");

    expect(screen.getByLabelText("Início do expediente")).toHaveValue("09:00");
    expect(screen.getByLabelText("Fim do expediente")).toHaveValue("18:00");
    expect(screen.getByLabelText("Domingo")).toHaveValue(2);
    expect(screen.getByText(/Versão 1 em vigor/)).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText("Sábado"), { target: { value: "2" } });
    fireEvent.click(screen.getByRole("button", { name: "Salvar regras" }));

    await waitFor(() => expect(api.updateOvertimeRules).toHaveBeenCalledTimes(1));
    expect(api.updateOvertimeRules.mock.calls[0][1]).toMatchObject({
      enabled: true,
      workdayStart: "09:00",
      workdayEnd: "18:00",
      factorSaturday: 2,
      factorSunday: 2,
      nightPercent: 20,
      requireTimeOfDay: true,
    });
    expect(await screen.findByText(/Valem para os lançamentos feitos daqui para a frente/)).toBeInTheDocument();
  });

  it("feriados: lista, traz os nacionais do ano e remove", async () => {
    api.addNationalHolidays.mockResolvedValue(settings());
    api.removeHoliday.mockResolvedValue(settings({ holidays: [] }));
    await openTab("Horas extras");

    const holidays = screen.getByRole("region", { name: "Feriados" });
    expect(within(holidays).getByText("20/11/2026")).toBeInTheDocument();

    fireEvent.click(within(holidays).getByRole("button", { name: "Adicionar feriados nacionais de 2026" }));
    await waitFor(() => expect(api.addNationalHolidays).toHaveBeenCalledWith(expect.anything(), 2026));

    fireEvent.click(await within(holidays).findByRole("button", { name: "Remover feriado Consciência Negra (20/11/2026)" }));
    await waitFor(() => expect(api.removeHoliday).toHaveBeenCalledWith(expect.anything(), "1"));
  });

  it("define o regime de cada pessoa", async () => {
    api.setHoursRegime.mockResolvedValue(settings());
    await openTab("Horas extras");

    fireEvent.change(screen.getByLabelText("Ana CLT"), { target: { value: "none" } });

    await waitFor(() => expect(api.setHoursRegime).toHaveBeenCalledWith(expect.anything(), "10", "none"));
  });
});

describe("Configuração: fila de horas adicionais", () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date(2026, 9, 2, 12, 0, 0));
    api.fetchSettings.mockReset().mockResolvedValue(settings());
    fetchAdditionalHours.mockReset();
    classifyAdditionalHours.mockReset().mockResolvedValue({ updated: 1 });
  });

  afterEach(() => {
    cleanup();
    vi.useRealTimers();
  });

  it("lista as horas a classificar dos últimos 90 dias e classifica as selecionadas", async () => {
    fetchAdditionalHours.mockResolvedValue({
      from: "2026-07-04",
      to: "2026-10-02",
      items: [item({ additional: { ...item({}).additional, denied: { reason: "Sem pedido prévio" } } })],
    });
    await openTab("Horas adicionais");

    await waitFor(() =>
      expect(fetchAdditionalHours).toHaveBeenCalledWith(expect.anything(), { from: "2026-07-04", to: "2026-10-02", status: "pending" }),
    );
    const table = await screen.findByRole("table", { name: "Horas adicionais de semanas aprovadas" });
    expect(within(table).getByText("15:00")).toBeInTheDocument(); // 10h de sábado × 1,5
    expect(within(table).getByText(/Não autorizada pelo aprovador: Sem pedido prévio/)).toBeInTheDocument();

    const bank = screen.getByRole("button", { name: "Banco de horas" });
    expect(bank).toBeDisabled(); // nada selecionado

    fireEvent.click(within(table).getByRole("checkbox", { name: "Selecionar Ana CLT em 03/10/2026" }));
    expect(screen.getByText(/1 selecionada\(s\): 10:00 \(15:00 ponderadas\)/)).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("Observação"), { target: { value: "Folga na emenda" } });
    fireEvent.click(bank);

    await waitFor(() => expect(classifyAdditionalHours).toHaveBeenCalledWith(expect.anything(), ["5"], "bank", "Folga na emenda"));
    expect(await screen.findByText("1 lançamento(s) classificados como Banco de horas.")).toBeInTheDocument();
  });

  it("com PJ selecionado, banco de horas fica indisponível (só a pagar)", async () => {
    fetchAdditionalHours.mockResolvedValue({
      from: "2026-07-04",
      to: "2026-10-02",
      items: [item({ entryId: "6", memberId: "11", memberName: "Paulo PJ", regime: "pj" })],
    });
    await openTab("Horas adicionais");

    fireEvent.click(await screen.findByRole("checkbox", { name: "Selecionar todas" }));

    expect(screen.getByRole("button", { name: "Banco de horas" })).toBeDisabled();
    fireEvent.click(screen.getByRole("button", { name: "A pagar" }));
    await waitFor(() => expect(classifyAdditionalHours).toHaveBeenCalledWith(expect.anything(), ["6"], "payable", undefined));
  });

  it("muda o filtro de situação", async () => {
    fetchAdditionalHours.mockResolvedValue({ from: "2026-07-04", to: "2026-10-02", items: [] });
    await openTab("Horas adicionais");
    expect(await screen.findByText("Nenhuma hora adicional neste filtro.")).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText("Situação"), { target: { value: "classified" } });

    await waitFor(() =>
      expect(fetchAdditionalHours).toHaveBeenLastCalledWith(expect.anything(), expect.objectContaining({ status: "classified" })),
    );
  });
});
