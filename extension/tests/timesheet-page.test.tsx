import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("../src/lib/api/config", () => ({
  getApiBaseUrl: () => "https://api.example.test",
}));

const fetchWeek = vi.fn();
const fetchMonth = vi.fn();
const submitWeek = vi.fn();
const updateEntry = vi.fn();
const deleteEntry = vi.fn();

vi.mock("../src/lib/api/timesheet", () => ({
  fetchWeek: (...args: unknown[]) => fetchWeek(...args),
  fetchMonth: (...args: unknown[]) => fetchMonth(...args),
  submitWeek: (...args: unknown[]) => submitWeek(...args),
  updateEntry: (...args: unknown[]) => updateEntry(...args),
  deleteEntry: (...args: unknown[]) => deleteEntry(...args),
}));

import type { WeekDto, WeekEntryDto } from "../src/lib/api/timesheet";
import { TimesheetPage } from "../src/pages/timesheet/TimesheetPage";

const WEEK = "2026-09-28";

function entry(overrides: Partial<WeekEntryDto>): WeekEntryDto {
  return {
    id: "1",
    workItemId: 15835,
    localDate: "2026-09-28",
    timezone: "America/Sao_Paulo",
    durationSeconds: 3600,
    source: "manual",
    billable: false,
    activityTypeId: "3",
    note: null,
    revision: 1,
    projectId: "p1",
    projectName: "SMIT LEARN IA",
    workItemTitle: "Teste Tracker",
    workItemType: "Task",
    activityTypeName: "Desenvolvimento",
    activityTypeColor: "#A6D8F5",
    ...overrides,
  };
}

function week(overrides: Partial<WeekDto> = {}): WeekDto {
  const entries = overrides.entries ?? [
    entry({ id: "1", localDate: "2026-09-28", durationSeconds: 3600 }),
    entry({ id: "2", localDate: "2026-09-30", durationSeconds: 1800, note: "Reunião" }),
    entry({ id: "3", workItemId: 200, workItemTitle: "Outro item", localDate: "2026-09-30", durationSeconds: 900 }),
  ];
  const days = ["2026-09-28", "2026-09-29", "2026-09-30", "2026-10-01", "2026-10-02", "2026-10-03", "2026-10-04"].map(
    (date) => ({
      date,
      totalSeconds: entries.filter((item) => item.localDate === date).reduce((sum, item) => sum + item.durationSeconds, 0),
    }),
  );

  return {
    weekStartDate: WEEK,
    weekEndDate: "2026-10-04",
    status: "open",
    revision: 0,
    submittedAt: null,
    totalSeconds: entries.reduce((sum, item) => sum + item.durationSeconds, 0),
    days,
    entries,
    ...overrides,
  };
}

const monthData = {
  month: "2026-09",
  totalSeconds: 6300,
  days: [
    { date: "2026-09-28", totalSeconds: 3600 },
    { date: "2026-09-30", totalSeconds: 2700 },
  ],
  weeks: [
    { weekStartDate: "2026-08-31", status: "open" },
    { weekStartDate: "2026-09-07", status: "open" },
    { weekStartDate: "2026-09-14", status: "open" },
    { weekStartDate: "2026-09-21", status: "open" },
    { weekStartDate: "2026-09-28", status: "open" },
  ],
};

describe("TimesheetPage", () => {
  beforeEach(() => {
    // Só o Date é falso: waitFor/findBy continuam usando timers reais.
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date(2026, 8, 30, 12, 0, 0));

    fetchWeek.mockReset().mockResolvedValue(week());
    fetchMonth.mockReset().mockResolvedValue(monthData);
    submitWeek.mockReset();
    updateEntry.mockReset();
    deleteEntry.mockReset().mockResolvedValue(undefined);
  });

  afterEach(() => {
    cleanup();
    vi.useRealTimers();
  });

  it("abre na semana atual e mostra totais por item, por dia e da semana", async () => {
    render(<TimesheetPage />);

    expect(await screen.findByText("28 set – 04 out 2026")).toBeInTheDocument();
    expect(fetchWeek).toHaveBeenCalledWith(expect.anything(), WEEK);

    const grid = screen.getByRole("table", { name: "Horas por work item e dia" });
    const firstItem = within(grid).getByRole("row", { name: /#15835/ });
    // segunda 01:00 · quarta 00:30 · total do item 01:30
    expect(within(firstItem).getAllByRole("cell").map((cell) => cell.textContent)).toEqual([
      "01:00", "–", "00:30", "–", "–", "–", "–", "01:30",
    ]);
    const footer = within(grid).getByRole("row", { name: /Total do dia/ });
    const footerCells = within(footer).getAllByRole("cell");
    expect(footerCells[footerCells.length - 1]).toHaveTextContent("01:45");
    expect(screen.getByText(/Total da semana/)).toHaveTextContent("01:45");
    expect(screen.getByText("Aberta", { selector: ".summary .badge" })).toBeInTheDocument();
  });

  it("navega entre semanas", async () => {
    render(<TimesheetPage />);
    await screen.findByText("28 set – 04 out 2026");

    fireEvent.click(screen.getByRole("button", { name: "Semana anterior" }));
    await waitFor(() => expect(fetchWeek).toHaveBeenLastCalledWith(expect.anything(), "2026-09-21"));

    fireEvent.click(screen.getByRole("button", { name: "Próxima semana" }));
    fireEvent.click(screen.getByRole("button", { name: "Próxima semana" }));
    await waitFor(() => expect(fetchWeek).toHaveBeenLastCalledWith(expect.anything(), "2026-10-05"));

    fireEvent.click(screen.getByRole("button", { name: "Hoje" }));
    await waitFor(() => expect(fetchWeek).toHaveBeenLastCalledWith(expect.anything(), WEEK));
  });

  it("pede confirmação antes de enviar e mostra a semana como enviada", async () => {
    const submitted = week({ status: "submitted", revision: 1, submittedAt: "2026-09-30T15:00:00Z" });
    submitWeek.mockImplementation(async () => {
      fetchWeek.mockResolvedValue(submitted); // a releitura depois do envio já vem "enviada"
      return submitted;
    });
    render(<TimesheetPage />);
    await screen.findByText("28 set – 04 out 2026");

    fireEvent.click(screen.getByRole("button", { name: "Enviar semana" }));
    expect(submitWeek).not.toHaveBeenCalled();
    expect(screen.getByText(/fica bloqueada para edição/)).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Confirmar envio" }));

    expect(await screen.findByText("Semana enviada para aprovação.")).toBeInTheDocument();
    expect(submitWeek).toHaveBeenCalledWith(expect.anything(), WEEK);
    expect(screen.getByText("Enviada", { selector: ".summary .badge" })).toBeInTheDocument();
  });

  it("cancelar a confirmação não envia", async () => {
    render(<TimesheetPage />);
    await screen.findByText("28 set – 04 out 2026");

    fireEvent.click(screen.getByRole("button", { name: "Enviar semana" }));
    fireEvent.click(screen.getByRole("button", { name: "Cancelar" }));

    expect(submitWeek).not.toHaveBeenCalled();
    expect(screen.getByRole("button", { name: "Enviar semana" })).toBeEnabled();
  });

  it("semana enviada bloqueia envio, edição e exclusão", async () => {
    fetchWeek.mockResolvedValue(week({ status: "submitted", revision: 1, submittedAt: "2026-09-30T15:00:00Z" }));
    render(<TimesheetPage />);
    await screen.findByText("28 set – 04 out 2026");

    expect(screen.getByRole("button", { name: "Enviar semana" })).toBeDisabled();
    expect(screen.queryByRole("button", { name: "Editar" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Excluir" })).not.toBeInTheDocument();
  });

  it("semana rejeitada continua editável e reenviável", async () => {
    fetchWeek.mockResolvedValue(week({ status: "rejected", revision: 1 }));
    render(<TimesheetPage />);
    await screen.findByText("28 set – 04 out 2026");

    expect(screen.getByRole("button", { name: "Enviar semana" })).toBeEnabled();
    expect(screen.getAllByRole("button", { name: "Editar" })).toHaveLength(3);
  });

  it("semana vazia não pode ser enviada", async () => {
    fetchWeek.mockResolvedValue(week({ entries: [] }));
    render(<TimesheetPage />);

    expect((await screen.findAllByText("Nenhum lançamento nesta semana.")).length).toBeGreaterThan(0);
    expect(screen.getByRole("button", { name: "Enviar semana" })).toBeDisabled();
  });

  it("exclui um lançamento e recarrega a semana", async () => {
    render(<TimesheetPage />);
    await screen.findByText("28 set – 04 out 2026");
    const callsBefore = fetchWeek.mock.calls.length;

    fireEvent.click(screen.getAllByRole("button", { name: "Excluir" })[0]);

    await waitFor(() => expect(deleteEntry).toHaveBeenCalledWith(expect.anything(), "1"));
    await waitFor(() => expect(fetchWeek.mock.calls.length).toBeGreaterThan(callsBefore));
  });

  it("edita duração e comentário usando a revisão do lançamento", async () => {
    updateEntry.mockResolvedValue({});
    render(<TimesheetPage />);
    await screen.findByText("28 set – 04 out 2026");

    fireEvent.click(screen.getAllByRole("button", { name: "Editar" })[1]);
    fireEvent.change(screen.getByLabelText("Duração (HH:MM)"), { target: { value: "01:15" } });
    fireEvent.change(screen.getByLabelText("Comentário"), { target: { value: "Planejamento" } });
    fireEvent.click(screen.getByRole("button", { name: "Salvar" }));

    await waitFor(() =>
      expect(updateEntry).toHaveBeenCalledWith(expect.anything(), "2", 1, { durationSeconds: 4500, note: "Planejamento" }),
    );
  });

  it("recusa duração inválida na edição sem chamar a API", async () => {
    render(<TimesheetPage />);
    await screen.findByText("28 set – 04 out 2026");

    fireEvent.click(screen.getAllByRole("button", { name: "Editar" })[0]);
    fireEvent.change(screen.getByLabelText("Duração (HH:MM)"), { target: { value: "abc" } });
    fireEvent.click(screen.getByRole("button", { name: "Salvar" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("formato HH:MM");
    expect(updateEntry).not.toHaveBeenCalled();
  });

  it("mostra o motivo quando o servidor recusa a alteração (ex.: semana já enviada em outra aba)", async () => {
    deleteEntry.mockRejectedValue(new Error("Semana enviada ou aprovada: edição bloqueada."));
    render(<TimesheetPage />);
    await screen.findByText("28 set – 04 out 2026");

    fireEvent.click(screen.getAllByRole("button", { name: "Excluir" })[0]);

    expect(await screen.findByRole("alert")).toHaveTextContent("Semana enviada ou aprovada: edição bloqueada.");
  });

  it("mostra erro de carregamento", async () => {
    fetchWeek.mockRejectedValue(new Error("Falha na chamada à API (/api/me/weeks/2026-09-28): HTTP 500"));
    render(<TimesheetPage />);

    expect(await screen.findByRole("alert")).toHaveTextContent("HTTP 500");
  });

  it("resumo mensal mostra horas por dia e abre a semana do dia clicado", async () => {
    render(<TimesheetPage />);
    await screen.findByText("28 set – 04 out 2026");

    const calendar = await screen.findByRole("table", { name: /Horas por dia em setembro de 2026/ });
    expect(within(calendar).getByRole("button", { name: "Abrir a semana do dia 30" })).toHaveTextContent("00:45");
    expect(screen.getByText(/Total do mês/)).toHaveTextContent("01:45");

    fireEvent.click(within(calendar).getByRole("button", { name: "Abrir a semana do dia 10" }));
    await waitFor(() => expect(fetchWeek).toHaveBeenLastCalledWith(expect.anything(), "2026-09-07"));
  });

  it("navega entre meses no resumo mensal", async () => {
    render(<TimesheetPage />);
    await screen.findByText("28 set – 04 out 2026");

    fireEvent.click(screen.getByRole("button", { name: "Próximo mês" }));

    await waitFor(() => expect(fetchMonth).toHaveBeenLastCalledWith(expect.anything(), "2026-10"));
    expect(await screen.findByText("outubro de 2026")).toBeInTheDocument();
  });
});
