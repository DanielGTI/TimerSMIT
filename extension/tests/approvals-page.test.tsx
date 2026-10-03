import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("../src/lib/api/config", () => ({
  getApiBaseUrl: () => "https://api.example.test",
}));

const fetchPendingApprovals = vi.fn();
const fetchDecidedApprovals = vi.fn();
const fetchApproval = vi.fn();
const decideApproval = vi.fn();
const reopenApproval = vi.fn();

vi.mock("../src/lib/api/approvals", () => ({
  fetchPendingApprovals: (...args: unknown[]) => fetchPendingApprovals(...args),
  fetchDecidedApprovals: (...args: unknown[]) => fetchDecidedApprovals(...args),
  fetchApproval: (...args: unknown[]) => fetchApproval(...args),
  decideApproval: (...args: unknown[]) => decideApproval(...args),
  reopenApproval: (...args: unknown[]) => reopenApproval(...args),
}));

const fetchPendingOvertime = vi.fn();
const decideOvertime = vi.fn();
vi.mock("../src/lib/api/overtime", async (importOriginal) => ({
  ...(await importOriginal<typeof import("../src/lib/api/overtime")>()),
  fetchPendingOvertime: (...args: unknown[]) => fetchPendingOvertime(...args),
  decideOvertime: (...args: unknown[]) => decideOvertime(...args),
}));

import type { ApprovalDetailDto } from "../src/lib/api/approvals";
import type { OvertimeItemDto } from "../src/lib/api/overtime";
import type { WeekDto } from "../src/lib/api/timesheet";
import { ApprovalsPage } from "../src/pages/approvals/ApprovalsPage";

const pendingItem = {
  id: "7",
  weekStartDate: "2026-09-28",
  status: "submitted",
  revision: 1,
  submittedAt: "2026-10-02T20:00:00Z",
  totalSeconds: 5400,
  entryCount: 2,
  submitter: { id: "3", displayName: "Carla Colaboradora" },
  ownWeek: false,
};

const week: WeekDto = {
  weekStartDate: "2026-09-28",
  weekEndDate: "2026-10-04",
  status: "submitted",
  revision: 1,
  submissionId: null,
  canReopen: false,
  submittedAt: "2026-10-02T20:00:00Z",
  totalSeconds: 5400,
  decisions: [],
  days: ["2026-09-28", "2026-09-29", "2026-09-30", "2026-10-01", "2026-10-02", "2026-10-03", "2026-10-04"].map((date) => ({
    date,
    totalSeconds: date === "2026-09-28" ? 5400 : 0,
  })),
  entries: [
    {
      id: "1",
      workItemId: 15835,
      localDate: "2026-09-28",
      timezone: "America/Sao_Paulo",
      durationSeconds: 5400,
      startTime: null,
      endTime: null,
      source: "manual",
      billable: false,
      activityTypeId: "3",
      note: "Planejamento",
      revision: 1,
      projectId: "p1",
      projectName: "SMIT LEARN IA",
      workItemTitle: "Teste Tracker",
      workItemType: "Task",
      activityTypeName: "Desenvolvimento",
      activityTypeColor: "#A6D8F5",
    },
  ],
};

function detail(overrides: Partial<ApprovalDetailDto> = {}): ApprovalDetailDto {
  return {
    submission: {
      id: "7",
      weekStartDate: "2026-09-28",
      status: "submitted",
      revision: 1,
      submittedAt: "2026-10-02T20:00:00Z",
      submitter: { id: "3", displayName: "Carla Colaboradora" },
    },
    week,
    permissions: { canDecide: true, canReopen: false, ownWeek: false },
    ...overrides,
  };
}

async function openFirstWeek() {
  render(<ApprovalsPage />);
  fireEvent.click(await screen.findByRole("button", { name: /Carla Colaboradora/ }));
  await screen.findByText("Revisão 1 · enviada em", { exact: false });
}

describe("ApprovalsPage", () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date(2026, 9, 5, 12, 0, 0));

    fetchPendingApprovals.mockReset().mockResolvedValue([pendingItem]);
    fetchDecidedApprovals.mockReset().mockResolvedValue([]);
    fetchApproval.mockReset().mockResolvedValue(detail());
    fetchPendingOvertime.mockReset().mockResolvedValue([]);
    decideOvertime.mockReset();
    decideApproval.mockReset();
    reopenApproval.mockReset();
  });

  afterEach(() => {
    cleanup();
    vi.useRealTimers();
  });

  it("lista as semanas pendentes com quem enviou, período e horas", async () => {
    render(<ApprovalsPage />);

    const item = await screen.findByRole("button", { name: /Carla Colaboradora/ });
    expect(item).toHaveTextContent("28 set – 04 out 2026");
    expect(item).toHaveTextContent("01:30");
    expect(item).toHaveTextContent("2 lançamentos");
    expect(screen.getByRole("tab", { name: "Pendentes (1)" })).toHaveAttribute("aria-selected", "true");
  });

  it("mostra o estado vazio quando não há nada para aprovar", async () => {
    fetchPendingApprovals.mockResolvedValue([]);
    render(<ApprovalsPage />);

    expect(await screen.findByText("Nenhuma semana aguardando sua aprovação.")).toBeInTheDocument();
  });

  it("indica quando é um reenvio", async () => {
    fetchPendingApprovals.mockResolvedValue([{ ...pendingItem, revision: 2 }]);
    render(<ApprovalsPage />);

    expect(await screen.findByRole("button", { name: /reenvio \(revisão 2\)/ })).toBeInTheDocument();
  });

  it("abre o detalhe com a grade, os lançamentos e as ações permitidas", async () => {
    await openFirstWeek();

    expect(screen.getByRole("table", { name: "Horas por work item e dia" })).toBeInTheDocument();
    expect(screen.getByText("Planejamento")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Aprovar semana" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Rejeitar" })).toBeInTheDocument();
    // Revisor nunca edita lançamento do colaborador.
    expect(screen.queryByRole("button", { name: "Editar" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Excluir" })).not.toBeInTheDocument();
  });

  it("aprova depois de confirmar, enviando a revisão vista", async () => {
    const approved = detail({
      submission: { ...detail().submission, status: "approved" },
      permissions: { canDecide: false, canReopen: false, ownWeek: false },
    });
    decideApproval.mockImplementation(async () => {
      fetchApproval.mockResolvedValue(approved); // a releitura depois da decisão já vem "aprovada"
      return approved;
    });
    await openFirstWeek();

    fireEvent.click(screen.getByRole("button", { name: "Aprovar semana" }));
    expect(decideApproval).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole("button", { name: "Confirmar aprovação" }));

    expect(await screen.findByText("Semana aprovada.")).toBeInTheDocument();
    expect(decideApproval).toHaveBeenCalledWith(expect.anything(), "7", { decision: "approve", revision: 1 });
    await waitFor(() => expect(screen.queryByRole("button", { name: "Aprovar semana" })).not.toBeInTheDocument());
  });

  it("rejeitar exige o motivo e o envia", async () => {
    const rejected = detail({
      submission: { ...detail().submission, status: "rejected" },
      permissions: { canDecide: false, canReopen: false, ownWeek: false },
    });
    decideApproval.mockImplementation(async () => {
      fetchApproval.mockResolvedValue(rejected);
      return rejected;
    });
    await openFirstWeek();

    fireEvent.click(screen.getByRole("button", { name: "Rejeitar" }));
    fireEvent.click(screen.getByRole("button", { name: "Confirmar rejeição" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("Informe o motivo da rejeição.");
    expect(decideApproval).not.toHaveBeenCalled();

    // O painel continua aberto: basta preencher o motivo e confirmar.
    fireEvent.change(screen.getByLabelText(/Motivo da rejeição/), { target: { value: "  Faltou o dia 29  " } });
    fireEvent.click(screen.getByRole("button", { name: "Confirmar rejeição" }));

    await waitFor(() =>
      expect(decideApproval).toHaveBeenCalledWith(expect.anything(), "7", { decision: "reject", revision: 1, reason: "Faltou o dia 29" }),
    );
    expect(await screen.findByText(/Semana rejeitada/)).toBeInTheDocument();
  });

  it("sem permissão de decidir não mostra ações", async () => {
    fetchApproval.mockResolvedValue(detail({ permissions: { canDecide: false, canReopen: false, ownWeek: false } }));
    await openFirstWeek();

    expect(screen.queryByRole("button", { name: "Aprovar semana" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Rejeitar" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Reabrir semana" })).not.toBeInTheDocument();
  });

  it("avisa quando o administrador está decidindo a própria semana", async () => {
    fetchApproval.mockResolvedValue(detail({ permissions: { canDecide: true, canReopen: false, ownWeek: true } }));
    await openFirstWeek();

    expect(screen.getByText(/Esta é a sua própria semana/)).toBeInTheDocument();
  });

  it("administrador reabre uma semana aprovada com justificativa", async () => {
    const approved = detail({
      submission: { ...detail().submission, status: "approved" },
      permissions: { canDecide: false, canReopen: true, ownWeek: false },
    });
    fetchApproval.mockResolvedValue(approved);
    reopenApproval.mockResolvedValue(detail({ submission: { ...detail().submission, status: "open" }, permissions: { canDecide: false, canReopen: false, ownWeek: false } }));
    await openFirstWeek();

    fireEvent.click(screen.getByRole("button", { name: "Reabrir semana" }));
    fireEvent.click(screen.getByRole("button", { name: "Confirmar reabertura" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("Informe a justificativa da reabertura.");
    expect(reopenApproval).not.toHaveBeenCalled();

    fireEvent.change(screen.getByLabelText(/Justificativa da reabertura/), { target: { value: "Faltou o deploy" } });
    fireEvent.click(screen.getByRole("button", { name: "Confirmar reabertura" }));

    await waitFor(() => expect(reopenApproval).toHaveBeenCalledWith(expect.anything(), "7", "Faltou o deploy"));
    expect(await screen.findByText("Semana reaberta para edição.")).toBeInTheDocument();
  });

  it("mostra o motivo do servidor quando a decisão é recusada (ex.: outra pessoa decidiu antes)", async () => {
    decideApproval.mockRejectedValue(new Error("Esta semana já foi decidida ou não está aguardando aprovação."));
    await openFirstWeek();

    fireEvent.click(screen.getByRole("button", { name: "Aprovar semana" }));
    fireEvent.click(screen.getByRole("button", { name: "Confirmar aprovação" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("já foi decidida");
    // A lista é recarregada para refletir o que o servidor tem.
    await waitFor(() => expect(fetchPendingApprovals.mock.calls.length).toBeGreaterThan(1));
  });

  it("aba de decididas lista o que eu aprovei ou rejeitei", async () => {
    fetchDecidedApprovals.mockResolvedValue([
      {
        id: "9",
        weekStartDate: "2026-09-21",
        status: "rejected",
        decision: "rejected",
        reason: "Faltou o dia 29",
        revision: 1,
        decidedAt: "2026-09-29T12:00:00Z",
        submitter: { id: "4", displayName: "Davi Desenvolvedor" },
      },
    ]);
    render(<ApprovalsPage />);
    await screen.findByRole("button", { name: /Carla Colaboradora/ });

    fireEvent.click(screen.getByRole("tab", { name: "Decididas por mim" }));

    const item = await screen.findByRole("button", { name: /Davi Desenvolvedor/ });
    expect(item).toHaveTextContent("Rejeitada em");
    expect(screen.queryByRole("button", { name: /Carla Colaboradora/ })).not.toBeInTheDocument();
  });

  it("mostra erro de carregamento", async () => {
    fetchPendingApprovals.mockRejectedValue(new Error("Falha na chamada à API (/api/approvals): HTTP 500"));
    render(<ApprovalsPage />);

    expect(await screen.findByRole("alert")).toHaveTextContent("HTTP 500");
  });

  it("o histórico de decisões aparece no detalhe", async () => {
    fetchApproval.mockResolvedValue(
      detail({
        week: {
          ...week,
          decisions: [
            { revision: 1, decision: "rejected", reason: "Faltou o dia 29", approverName: "Ana", selfDecision: false, decidedAt: "2026-10-01T12:00:00Z" },
          ],
        },
      }),
    );
    await openFirstWeek();

    const history = screen.getByRole("list", { name: "Histórico de decisões" });
    expect(within(history).getByText(/Faltou o dia 29/)).toBeInTheDocument();
  });

  it("hora extra a confirmar da semana bloqueia a aprovação até ser decidida", async () => {
    const confirmation: OvertimeItemDto = {
      id: "40",
      kind: "confirmation",
      dateFrom: "2026-09-29",
      dateTo: "2026-09-29",
      secondsPerDay: 5400,
      startTime: "18:00",
      endTime: "19:30",
      reason: "Deploy",
      suggestedDestination: null,
      afterTheFact: true,
      status: "pending",
      approvedSecondsPerDay: null,
      decidedBy: null,
      decidedAt: null,
      decisionNote: null,
      projectName: "SMIT LEARN IA",
      workItemId: 15835,
      note: null,
      createdAt: null,
    };
    fetchApproval.mockResolvedValue(detail({ week: { ...week, overtimeConfirmations: [confirmation] } }));
    decideOvertime.mockResolvedValue({ ...confirmation, status: "approved" });
    await openFirstWeek();

    const block = screen.getByRole("region", { name: "Horas extras a confirmar da semana" });
    expect(block).toHaveTextContent("18:00–19:30");
    fireEvent.click(screen.getByRole("button", { name: "Aprovar semana" }));
    expect(screen.getByRole("note")).toHaveTextContent("Há 1 hora(s) extra(s) a confirmar nesta semana");
    expect(screen.getByRole("button", { name: "Confirmar aprovação" })).toBeDisabled();

    fireEvent.click(within(block).getByRole("button", { name: "Confirmar" }));
    await waitFor(() => expect(decideOvertime).toHaveBeenCalledWith(expect.anything(), "40", { approve: true }));
  });

  it("a aba Horas extras lista o que espera a decisão de quem está logado", async () => {
    fetchPendingOvertime.mockResolvedValue([
      {
        id: "41",
        kind: "request",
        dateFrom: "2026-10-06",
        dateTo: "2026-10-08",
        secondsPerDay: 7200,
        startTime: null,
        endTime: null,
        reason: "Virada da release",
        suggestedDestination: "bank",
        afterTheFact: false,
        status: "pending",
        approvedSecondsPerDay: null,
        decidedBy: null,
        decidedAt: null,
        decisionNote: null,
        projectName: null,
        workItemId: null,
        note: null,
        createdAt: null,
        person: { id: "3", displayName: "Carla Colaboradora" },
        weekStatus: null,
      },
    ]);
    render(<ApprovalsPage />);

    fireEvent.click(await screen.findByRole("tab", { name: "Horas extras (1)" }));
    const list = screen.getByRole("list", { name: "Horas extras para decidir" });
    expect(list).toHaveTextContent("Carla Colaboradora");
    expect(list).toHaveTextContent("06/10/2026 a 08/10/2026");
    expect(list).toHaveTextContent("Sugestão: Banco de horas");
    expect(within(list).getByRole("button", { name: "Aprovar" })).toBeEnabled();
  });
});
