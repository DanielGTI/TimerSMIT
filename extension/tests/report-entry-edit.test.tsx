import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("../src/lib/api/config", () => ({
  getApiBaseUrl: () => "https://api.example.test",
}));

const fetchReport = vi.fn();
const fetchReportOptions = vi.fn();
const fetchReportDetail = vi.fn();

vi.mock("../src/lib/api/reports", () => ({
  fetchReport: (...args: unknown[]) => fetchReport(...args),
  fetchReportOptions: (...args: unknown[]) => fetchReportOptions(...args),
  fetchReportDetail: (...args: unknown[]) => fetchReportDetail(...args),
  downloadReportCsv: vi.fn(),
}));

const adminUpdateEntry = vi.fn();
const adminDeleteEntry = vi.fn();
vi.mock("../src/lib/api/adminEntries", () => ({
  adminUpdateEntry: (...args: unknown[]) => adminUpdateEntry(...args),
  adminDeleteEntry: (...args: unknown[]) => adminDeleteEntry(...args),
}));

vi.mock("../src/lib/devops/sdk", () => ({
  getHostContext: async () => ({ name: "smitbr" }),
}));

import type { ReportDto, ReportOptionsDto, ReportRowDto } from "../src/lib/api/reports";
import { ReportsPage } from "../src/pages/reports/ReportsPage";

function row(overrides: Partial<ReportRowDto> = {}): ReportRowDto {
  return {
    id: "2",
    localDate: "2026-09-01",
    memberId: "3",
    memberName: "Willian de Sena Chiquinato",
    projectId: "1",
    projectName: "SARC",
    workItemId: 15647,
    workItemTitle: "Implementação do PIX em lote.",
    workItemType: "Task",
    iterationPath: null,
    startTime: "09:28",
    endTime: "14:00",
    activityTypeId: "2",
    activityTypeName: "Desenvolvimento",
    activityTypeColor: "#A6D8F5",
    durationSeconds: 16320,
    billable: false,
    source: "manual",
    note: null,
    revision: 1,
    weekStatus: "open",
    ...overrides,
  };
}

const rows = [row(), row({ id: "3", localDate: "2026-09-02", weekStatus: "approved" })];

const report: ReportDto = {
  scope: { level: "all", canFilterByMember: true },
  totals: { totalSeconds: 32640, billableSeconds: 0, nonBillableSeconds: 32640, entryCount: 2 },
  byMember: [],
  byProject: [],
  byActivity: [],
  rows,
  pagination: { page: 1, perPage: 50, total: 2, lastPage: 1 },
};

const options: ReportOptionsDto = {
  scope: { level: "all", canFilterByMember: true },
  billableInUse: false,
  members: [{ id: "3", name: "Willian de Sena Chiquinato" }],
  projects: [{ id: "1", name: "SARC", usesBillable: false }],
  activityTypes: [
    { id: "2", name: "Desenvolvimento", color: "#A6D8F5", enabled: true },
    { id: "5", name: "Reunião Interna", color: "#F4A6A6", enabled: true },
  ],
};

async function openDetail(canEditEntries = true) {
  fetchReportDetail.mockResolvedValue({ ...report, truncated: false, canEditEntries });
  render(<ReportsPage />);
  await screen.findByRole("group", { name: "Totais" });
  fireEvent.click(screen.getByRole("tab", { name: "Detalhada" }));
  return screen.findByRole("table", { name: "Lançamentos detalhados" });
}

describe("Relatório detalhado: correção pelo administrador", () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date(2026, 9, 3, 12, 0, 0));
    fetchReport.mockReset().mockResolvedValue(report);
    fetchReportOptions.mockReset().mockResolvedValue(options);
    fetchReportDetail.mockReset();
    adminUpdateEntry.mockReset().mockResolvedValue({});
    adminDeleteEntry.mockReset().mockResolvedValue(undefined);
  });

  afterEach(() => {
    cleanup();
    vi.useRealTimers();
  });

  it("o administrador vê o lápis nas semanas abertas e o cadeado nas aprovadas; os demais não veem", async () => {
    const table = await openDetail();
    expect(within(table).getByRole("button", { name: "Editar lançamento de Willian de Sena Chiquinato em 01/09/2026 (04:32)" })).toBeInTheDocument();
    expect(within(table).getByRole("img", { name: "Semana aprovada: edição bloqueada" })).toBeInTheDocument();
    expect(within(table).getAllByRole("button", { name: /^Editar lançamento/ })).toHaveLength(1);

    cleanup();
    const other = await openDetail(false);
    expect(within(other).queryByRole("button", { name: /^Editar lançamento/ })).not.toBeInTheDocument();
    expect(within(other).queryByRole("columnheader", { name: "Editar" })).not.toBeInTheDocument();
  });

  it("corrige horário, atividade e comentário e só envia o que mudou; depois recarrega a grade", async () => {
    const table = await openDetail();
    fireEvent.click(within(table).getByRole("button", { name: /^Editar lançamento/ }));

    const panel = screen.getByRole("dialog", { name: "Editar lançamento" });
    expect(within(panel).getByText("Willian de Sena Chiquinato")).toBeInTheDocument();
    expect(within(panel).getByLabelText("De")).toHaveValue("09:28");
    expect(within(panel).getByLabelText("Duração")).toHaveValue("04:32");

    fireEvent.change(within(panel).getByLabelText("Até"), { target: { value: "13:00" } });
    expect(within(panel).getByLabelText("Duração")).toHaveValue("03:32");
    fireEvent.click(within(panel).getByRole("combobox", { name: "Atividade do lançamento" }));
    fireEvent.click(within(panel).getByRole("option", { name: "Reunião Interna" }));
    fireEvent.change(within(panel).getByLabelText("Comentário"), { target: { value: "  Corrigido  " } });
    fireEvent.click(within(panel).getByRole("button", { name: "Salvar" }));

    await waitFor(() => expect(adminUpdateEntry).toHaveBeenCalledTimes(1));
    expect(adminUpdateEntry.mock.calls[0].slice(1)).toEqual([
      "2",
      1,
      { durationSeconds: 12720, activityTypeId: 5, note: "Corrigido" },
    ]);
    expect(await screen.findByText("Lançamento de Willian de Sena Chiquinato corrigido.")).toBeInTheDocument();
    expect(screen.queryByRole("dialog", { name: "Editar lançamento" })).not.toBeInTheDocument();
    await waitFor(() => expect(fetchReportDetail).toHaveBeenCalledTimes(2));
  });

  it("mostra o motivo quando o servidor recusa e mantém o painel aberto", async () => {
    adminUpdateEntry.mockRejectedValue(new Error("A semana deste lançamento (ou a da nova data) foi enviada ou aprovada."));
    const table = await openDetail();
    fireEvent.click(within(table).getByRole("button", { name: /^Editar lançamento/ }));

    const panel = screen.getByRole("dialog", { name: "Editar lançamento" });
    fireEvent.change(within(panel).getByLabelText("Data"), { target: { value: "2026-09-15" } });
    fireEvent.click(within(panel).getByRole("button", { name: "Salvar" }));

    expect(await within(panel).findByRole("alert")).toHaveTextContent("foi enviada ou aprovada");
    expect(adminUpdateEntry.mock.calls[0][3]).toEqual({ localDate: "2026-09-15" });
  });

  it("exclui depois de confirmar", async () => {
    const table = await openDetail();
    fireEvent.click(within(table).getByRole("button", { name: /^Editar lançamento/ }));

    const panel = screen.getByRole("dialog", { name: "Editar lançamento" });
    fireEvent.click(within(panel).getByRole("button", { name: "Excluir" }));
    expect(adminDeleteEntry).not.toHaveBeenCalled();
    fireEvent.click(within(panel).getByRole("button", { name: "Sim, excluir" }));

    await waitFor(() => expect(adminDeleteEntry).toHaveBeenCalledWith(expect.anything(), "2"));
    expect(await screen.findByText("Lançamento de Willian de Sena Chiquinato (01/09/2026, 04:32) excluído.")).toBeInTheDocument();
  });
});
