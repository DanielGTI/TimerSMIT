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

const fetchClosing = vi.fn();
const downloadClosingCsv = vi.fn();
vi.mock("../src/lib/api/additionalHours", async (importOriginal) => ({
  ...(await importOriginal<typeof import("../src/lib/api/additionalHours")>()),
  fetchClosing: (...args: unknown[]) => fetchClosing(...args),
  downloadClosingCsv: (...args: unknown[]) => downloadClosingCsv(...args),
}));

const fetchSettings = vi.fn();
vi.mock("../src/lib/api/settings", () => ({
  fetchSettings: (...args: unknown[]) => fetchSettings(...args),
}));

import { WeekAlerts, alertText } from "../src/components/WeekAlerts";
import type { ClosingMemberDto } from "../src/lib/api/additionalHours";
import { SettingsPage } from "../src/pages/settings/SettingsPage";

const H = 3600;

describe("Avisos de jornada", () => {
  afterEach(cleanup);

  it("descreve cada aviso com o valor e o limite", () => {
    expect(alertText({ type: "daily_extra", date: "2026-09-29", seconds: 12600, limitSeconds: 2 * H })).toBe(
      "ter 29/09: 03:30 de horas extras no dia (limite 02:00).",
    );
    expect(alertText({ type: "weekly_hours", date: "2026-09-28", seconds: 45 * H, limitSeconds: 44 * H })).toBe(
      "Semana com 45:00 trabalhadas (limite 44:00).",
    );
    expect(
      alertText({
        type: "rest",
        date: "2026-09-30",
        seconds: 8 * H,
        limitSeconds: 11 * H,
        previousEnd: "2026-09-29 23:00",
        nextStart: "2026-09-30 07:00",
      }),
    ).toBe("qua 30/09: só 08:00 de descanso entre 29/09 23:00 e 30/09 07:00 (mínimo 11:00).");
  });

  it("não aparece sem avisos e lembra que nada é bloqueado", () => {
    const { container } = render(<WeekAlerts alerts={[]} audience="self" />);
    expect(container).toBeEmptyDOMElement();

    render(<WeekAlerts alerts={[{ type: "weekly_hours", date: "2026-09-28", seconds: 45 * H, limitSeconds: 44 * H }]} audience="self" />);
    const note = screen.getByRole("note", { name: "Avisos de jornada" });
    expect(note).toHaveTextContent("não impedem o envio");
    expect(within(note).getAllByRole("listitem")).toHaveLength(1);
  });
});

function member(overrides: Partial<ClosingMemberDto> = {}): ClosingMemberDto {
  const zero = { seconds: 0, nightSeconds: 0, weightedSeconds: 0 };
  return {
    memberId: "10",
    memberName: "Ana CLT",
    regime: "clt",
    totals: {
      overtime: { seconds: 6 * H, nightSeconds: H, weightedSeconds: 10 * H },
      payable: zero,
      bank: { seconds: 4 * H, nightSeconds: 0, weightedSeconds: 6 * H },
      pending: { seconds: 2 * H, nightSeconds: 0, weightedSeconds: 3 * H },
      unapproved: zero,
    },
    lines: [
      { category: "overtime", factor: 1.5, seconds: 4 * H, nightSeconds: H, weightedSeconds: 6.4 * H },
      { category: "overtime", factor: 2, seconds: 2 * H, nightSeconds: 0, weightedSeconds: 3.6 * H },
      { category: "bank", factor: 1.5, seconds: 4 * H, nightSeconds: 0, weightedSeconds: 6 * H },
      { category: "pending", factor: null, seconds: 2 * H, nightSeconds: 0, weightedSeconds: 3 * H },
    ],
    bank: { creditedSeconds: 6 * H, timeOffSeconds: 4 * H, payoutSeconds: 0, adjustmentSeconds: 0, expiredSeconds: 3 * H, balanceSeconds: 2 * H },
    alerts: { daily_extra: 2, weekly_hours: 0, rest: 1 },
    deniedCount: 1,
    ...overrides,
  };
}

describe("Configuração: fechamento do mês", () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date(2026, 9, 12, 12, 0, 0));
    saveBlob.mockReset();
    fetchClosing.mockReset().mockResolvedValue({ month: "2026-10", from: "2026-10-01", to: "2026-10-31", members: [member()] });
    downloadClosingCsv.mockReset().mockResolvedValue(new Blob(["x"]));
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
  });
  afterEach(() => {
    cleanup();
    vi.useRealTimers();
  });

  async function openTab() {
    render(<SettingsPage />);
    fireEvent.click(await screen.findByRole("tab", { name: "Fechamento do mês" }));
    return screen.findByRole("region", { name: "Fechamento do mês" });
  }

  it("mostra o mês atual com horas por fator, banco, pendências e avisos", async () => {
    const card = await openTab();
    await waitFor(() => expect(fetchClosing).toHaveBeenCalledWith(expect.anything(), "2026-10"));

    const row = within(await within(card).findByRole("table")).getAllByRole("row")[1];
    expect(row).toHaveTextContent("Ana CLT");
    expect(row).toHaveTextContent("06:00");
    expect(row).toHaveTextContent("1,5× 04:00");
    expect(row).toHaveTextContent("2× 02:00");
    expect(row).toHaveTextContent("10:00 ponderadas");
    expect(row).toHaveTextContent("01:00 noturnas");
    expect(row).toHaveTextContent("Saldo no fim do mês: 02:00");
    expect(row).toHaveTextContent("Vencido (pagar como hora extra): 03:00");
    expect(row).toHaveTextContent("A classificar: 02:00");
    expect(row).toHaveTextContent("1 não autorizada(s) pelo aprovador");
    expect(row).toHaveTextContent("2 dia(s) acima do limite de horas extras");
    expect(row).toHaveTextContent("1 descanso(s) curto(s) entre jornadas");
    expect(within(card).getByRole("note")).toHaveTextContent("Ainda há 02:00 de horas adicionais");
  });

  it("muda o mês e exporta o CSV", async () => {
    const card = await openTab();
    await within(card).findByRole("table");

    fireEvent.change(within(card).getByLabelText("Mês"), { target: { value: "2026-09" } });
    await waitFor(() => expect(fetchClosing).toHaveBeenLastCalledWith(expect.anything(), "2026-09"));
    await within(card).findByRole("table");

    fireEvent.click(within(card).getByRole("button", { name: "Exportar CSV" }));
    await waitFor(() => expect(saveBlob).toHaveBeenCalledWith(expect.any(Blob), "fechamento_2026-09.csv"));
    expect(downloadClosingCsv).toHaveBeenCalledWith(expect.anything(), "2026-09");
  });

  it("mês sem nada a fechar", async () => {
    fetchClosing.mockResolvedValue({ month: "2026-10", from: "2026-10-01", to: "2026-10-31", members: [] });
    const card = await openTab();
    expect(await within(card).findByText(/Nada a fechar em/)).toBeInTheDocument();
    expect(within(card).queryByRole("note")).not.toBeInTheDocument();
  });
});
