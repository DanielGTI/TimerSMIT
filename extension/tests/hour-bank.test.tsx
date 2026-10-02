import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("../src/lib/api/config", () => ({
  getApiBaseUrl: () => "https://api.example.test",
}));

vi.mock("../src/lib/devops/directory", () => ({
  fetchActiveDirectoryPeople: vi.fn(async () => []),
}));

const fetchCurrentSession = vi.fn();
vi.mock("../src/lib/api/me", () => ({
  fetchCurrentSession: (...args: unknown[]) => fetchCurrentSession(...args),
}));

const bank = {
  fetchMyHourBank: vi.fn(),
  fetchHourBankOverview: vi.fn(),
  fetchHourBankStatement: vi.fn(),
  addHourBankMovement: vi.fn(),
  removeHourBankMovement: vi.fn(),
};
vi.mock("../src/lib/api/hourBank", async (importOriginal) => ({
  ...(await importOriginal<typeof import("../src/lib/api/hourBank")>()),
  fetchMyHourBank: (...args: unknown[]) => bank.fetchMyHourBank(...args),
  fetchHourBankOverview: (...args: unknown[]) => bank.fetchHourBankOverview(...args),
  fetchHourBankStatement: (...args: unknown[]) => bank.fetchHourBankStatement(...args),
  addHourBankMovement: (...args: unknown[]) => bank.addHourBankMovement(...args),
  removeHourBankMovement: (...args: unknown[]) => bank.removeHourBankMovement(...args),
}));

const fetchSettings = vi.fn();
vi.mock("../src/lib/api/settings", () => ({
  fetchSettings: (...args: unknown[]) => fetchSettings(...args),
}));

import { createApiClient } from "../src/lib/api/client";
import type { HourBankEventDto, HourBankStatementDto } from "../src/lib/api/hourBank";
import { MyHourBank } from "../src/pages/timesheet/MyHourBank";
import { SettingsPage } from "../src/pages/settings/SettingsPage";

const H = 3600;

function event(overrides: Partial<HourBankEventDto>): HourBankEventDto {
  return {
    id: "entry:5",
    type: "credit",
    date: "2026-10-03",
    seconds: 6 * H,
    balanceSeconds: 6 * H,
    note: null,
    movementId: null,
    createdBy: null,
    entryId: "5",
    workItemId: 15596,
    workItemTitle: "Suporte",
    projectName: "McCain",
    additionalSeconds: 4 * H,
    expiresOn: "2027-04-03",
    remainingSeconds: 2 * H,
    creditDate: null,
    uncoveredSeconds: null,
    ...overrides,
  };
}

function statement(overrides: Partial<HourBankStatementDto> = {}): HourBankStatementDto {
  return {
    member: { id: "10", name: "Ana CLT", regime: "clt" },
    validityMonths: 6,
    summary: { balanceSeconds: 2 * H, expiredSeconds: 0, expiringSoonSeconds: 0, nextExpiry: null, uncoveredSeconds: 0 },
    events: [
      event({}),
      event({
        id: "movement:0000000007",
        type: "time_off",
        date: "2026-10-09",
        seconds: -4 * H,
        balanceSeconds: 2 * H,
        note: "Folga na emenda",
        movementId: "7",
        createdBy: "Daniel Admin",
        entryId: null,
        workItemId: null,
        additionalSeconds: null,
        expiresOn: null,
        remainingSeconds: null,
      }),
    ],
    ...overrides,
  };
}

const client = createApiClient({ apiBaseUrl: "https://api.example.test" });

describe("Folha semanal: meu banco de horas", () => {
  beforeEach(() => {
    fetchCurrentSession.mockReset();
    Object.values(bank).forEach((fn) => fn.mockReset());
  });
  afterEach(cleanup);

  it("CLT vê o saldo e abre o extrato, mais recente primeiro", async () => {
    fetchCurrentSession.mockResolvedValue({ displayName: "Ana", hoursRegime: "clt", overtime: { enabled: true } });
    bank.fetchMyHourBank.mockResolvedValue(statement());
    render(<MyHourBank client={client} />);

    const card = await screen.findByRole("region", { name: "Meu banco de horas" });
    expect(within(card).getByText("Saldo").nextSibling).toHaveTextContent("02:00");
    expect(within(card).queryByRole("table")).not.toBeInTheDocument();

    fireEvent.click(within(card).getByRole("button", { name: "Ver extrato" }));
    const rows = within(within(card).getByRole("table")).getAllByRole("row");
    expect(rows[1]).toHaveTextContent("Folga");
    expect(rows[1]).toHaveTextContent("−04:00");
    expect(rows[2]).toHaveTextContent("#15596 Suporte");
    expect(rows[2]).toHaveTextContent("vence em 03/04/2027 · restam 02:00");
    expect(within(card).queryByRole("button", { name: /Desfazer/ })).not.toBeInTheDocument();
  });

  it("não aparece com o controle desligado nem para PJ sem movimento", async () => {
    fetchCurrentSession.mockResolvedValue({ displayName: "Ana", hoursRegime: "clt", overtime: { enabled: false } });
    const { unmount } = render(<MyHourBank client={client} />);
    await waitFor(() => expect(fetchCurrentSession).toHaveBeenCalled());
    expect(bank.fetchMyHourBank).not.toHaveBeenCalled();
    unmount();

    fetchCurrentSession.mockResolvedValue({ displayName: "Paulo", hoursRegime: "pj", overtime: { enabled: true } });
    bank.fetchMyHourBank.mockResolvedValue(statement({ events: [] }));
    render(<MyHourBank client={client} />);
    await waitFor(() => expect(bank.fetchMyHourBank).toHaveBeenCalled());
    expect(screen.queryByRole("region", { name: "Meu banco de horas" })).not.toBeInTheDocument();
  });
});

describe("Configuração: banco de horas", () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date(2026, 9, 6, 12, 0, 0));
    Object.values(bank).forEach((fn) => fn.mockReset());
    fetchSettings.mockReset().mockResolvedValue({
      organization: { name: "smitbr", timezone: "America/Sao_Paulo" },
      policy: { durationIncrementMinutes: 1, dailyLimitHours: 24, retroactiveWindowDays: 30, commentRequired: false, version: 0, effectiveFrom: null },
      overtime: { enabled: true, workdayStart: "09:00", workdayEnd: "18:00", version: 1 },
      holidays: [],
      projects: [],
      activityTypes: [],
      members: [],
      peopleSyncedAt: null,
      designations: [],
    });
    bank.fetchHourBankOverview.mockResolvedValue([
      {
        memberId: "10",
        memberName: "Ana CLT",
        regime: "clt",
        summary: { balanceSeconds: 2 * H, expiredSeconds: 3 * H, expiringSoonSeconds: H, nextExpiry: "2026-10-20", uncoveredSeconds: 0 },
      },
    ]);
    bank.fetchHourBankStatement.mockResolvedValue(statement());
  });
  afterEach(() => {
    cleanup();
    vi.useRealTimers();
  });

  async function openAna() {
    render(<SettingsPage />);
    fireEvent.click(await screen.findByRole("tab", { name: "Banco de horas" }));
    const table = await screen.findByRole("table", { name: "Saldo de cada pessoa" });
    expect(within(table).getByText("03:00")).toBeInTheDocument(); // vencido
    fireEvent.click(within(table).getByRole("button", { name: "Abrir extrato de Ana CLT" }));
    return screen.findByRole("region", { name: "Banco de horas de Ana CLT" });
  }

  it("lança uma folga e atualiza o extrato", async () => {
    bank.addHourBankMovement.mockResolvedValue(statement());
    const panel = await openAna();
    await within(panel).findByRole("table");

    fireEvent.change(within(panel).getByLabelText("Data"), { target: { value: "2026-10-09" } });
    fireEvent.change(within(panel).getByLabelText("Horas"), { target: { value: "8" } });
    fireEvent.change(within(panel).getByLabelText("Motivo"), { target: { value: " Emenda do feriado " } });
    fireEvent.click(within(panel).getByRole("button", { name: "Lançar" }));

    await waitFor(() =>
      expect(bank.addHourBankMovement).toHaveBeenCalledWith(expect.anything(), "10", {
        kind: "time_off",
        seconds: 8 * H,
        localDate: "2026-10-09",
        note: "Emenda do feriado",
      }),
    );
    expect(await screen.findByText("Lançado: Folga (compensação), 08:00.")).toBeInTheDocument();
    expect(bank.fetchHourBankOverview).toHaveBeenCalledTimes(2);
  });

  it("ajuste para menos e o erro de saldo insuficiente", async () => {
    bank.addHourBankMovement.mockRejectedValue(new Error("Saldo insuficiente: em 06/10/2026 faltariam 01:00 no banco de horas."));
    const panel = await openAna();
    await within(panel).findByRole("table");

    fireEvent.change(within(panel).getByLabelText("Tipo"), { target: { value: "adjustment_debit" } });
    fireEvent.change(within(panel).getByLabelText("Horas"), { target: { value: "03:00" } });
    fireEvent.change(within(panel).getByLabelText("Motivo"), { target: { value: "Correção" } });
    fireEvent.click(within(panel).getByRole("button", { name: "Lançar" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("Saldo insuficiente");
    expect(bank.addHourBankMovement.mock.calls[0][2]).toMatchObject({ kind: "adjustment", direction: "debit", seconds: 3 * H });
  });

  it("horas em formato inválido não chamam o servidor", async () => {
    const panel = await openAna();
    await within(panel).findByRole("table");

    fireEvent.change(within(panel).getByLabelText("Horas"), { target: { value: "8h" } });
    fireEvent.change(within(panel).getByLabelText("Motivo"), { target: { value: "X" } });
    fireEvent.click(within(panel).getByRole("button", { name: "Lançar" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("HH:MM");
    expect(bank.addHourBankMovement).not.toHaveBeenCalled();
  });

  it("desfaz uma folga", async () => {
    bank.removeHourBankMovement.mockResolvedValue(statement({ events: [event({ remainingSeconds: 6 * H })] }));
    const panel = await openAna();

    fireEvent.click(await within(panel).findByRole("button", { name: "Desfazer folga de 09/10/2026" }));

    await waitFor(() => expect(bank.removeHourBankMovement).toHaveBeenCalledWith(expect.anything(), "7"));
    expect(await screen.findByText("Desfeito: Folga de 09/10/2026.")).toBeInTheDocument();
  });
});
