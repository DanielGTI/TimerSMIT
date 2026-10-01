import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("../src/lib/api/config", () => ({
  getApiBaseUrl: () => "https://api.example.test",
}));

const fetchReport = vi.fn();
const fetchReportOptions = vi.fn();
const fetchReportDetail = vi.fn();
const downloadReportCsv = vi.fn();
const saveBlob = vi.fn();

vi.mock("../src/lib/api/reports", () => ({
  fetchReport: (...args: unknown[]) => fetchReport(...args),
  fetchReportOptions: (...args: unknown[]) => fetchReportOptions(...args),
  fetchReportDetail: (...args: unknown[]) => fetchReportDetail(...args),
  downloadReportCsv: (...args: unknown[]) => downloadReportCsv(...args),
}));

vi.mock("../src/lib/devops/sdk", () => ({
  getHostContext: async () => ({ name: "smitbr" }),
}));

vi.mock("../src/lib/download", () => ({
  saveBlob: (...args: unknown[]) => saveBlob(...args),
}));

import type { ReportDto, ReportOptionsDto, ReportRowDto } from "../src/lib/api/reports";
import { ReportsPage } from "../src/pages/reports/ReportsPage";

function row(overrides: Partial<ReportRowDto> = {}): ReportRowDto {
  return {
    id: "1",
    localDate: "2026-10-01",
    memberId: "3",
    memberName: "Alice",
    projectId: "1",
    projectName: "Projeto A",
    workItemId: 15835,
    workItemTitle: "Teste Tracker",
    workItemType: "Task",
    iterationPath: "Projeto A\\Sprint 1",
    startTime: null,
    endTime: null,
    activityTypeId: "2",
    activityTypeName: "Desenvolvimento",
    activityTypeColor: "#A6D8F5",
    durationSeconds: 5400,
    billable: true,
    source: "manual",
    note: "Planejamento",
    weekStatus: "approved",
    ...overrides,
  };
}

function report(overrides: Partial<ReportDto> = {}): ReportDto {
  return {
    scope: { level: "all", canFilterByMember: true },
    totals: { totalSeconds: 9000, billableSeconds: 5400, nonBillableSeconds: 3600, entryCount: 2 },
    byMember: [{ id: "3", name: "Alice", totalSeconds: 9000, entryCount: 2 }],
    byProject: [{ id: "1", name: "Projeto A", totalSeconds: 9000, entryCount: 2 }],
    byActivity: [
      { id: "2", name: "Desenvolvimento", totalSeconds: 5400, entryCount: 1 },
      { id: null, name: "Não definido", totalSeconds: 3600, entryCount: 1 },
    ],
    rows: [row(), row({ id: "2", durationSeconds: 3600, billable: false, activityTypeId: null, activityTypeName: null, activityTypeColor: null, weekStatus: "open", note: null })],
    pagination: { page: 1, perPage: 50, total: 2, lastPage: 1 },
    ...overrides,
  };
}

const options: ReportOptionsDto = {
  scope: { level: "all", canFilterByMember: true },
  members: [
    { id: "3", name: "Alice" },
    { id: "4", name: "Bob" },
  ],
  projects: [
    { id: "1", name: "Projeto A" },
    { id: "2", name: "Projeto B" },
  ],
  activityTypes: [
    { id: "2", name: "Desenvolvimento", color: "#A6D8F5", enabled: true },
    { id: "9", name: "Antiga", color: null, enabled: false },
  ],
};

const lastFilters = () => fetchReport.mock.calls[fetchReport.mock.calls.length - 1][1];
const lastPage = () => fetchReport.mock.calls[fetchReport.mock.calls.length - 1][2];

describe("ReportsPage", () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date(2026, 9, 5, 12, 0, 0));

    fetchReport.mockReset().mockResolvedValue(report());
    fetchReportOptions.mockReset().mockResolvedValue(options);
    fetchReportDetail.mockReset().mockResolvedValue({ ...report(), rows: report().rows, truncated: false });
    downloadReportCsv.mockReset().mockResolvedValue(new Blob(["x"]));
    saveBlob.mockReset();
  });

  afterEach(() => {
    cleanup();
    vi.useRealTimers();
  });

  it("abre no mês atual e mostra totais, quebras e lançamentos", async () => {
    render(<ReportsPage />);

    const totals = await screen.findByRole("group", { name: "Totais" });
    expect(lastFilters()).toEqual({ from: "2026-10-01", to: "2026-10-31" });
    expect(lastPage()).toBe(1);
    expect(within(totals).getByText("02:30")).toBeInTheDocument(); // 9000 s
    expect(within(totals).getByText("01:30")).toBeInTheDocument(); // faturável
    expect(within(totals).getByText("01:00")).toBeInTheDocument(); // não faturável

    const byActivity = screen.getByRole("region", { name: "Por atividade" });
    expect(within(byActivity).getByText("Desenvolvimento")).toBeInTheDocument();
    expect(within(byActivity).getByText("Não definido")).toBeInTheDocument();

    const table = screen.getByRole("table", { name: "Lançamentos do relatório" });
    expect(within(table).getAllByRole("row")).toHaveLength(3);
    expect(within(table).getByText("Planejamento")).toBeInTheDocument();
    expect(within(table).getByText("Aprovada")).toBeInTheDocument();
    expect(within(table).getByText("Aberta")).toBeInTheDocument();
  });

  it("aplica os filtros escolhidos e volta para a primeira página", async () => {
    render(<ReportsPage />);
    await screen.findByRole("group", { name: "Totais" });

    fireEvent.change(screen.getByLabelText("Pessoa"), { target: { value: "4" } });
    fireEvent.change(screen.getByLabelText("Projeto"), { target: { value: "2" } });
    fireEvent.change(screen.getByLabelText("Atividade"), { target: { value: "9" } });
    fireEvent.change(screen.getByLabelText("Faturável"), { target: { value: "true" } });
    fireEvent.change(screen.getByLabelText("Estado da semana"), { target: { value: "approved" } });
    fireEvent.change(screen.getByLabelText("Work item"), { target: { value: "15a8b3" } });
    fireEvent.click(screen.getByRole("button", { name: "Aplicar filtros" }));

    await waitFor(() =>
      expect(lastFilters()).toEqual({
        from: "2026-10-01",
        to: "2026-10-31",
        memberId: "4",
        projectId: "2",
        activityTypeId: "9",
        billable: "true",
        status: "approved",
        workItemId: "1583", // só dígitos de "15a8b3"
      }),
    );
    expect(lastPage()).toBe(1);
  });

  it("não consulta até aplicar, e voltar a 'Todos' remove o filtro", async () => {
    render(<ReportsPage />);
    await screen.findByRole("group", { name: "Totais" });
    const calls = fetchReport.mock.calls.length;

    fireEvent.change(screen.getByLabelText("Projeto"), { target: { value: "2" } });
    expect(fetchReport.mock.calls.length).toBe(calls);

    fireEvent.change(screen.getByLabelText("Projeto"), { target: { value: "" } });
    fireEvent.click(screen.getByRole("button", { name: "Aplicar filtros" }));

    await waitFor(() => expect(fetchReport.mock.calls.length).toBeGreaterThan(calls));
    expect(lastFilters()).toEqual({ from: "2026-10-01", to: "2026-10-31" });
  });

  it("períodos prontos preenchem as datas", async () => {
    render(<ReportsPage />);
    await screen.findByRole("group", { name: "Totais" });

    fireEvent.click(screen.getByRole("button", { name: "Semana passada" }));
    expect(screen.getByLabelText("De")).toHaveValue("2026-09-28");
    expect(screen.getByLabelText("Até")).toHaveValue("2026-10-04");

    fireEvent.click(screen.getByRole("button", { name: "Aplicar filtros" }));
    await waitFor(() => expect(lastFilters()).toEqual({ from: "2026-09-28", to: "2026-10-04" }));
  });

  it("recusa período invertido sem consultar o servidor", async () => {
    render(<ReportsPage />);
    await screen.findByRole("group", { name: "Totais" });
    const calls = fetchReport.mock.calls.length;

    fireEvent.change(screen.getByLabelText("De"), { target: { value: "2026-11-01" } });
    fireEvent.click(screen.getByRole("button", { name: "Aplicar filtros" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("período válido");
    expect(fetchReport.mock.calls.length).toBe(calls);
  });

  it("pagina os lançamentos", async () => {
    fetchReport.mockResolvedValue(report({ pagination: { page: 1, perPage: 50, total: 120, lastPage: 3 } }));
    render(<ReportsPage />);
    await screen.findByRole("group", { name: "Totais" });

    expect(screen.getByRole("button", { name: "Anterior" })).toBeDisabled();
    expect(screen.getByText(/Página 1 de 3 · 120 lançamentos/)).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Próxima" }));

    await waitFor(() => expect(lastPage()).toBe(2));
  });

  it("sem resultados mostra o aviso e não deixa exportar", async () => {
    fetchReport.mockResolvedValue(
      report({ rows: [], totals: { totalSeconds: 0, billableSeconds: 0, nonBillableSeconds: 0, entryCount: 0 }, byMember: [], byProject: [], byActivity: [], pagination: { page: 1, perPage: 50, total: 0, lastPage: 1 } }),
    );
    render(<ReportsPage />);

    expect(await screen.findByText("Nenhum lançamento para esses filtros.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Exportar CSV" })).toBeDisabled();
  });

  it("exporta o CSV com os filtros JÁ aplicados, não com o rascunho", async () => {
    render(<ReportsPage />);
    await screen.findByRole("group", { name: "Totais" });

    fireEvent.change(screen.getByLabelText("Projeto"), { target: { value: "2" } }); // rascunho, não aplicado
    fireEvent.click(screen.getByRole("button", { name: "Exportar CSV" }));

    await waitFor(() => expect(saveBlob).toHaveBeenCalledTimes(1));
    expect(downloadReportCsv).toHaveBeenCalledWith(expect.anything(), { from: "2026-10-01", to: "2026-10-31" });
    expect(saveBlob.mock.calls[0][1]).toBe("horas_2026-10-01_a_2026-10-31.csv");
  });

  it("mostra o motivo quando a exportação é recusada", async () => {
    downloadReportCsv.mockRejectedValue(new Error("Você não tem permissão para esta ação."));
    render(<ReportsPage />);
    await screen.findByRole("group", { name: "Totais" });

    fireEvent.click(screen.getByRole("button", { name: "Exportar CSV" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("permissão");
    expect(saveBlob).not.toHaveBeenCalled();
  });

  it("quem só enxerga as próprias horas não vê filtro nem coluna de pessoa", async () => {
    const selfScope = { level: "self" as const, canFilterByMember: false };
    fetchReportOptions.mockResolvedValue({ ...options, scope: selfScope, members: [{ id: "3", name: "Alice" }] });
    fetchReport.mockResolvedValue(report({ scope: selfScope }));
    render(<ReportsPage />);

    await screen.findByRole("group", { name: "Totais" });
    expect(screen.queryByLabelText("Pessoa")).not.toBeInTheDocument();
    expect(screen.queryByRole("region", { name: "Por pessoa" })).not.toBeInTheDocument();
    expect(screen.queryByRole("columnheader", { name: "Pessoa" })).not.toBeInTheDocument();
  });

  it("lista atividades desabilitadas nos filtros (lançamentos antigos ainda as usam)", async () => {
    render(<ReportsPage />);
    await screen.findByRole("group", { name: "Totais" });

    expect(screen.getByRole("option", { name: "Antiga (desabilitada)" })).toBeInTheDocument();
  });

  it("mostra erro de carregamento", async () => {
    fetchReport.mockRejectedValue(new Error("Falha na chamada à API (/api/reports/time): HTTP 500"));
    render(<ReportsPage />);

    expect(await screen.findByRole("alert")).toHaveTextContent("HTTP 500");
  });

  it("a aba Detalhada só busca a grade quando aberta e usa os mesmos filtros aplicados", async () => {
    render(<ReportsPage />);
    await screen.findByRole("group", { name: "Totais" });
    expect(fetchReportDetail).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole("tab", { name: "Detalhada" }));

    expect(await screen.findByRole("table", { name: "Lançamentos detalhados" })).toBeInTheDocument();
    expect(fetchReportDetail).toHaveBeenCalledTimes(1);
    expect(fetchReportDetail.mock.calls[0][1]).toEqual({ from: "2026-10-01", to: "2026-10-31" });
    expect(screen.getByText(/Linhas filtradas:/)).toHaveTextContent("Linhas filtradas: 2 (02:30 h)");
    expect((await screen.findAllByRole("link", { name: "15835" }))[0]).toHaveAttribute("href", expect.stringContaining("/smitbr/Projeto%20A/_workitems/edit/15835"));
  });

  it("avisa quando o período tem mais linhas do que a grade traz", async () => {
    fetchReportDetail.mockResolvedValue({
      ...report(),
      totals: { totalSeconds: 9000, billableSeconds: 5400, nonBillableSeconds: 3600, entryCount: 25000 },
      truncated: true,
    });
    render(<ReportsPage />);
    await screen.findByRole("group", { name: "Totais" });

    fireEvent.click(screen.getByRole("tab", { name: "Detalhada" }));

    expect(await screen.findByText(/25000 lançamentos e a grade mostra só os primeiros 2/)).toBeInTheDocument();
  });
});
