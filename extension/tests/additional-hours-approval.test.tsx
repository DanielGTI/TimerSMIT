import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("../src/lib/api/config", () => ({
  getApiBaseUrl: () => "https://api.example.test",
}));

const fetchApproval = vi.fn();
const decideApproval = vi.fn();

vi.mock("../src/lib/api/approvals", () => ({
  fetchPendingApprovals: async () => [
    {
      id: "7",
      weekStartDate: "2026-09-28",
      status: "submitted",
      revision: 1,
      submittedAt: "2026-10-02T20:00:00Z",
      totalSeconds: 25200,
      entryCount: 2,
      submitter: { id: "3", displayName: "Carla Colaboradora" },
      ownWeek: false,
    },
  ],
  fetchDecidedApprovals: async () => [],
  fetchApproval: (...args: unknown[]) => fetchApproval(...args),
  decideApproval: (...args: unknown[]) => decideApproval(...args),
  reopenApproval: vi.fn(),
}));

import type { ApprovalDetailDto } from "../src/lib/api/approvals";
import type { WeekEntryDto } from "../src/lib/api/timesheet";
import { ApprovalsPage } from "../src/pages/approvals/ApprovalsPage";

const base = {
  timezone: "America/Sao_Paulo",
  source: "manual" as const,
  billable: false,
  activityTypeId: null,
  note: null,
  revision: 1,
  projectId: "p1",
  projectName: "McCain",
  workItemType: "Task",
  activityTypeName: null,
  activityTypeColor: null,
};

const entries: WeekEntryDto[] = [
  {
    ...base,
    id: "1",
    workItemId: 15596,
    workItemTitle: "Alinhamento",
    localDate: "2026-09-29",
    durationSeconds: 10800,
    startTime: "17:00",
    endTime: "20:00",
    additional: { seconds: 7200, weightedSeconds: 10800, nightSeconds: 0, dayType: "weekday", status: "pending", denied: null },
  },
  {
    ...base,
    id: "2",
    workItemId: 15600,
    workItemTitle: "Suporte",
    localDate: "2026-10-03",
    durationSeconds: 14400,
    startTime: "09:00",
    endTime: "13:00",
    additional: { seconds: 14400, weightedSeconds: 21600, nightSeconds: 0, dayType: "saturday", status: "pending", denied: null },
  },
];

function detail(): ApprovalDetailDto {
  return {
    submission: {
      id: "7",
      weekStartDate: "2026-09-28",
      status: "submitted",
      revision: 1,
      submittedAt: "2026-10-02T20:00:00Z",
      submitter: { id: "3", displayName: "Carla Colaboradora" },
    },
    week: {
      weekStartDate: "2026-09-28",
      weekEndDate: "2026-10-04",
      status: "submitted",
      revision: 1,
      submissionId: "7",
      canReopen: false,
      submittedAt: "2026-10-02T20:00:00Z",
      totalSeconds: 25200,
      additionalTotals: { seconds: 21600, weightedSeconds: 32400, pendingSeconds: 21600 },
      decisions: [],
      days: ["2026-09-28", "2026-09-29", "2026-09-30", "2026-10-01", "2026-10-02", "2026-10-03", "2026-10-04"].map((date) => ({
        date,
        totalSeconds: entries.filter((entry) => entry.localDate === date).reduce((sum, entry) => sum + entry.durationSeconds, 0),
      })),
      entries,
    },
    permissions: { canDecide: true, canReopen: false, ownWeek: false },
  };
}

async function openApprove() {
  render(<ApprovalsPage />);
  fireEvent.click(await screen.findByRole("button", { name: /Carla Colaboradora/ }));
  fireEvent.click(await screen.findByRole("button", { name: "Aprovar semana" }));
  return screen.getByRole("group", { name: "Confirmar aprovação" });
}

describe("Aprovação: horas adicionais", () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date(2026, 9, 5, 12, 0, 0));
    fetchApproval.mockReset().mockResolvedValue(detail());
    decideApproval.mockReset().mockResolvedValue(detail());
  });

  afterEach(() => {
    cleanup();
    vi.useRealTimers();
  });

  it("os lançamentos mostram as horas adicionais a validar com o fator", async () => {
    render(<ApprovalsPage />);
    fireEvent.click(await screen.findByRole("button", { name: /Carla Colaboradora/ }));

    expect(await screen.findByText("Horas adicionais a validar: 02:00 → 03:00")).toBeInTheDocument();
    expect(screen.getByText("Horas adicionais a validar: 04:00 → 06:00")).toBeInTheDocument();
  });

  it("aprovar com tudo marcado valida as horas, sem lista de não autorizadas", async () => {
    const box = await openApprove();

    expect(within(box).getByRole("group", { name: "Horas adicionais desta semana" })).toBeInTheDocument();
    expect(within(box).getAllByRole("checkbox").every((checkbox) => (checkbox as HTMLInputElement).checked)).toBe(true);

    fireEvent.click(within(box).getByRole("button", { name: "Confirmar aprovação" }));

    await waitFor(() => expect(decideApproval).toHaveBeenCalledWith(expect.anything(), "7", { decision: "approve", revision: 1 }));
  });

  it("desmarcar uma hora pede o motivo e a envia como não autorizada", async () => {
    const box = await openApprove();
    const [, saturday] = within(box).getAllByRole("checkbox");

    fireEvent.click(saturday);
    fireEvent.click(within(box).getByRole("button", { name: "Confirmar aprovação" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("Informe o motivo");
    expect(decideApproval).not.toHaveBeenCalled();

    fireEvent.change(within(box).getByLabelText(/Motivo para não autorizar #15600/), { target: { value: "  Sem pedido prévio  " } });
    fireEvent.click(within(box).getByRole("button", { name: "Confirmar aprovação" }));

    await waitFor(() =>
      expect(decideApproval).toHaveBeenCalledWith(expect.anything(), "7", {
        decision: "approve",
        revision: 1,
        unauthorized: [{ entryId: "2", reason: "Sem pedido prévio" }],
      }),
    );
    expect(await screen.findByText(/1 hora\(s\) adicional\(is\) marcada\(s\) como não autorizada/)).toBeInTheDocument();
  });

  it("remarcar a hora tira o pedido de motivo", async () => {
    const box = await openApprove();
    const [first] = within(box).getAllByRole("checkbox");

    fireEvent.click(first);
    expect(within(box).getByLabelText(/Motivo para não autorizar #15596/)).toBeInTheDocument();
    fireEvent.click(first);
    expect(within(box).queryByLabelText(/Motivo para não autorizar #15596/)).not.toBeInTheDocument();
  });
});
