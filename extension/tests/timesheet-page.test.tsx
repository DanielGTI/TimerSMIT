import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("../src/lib/api/config", () => ({
  getApiBaseUrl: () => "https://api.example.test",
}));

const fetchWeek = vi.fn();
const fetchMonth = vi.fn();
const submitWeek = vi.fn();
const recallWeek = vi.fn();
const reopenApproval = vi.fn();
const updateEntry = vi.fn();
const deleteEntry = vi.fn();

vi.mock("../src/lib/api/timesheet", () => ({
  fetchWeek: (...args: unknown[]) => fetchWeek(...args),
  fetchMonth: (...args: unknown[]) => fetchMonth(...args),
  submitWeek: (...args: unknown[]) => submitWeek(...args),
  recallWeek: (...args: unknown[]) => recallWeek(...args),
  updateEntry: (...args: unknown[]) => updateEntry(...args),
  deleteEntry: (...args: unknown[]) => deleteEntry(...args),
}));

const createManualEntry = vi.fn();
const searchWorkItems = vi.fn();
const fetchWorkItemDetails = vi.fn();

vi.mock("../src/lib/api/entries", () => ({
  createManualEntry: (...args: unknown[]) => createManualEntry(...args),
}));
vi.mock("../src/lib/api/approvals", () => ({
  reopenApproval: (...args: unknown[]) => reopenApproval(...args),
}));
vi.mock("../src/lib/api/me", () => ({
  fetchCurrentSession: async () => ({ tenantId: "t", organizationName: "smitbr", memberId: "m", displayName: "Daniel Ferreira" }),
}));
vi.mock("../src/lib/api/activityTypes", () => ({
  fetchActivityTypes: async () => [{ id: "3", name: "Desenvolvimento", color: "#A6D8F5", defaultBillable: false }],
}));
vi.mock("../src/lib/devops/workItemSearch", async (importOriginal) => ({
  ...(await importOriginal<typeof import("../src/lib/devops/workItemSearch")>()),
  searchWorkItems: (...args: unknown[]) => searchWorkItems(...args),
  fetchWorkItemDetails: (...args: unknown[]) => fetchWorkItemDetails(...args),
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
    startTime: null,
    endTime: null,
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
    submissionId: null,
    canReopen: false,
    decisions: [],
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
    recallWeek.mockReset();
    reopenApproval.mockReset();
    updateEntry.mockReset();
    deleteEntry.mockReset().mockResolvedValue(undefined);
    createManualEntry.mockReset().mockResolvedValue({});
    searchWorkItems.mockReset().mockResolvedValue([]);
    fetchWorkItemDetails.mockReset();
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

  it("mostra as horas adicionais a validar no lançamento e no total da semana", async () => {
    fetchWeek.mockResolvedValue(
      week({
        additionalTotals: { seconds: 7200, weightedSeconds: 10800, pendingSeconds: 7200 },
        entries: [
          entry({
            id: "9",
            localDate: "2026-09-29",
            durationSeconds: 10800,
            startTime: "17:00",
            endTime: "20:00",
            additional: { seconds: 7200, weightedSeconds: 10800, nightSeconds: 0, dayType: "weekday", status: "pending", denied: null },
          }),
          entry({
            id: "10",
            localDate: "2026-09-30",
            additional: {
              seconds: 3600,
              weightedSeconds: 3600,
              nightSeconds: 0,
              dayType: "weekday",
              status: "bank",
              denied: { reason: "Sem pedido prévio" },
            },
          }),
        ],
      }),
    );
    render(<TimesheetPage />);

    expect(await screen.findByText("Horas adicionais a validar: 02:00 → 03:00")).toBeInTheDocument();
    expect(screen.getByText("Banco de horas: 01:00")).toBeInTheDocument();
    expect(screen.getByText("Não autorizada pelo aprovador: Sem pedido prévio")).toBeInTheDocument();
    expect(screen.getByText(/Horas adicionais:/)).toHaveTextContent("Horas adicionais: 03:00 (02:00 trabalhadas, 02:00 a validar)");
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

  it("semana rejeitada mostra quem rejeitou, o motivo e o histórico", async () => {
    fetchWeek.mockResolvedValue(
      week({
        status: "rejected",
        revision: 1,
        decisions: [
          {
            revision: 1,
            decision: "rejected",
            reason: "Faltou o dia 29",
            approverName: "Ana Aprovadora",
            selfDecision: false,
            decidedAt: "2026-10-01T12:00:00Z",
          },
        ],
      }),
    );
    render(<TimesheetPage />);
    await screen.findByText("28 set – 04 out 2026");

    const banner = screen.getByText(/Semana rejeitada por Ana Aprovadora/);
    expect(banner).toHaveTextContent("Faltou o dia 29");
    expect(banner).toHaveTextContent("Corrija os lançamentos e envie novamente");
    const history = screen.getByRole("list", { name: "Histórico de decisões" });
    expect(within(history).getByText("Rejeitada")).toBeInTheDocument();
  });

  it("semana aprovada mostra o aviso de aprovação", async () => {
    fetchWeek.mockResolvedValue(
      week({
        status: "approved",
        revision: 1,
        decisions: [
          { revision: 1, decision: "approved", reason: null, approverName: "Ana Aprovadora", selfDecision: false, decidedAt: "2026-10-01T12:00:00Z" },
        ],
      }),
    );
    render(<TimesheetPage />);
    await screen.findByText("28 set – 04 out 2026");

    expect(screen.getByText(/Semana aprovada por Ana Aprovadora/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Editar" })).not.toBeInTheDocument();
  });

  it("semana reaberta explica o motivo e volta a ser editável", async () => {
    fetchWeek.mockResolvedValue(
      week({
        status: "open",
        revision: 1,
        decisions: [
          { revision: 1, decision: "approved", reason: null, approverName: "Ana", selfDecision: false, decidedAt: "2026-10-01T12:00:00Z" },
          { revision: 1, decision: "reopened", reason: "Faltou o deploy", approverName: "Admin", selfDecision: false, decidedAt: "2026-10-02T12:00:00Z" },
        ],
      }),
    );
    render(<TimesheetPage />);
    await screen.findByText("28 set – 04 out 2026");

    expect(screen.getByText(/Semana reaberta por Admin/)).toHaveTextContent("Faltou o deploy");
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

  it("mostra o horário do lançamento e permite editar ou apagar o início", async () => {
    updateEntry.mockResolvedValue({});
    fetchWeek.mockResolvedValue(
      week({ entries: [entry({ id: "7", durationSeconds: 5400, startTime: "09:00", endTime: "10:30" })] }),
    );
    render(<TimesheetPage />);
    await screen.findByText("28 set – 04 out 2026");

    expect(screen.getByText("09:00–10:30")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Editar" }));
    expect(screen.getByLabelText("Início (opcional)")).toHaveValue("09:00");
    fireEvent.change(screen.getByLabelText("Início (opcional)"), { target: { value: "14:00" } });
    fireEvent.click(screen.getByRole("button", { name: "Salvar" }));
    await waitFor(() =>
      expect(updateEntry).toHaveBeenCalledWith(expect.anything(), "7", 1, { durationSeconds: 5400, note: "", startTime: "14:00" }),
    );

    updateEntry.mockClear();
    fireEvent.click(screen.getByRole("button", { name: "Editar" }));
    fireEvent.change(screen.getByLabelText("Início (opcional)"), { target: { value: "" } });
    fireEvent.click(screen.getByRole("button", { name: "Salvar" }));
    await waitFor(() =>
      expect(updateEntry).toHaveBeenCalledWith(expect.anything(), "7", 1, { durationSeconds: 5400, note: "", startTime: null }),
    );
  });

  it("não envia o início quando ele não mudou", async () => {
    updateEntry.mockResolvedValue({});
    render(<TimesheetPage />);
    await screen.findByText("28 set – 04 out 2026");

    fireEvent.click(screen.getAllByRole("button", { name: "Editar" })[1]);
    fireEvent.click(screen.getByRole("button", { name: "Salvar" }));

    await waitFor(() => expect(updateEntry).toHaveBeenCalled());
    expect(updateEntry.mock.calls[0][3]).not.toHaveProperty("startTime");
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

  describe("adicionar tempo pela data", () => {
    const hit = { id: 15596, title: "(Reunião)(Alinhamento) Com o cliente 07/08/2026", workItemType: "Task", projectName: "McCain", state: "Active" };
    const details = {
      ...hit,
      projectId: "guid-mccain",
      iterationPath: "McCain\\Sprint 8",
      parent: { id: 15550, title: "08 Agosto 2026 Suporte ao Cliente", workItemType: "User Story" },
      webUrl: "https://dev.azure.com/smitbr/McCain/_workitems/edit/15596",
    };

    async function openFromDay(day: string) {
      render(<TimesheetPage />);
      await screen.findByText("28 set – 04 out 2026");
      const calendar = await screen.findByRole("table", { name: /Horas por dia em setembro de 2026/ });
      fireEvent.click(within(calendar).getByRole("button", { name: `Adicionar tempo em ${day}` }));
      return screen.findByRole("dialog", { name: "Adicionar tempo" });
    }

    it("o + do dia abre o painel com a data, busca o work item e lança nele", async () => {
      searchWorkItems.mockResolvedValue([hit, { ...hit, id: 15560, title: "Outro" }]);
      fetchWorkItemDetails.mockResolvedValue(details);
      const dialog = await openFromDay("15/09");

      expect(await within(dialog).findByText("Daniel Ferreira")).toBeInTheDocument();
      expect((within(dialog).getByLabelText("Data") as HTMLInputElement).value).toBe("2026-09-15");
      expect(within(dialog).getByRole("button", { name: "Salvar" })).toBeDisabled();

      fireEvent.change(within(dialog).getByRole("combobox", { name: "Work item" }), { target: { value: "155" } });
      await waitFor(() => expect(searchWorkItems).toHaveBeenLastCalledWith("155"));
      fireEvent.click(await within(dialog).findByRole("option", { name: /#15596/ }));

      // Escolhido: o chip mostra o item; o detalhe traz projeto, pai e título inteiro.
      const tooltip = await within(dialog).findByRole("tooltip");
      expect(fetchWorkItemDetails).toHaveBeenCalledWith(15596);
      expect(tooltip).toHaveTextContent("McCain / … / #15550 08 Agosto 2026 Suporte ao Cliente");
      expect(tooltip).toHaveTextContent("#15596 (Reunião)(Alinhamento) Com o cliente 07/08/2026");
      expect(within(dialog).getByRole("link", { name: "#15596" })).toHaveAttribute("href", details.webUrl);

      fireEvent.change(within(dialog).getByLabelText("Duração"), { target: { value: "01:30" } });
      fireEvent.click(within(dialog).getByRole("button", { name: "Salvar" }));

      await waitFor(() => expect(createManualEntry).toHaveBeenCalledTimes(1));
      expect(createManualEntry.mock.calls[0][1]).toMatchObject({
        projectId: "guid-mccain",
        projectName: "McCain",
        workItemId: 15596,
        localDate: "2026-09-15",
        durationSeconds: 5400,
        title: hit.title,
        workItemType: "Task",
        iterationPath: "McCain\\Sprint 8",
      });

      expect(await screen.findByText("Lançamento de 01:30 registrado em 15/09.")).toBeInTheDocument();
      expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
      await waitFor(() => expect(fetchWeek).toHaveBeenLastCalledWith(expect.anything(), "2026-09-14"));
    });

    it("trocar o work item volta para a busca", async () => {
      searchWorkItems.mockResolvedValue([hit]);
      fetchWorkItemDetails.mockResolvedValue(details);
      const dialog = await openFromDay("30/09");

      fireEvent.focus(await within(dialog).findByRole("combobox", { name: "Work item" }));
      fireEvent.click(await within(dialog).findByRole("option", { name: /#15596/ }));
      fireEvent.click(await within(dialog).findByRole("button", { name: "Trocar o work item" }));

      expect(within(dialog).getByRole("combobox", { name: "Work item" })).toHaveValue("");
      expect(within(dialog).getByRole("button", { name: "Salvar" })).toBeDisabled();
    });

    it("item sem projeto identificado não é escolhido", async () => {
      searchWorkItems.mockResolvedValue([hit]);
      fetchWorkItemDetails.mockResolvedValue({ ...details, projectId: null });
      const dialog = await openFromDay("30/09");

      fireEvent.focus(await within(dialog).findByRole("combobox", { name: "Work item" }));
      fireEvent.click(await within(dialog).findByRole("option", { name: /#15596/ }));

      expect(await within(dialog).findByText(/Não foi possível identificar o projeto do #15596/)).toBeInTheDocument();
      expect(within(dialog).getByRole("button", { name: "Salvar" })).toBeDisabled();
    });

    it("Cancelar e Esc fecham sem lançar", async () => {
      const dialog = await openFromDay("30/09");
      fireEvent.click(await within(dialog).findByRole("button", { name: "Cancelar" }));
      expect(screen.queryByRole("dialog")).not.toBeInTheDocument();

      fireEvent.click(screen.getByRole("button", { name: "+ Adicionar tempo" }));
      const again = await screen.findByRole("dialog", { name: "Adicionar tempo" });
      // Sem dia escolhido, vale hoje (a semana aberta é a atual).
      expect((await within(again).findByLabelText("Data") as HTMLInputElement).value).toBe("2026-09-30");
      fireEvent.keyDown(document, { key: "Escape" });
      expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
      expect(createManualEntry).not.toHaveBeenCalled();
    });

    it("dias de outro mês na linha (ex.: outubro na visão de setembro) também têm + e abrem a semana", async () => {
      fetchWeek.mockResolvedValue(
        week({
          days: week().days.map((day) => (day.date === "2026-10-01" ? { ...day, totalSeconds: 7200 } : day)),
        }),
      );
      render(<TimesheetPage />);
      await screen.findByText("28 set – 04 out 2026");
      const calendar = await screen.findByRole("table", { name: /Horas por dia em setembro de 2026/ });

      // Horas de fora do mês vêm da semana aberta na folha.
      expect(within(calendar).getByRole("button", { name: "Abrir a semana do dia 01/10" })).toHaveTextContent("02:00");
      expect(within(calendar).getByRole("button", { name: "Adicionar tempo em 31/08" })).toBeInTheDocument();

      fireEvent.click(within(calendar).getByRole("button", { name: "Adicionar tempo em 02/10" }));
      const dialog = await screen.findByRole("dialog", { name: "Adicionar tempo" });
      expect(((await within(dialog).findByLabelText("Data")) as HTMLInputElement).value).toBe("2026-10-02");
    });

    function submittedMonth(status: "submitted" | "approved") {
      return {
        ...monthData,
        weeks: monthData.weeks.map((item) => (item.weekStartDate === WEEK ? { ...item, status } : item)),
      };
    }
    const submitted = () => week({ status: "submitted", revision: 1, submittedAt: "2026-09-30T15:00:00Z" });

    it("+ em semana enviada pede para cancelar o envio e então abre o lançamento", async () => {
      fetchWeek.mockResolvedValue(submitted());
      fetchMonth.mockResolvedValue(submittedMonth("submitted"));
      recallWeek.mockImplementation(async () => {
        fetchWeek.mockResolvedValue(week());
        return week();
      });
      render(<TimesheetPage />);
      await screen.findByText("Enviada", { selector: ".summary .badge" });
      const calendar = await screen.findByRole("table", { name: /Horas por dia em setembro de 2026/ });

      fireEvent.click(await within(calendar).findByRole("button", { name: "Adicionar tempo em 02/10" }));

      // Ainda não cancelou nem abriu o painel: primeiro a confirmação.
      const confirm = screen.getByRole("group", { name: "Confirmar cancelamento do envio" });
      expect(confirm).toHaveTextContent("Esta semana já foi enviada");
      expect(recallWeek).not.toHaveBeenCalled();
      expect(screen.queryByRole("dialog")).not.toBeInTheDocument();

      fireEvent.click(within(confirm).getByRole("button", { name: "Cancelar envio e lançar" }));

      await waitFor(() => expect(recallWeek).toHaveBeenCalledWith(expect.anything(), WEEK));
      const dialog = await screen.findByRole("dialog", { name: "Adicionar tempo" });
      expect(((await within(dialog).findByLabelText("Data")) as HTMLInputElement).value).toBe("2026-10-02");
      expect(screen.getByText(/Envio cancelado/)).toBeInTheDocument();
      expect(screen.getByText("Aberta", { selector: ".summary .badge" })).toBeInTheDocument();
    });

    it("Voltar não cancela o envio", async () => {
      fetchWeek.mockResolvedValue(submitted());
      fetchMonth.mockResolvedValue(submittedMonth("submitted"));
      render(<TimesheetPage />);
      const calendar = await screen.findByRole("table", { name: /Horas por dia em setembro de 2026/ });

      fireEvent.click(await within(calendar).findByRole("button", { name: "Adicionar tempo em 30/09" }));
      fireEvent.click(screen.getByRole("button", { name: "Voltar" }));

      expect(recallWeek).not.toHaveBeenCalled();
      expect(screen.queryByRole("group", { name: "Confirmar cancelamento do envio" })).not.toBeInTheDocument();
    });

    it("semana enviada tem o botão Cancelar envio; erro do servidor aparece", async () => {
      fetchWeek.mockResolvedValue(submitted());
      recallWeek.mockRejectedValue(new Error("Esta semana não está enviada; não há envio para cancelar."));
      render(<TimesheetPage />);
      await screen.findByText("Enviada", { selector: ".summary .badge" });
      expect(screen.queryByRole("button", { name: "+ Adicionar tempo" })).not.toBeInTheDocument();

      fireEvent.click(screen.getByRole("button", { name: "Cancelar envio" }));
      const confirm = screen.getByRole("group", { name: "Confirmar cancelamento do envio" });
      expect(confirm).toHaveTextContent("Cancelar o envio?");
      fireEvent.click(within(confirm).getByRole("button", { name: "Cancelar envio" }));

      expect(await screen.findByRole("alert")).toHaveTextContent("não há envio para cancelar");
      expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    });

    const approved = (canReopen: boolean) =>
      week({ status: "approved", revision: 1, submittedAt: "2026-09-30T15:00:00Z", submissionId: "77", canReopen });

    it("+ em semana aprovada: administrador reabre com justificativa e então lança", async () => {
      fetchWeek.mockResolvedValue(approved(true));
      fetchMonth.mockResolvedValue(submittedMonth("approved"));
      reopenApproval.mockImplementation(async () => {
        fetchWeek.mockResolvedValue(week());
        return {};
      });
      render(<TimesheetPage />);
      await screen.findByText("Aprovada", { selector: ".summary .badge" });
      const calendar = await screen.findByRole("table", { name: /Horas por dia em setembro de 2026/ });

      fireEvent.click(await within(calendar).findByRole("button", { name: "Adicionar tempo em 02/10" }));

      const box = await screen.findByRole("group", { name: "Reabrir semana aprovada" });
      const confirm = within(box).getByRole("button", { name: "Reabrir e lançar" });
      expect(confirm).toBeDisabled(); // sem justificativa não reabre
      fireEvent.change(within(box).getByLabelText(/Justificativa/), { target: { value: "  Esqueci de lançar hoje  " } });
      fireEvent.click(confirm);

      await waitFor(() => expect(reopenApproval).toHaveBeenCalledWith(expect.anything(), "77", "Esqueci de lançar hoje"));
      const dialog = await screen.findByRole("dialog", { name: "Adicionar tempo" });
      expect(((await within(dialog).findByLabelText("Data")) as HTMLInputElement).value).toBe("2026-10-02");
      expect(screen.getByText(/Semana reaberta/)).toBeInTheDocument();
    });

    it("+ em semana aprovada: quem não é administrador só recebe o aviso", async () => {
      fetchWeek.mockResolvedValue(approved(false));
      fetchMonth.mockResolvedValue(submittedMonth("approved"));
      render(<TimesheetPage />);
      await screen.findByText("Aprovada", { selector: ".summary .badge" });
      const calendar = await screen.findByRole("table", { name: /Horas por dia em setembro de 2026/ });

      fireEvent.click(await within(calendar).findByRole("button", { name: "Adicionar tempo em 30/09" }));

      const box = await screen.findByRole("group", { name: "Reabrir semana aprovada" });
      expect(box).toHaveTextContent("Peça a um administrador para reabri-la");
      expect(within(box).queryByLabelText(/Justificativa/)).not.toBeInTheDocument();
      expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
      expect(reopenApproval).not.toHaveBeenCalled();

      fireEvent.click(within(box).getByRole("button", { name: "Entendi" }));
      expect(screen.queryByRole("group", { name: "Reabrir semana aprovada" })).not.toBeInTheDocument();
    });

    it("administrador tem o botão Reabrir semana na folha aprovada; quem não é, não", async () => {
      fetchWeek.mockResolvedValue(approved(true));
      const { unmount } = render(<TimesheetPage />);
      await screen.findByText("Aprovada", { selector: ".summary .badge" });
      fireEvent.click(screen.getByRole("button", { name: "Reabrir semana" }));
      expect(screen.getByRole("button", { name: "Reabrir semana" })).toBeInTheDocument();
      expect(screen.getByRole("group", { name: "Reabrir semana aprovada" })).toHaveTextContent("Para alterar");
      unmount();

      fetchWeek.mockResolvedValue(approved(false));
      render(<TimesheetPage />);
      await screen.findByText("Aprovada", { selector: ".summary .badge" });
      expect(screen.queryByRole("button", { name: "Reabrir semana" })).not.toBeInTheDocument();
      expect(screen.queryByRole("button", { name: "+ Adicionar tempo" })).not.toBeInTheDocument();
    });

    it("a semana aberta no calendário não tem mais o destaque de bordas azuis", async () => {
      const { container } = render(<TimesheetPage />);
      await screen.findByRole("table", { name: /Horas por dia em setembro de 2026/ });

      expect(container.querySelector(".calendar .is-selected")).toBeNull();
    });
  });
});
