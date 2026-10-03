import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("../src/lib/api/config", () => ({
  getApiBaseUrl: () => "https://api.example.test",
}));

const createManualEntry = vi.fn();
vi.mock("../src/lib/api/entries", () => ({
  createManualEntry: (...args: unknown[]) => createManualEntry(...args),
}));

const checkOvertime = vi.fn();
const fetchMyOvertime = vi.fn();
const informOvertime = vi.fn();
const cancelOvertime = vi.fn();
const decideOvertime = vi.fn();
vi.mock("../src/lib/api/overtime", async (importOriginal) => ({
  ...(await importOriginal<typeof import("../src/lib/api/overtime")>()),
  checkOvertime: (...args: unknown[]) => checkOvertime(...args),
  fetchMyOvertime: (...args: unknown[]) => fetchMyOvertime(...args),
  informOvertime: (...args: unknown[]) => informOvertime(...args),
  cancelOvertime: (...args: unknown[]) => cancelOvertime(...args),
  decideOvertime: (...args: unknown[]) => decideOvertime(...args),
}));

const fetchCurrentSession = vi.fn();
vi.mock("../src/lib/api/me", () => ({
  fetchCurrentSession: (...args: unknown[]) => fetchCurrentSession(...args),
}));

const stopTimer = vi.fn();
vi.mock("../src/lib/api/timer", () => ({
  startTimer: vi.fn(),
  stopTimer: (...args: unknown[]) => stopTimer(...args),
}));

const setOvertimeProfile = vi.fn();
vi.mock("../src/lib/api/settings", () => ({
  grantRole: vi.fn(),
  revokeRole: vi.fn(),
  syncPeople: vi.fn(async () => undefined),
  setOvertimeProfile: (...args: unknown[]) => setOvertimeProfile(...args),
}));
vi.mock("../src/lib/devops/directory", () => ({
  fetchActiveDirectoryPeople: vi.fn(async () => []),
}));

import { ManualEntryForm } from "../src/components/ManualEntryForm";
import { ApiError, type ApiClient } from "../src/lib/api/client";
import type { OvertimeCheckDto, OvertimeItemDto } from "../src/lib/api/overtime";
import { OvertimeDecision } from "../src/pages/approvals/OvertimeDecision";
import { PeopleSection } from "../src/pages/settings/PeopleSection";
import { MyOvertime } from "../src/pages/timesheet/MyOvertime";
import { TimerPanel } from "../src/pages/work-item/TimerPanel";

const client = {} as ApiClient;
const H = 3600;

function check(overrides: Partial<OvertimeCheckDto> = {}): OvertimeCheckDto {
  return {
    applies: true,
    profile: "standard",
    additionalSeconds: 5400,
    coveredSeconds: 0,
    uncoveredSeconds: 5400,
    entries: [{ startTime: "17:00", endTime: "19:30", seconds: 9000 }],
    pending: [],
    ...overrides,
  };
}

function item(overrides: Partial<OvertimeItemDto> = {}): OvertimeItemDto {
  return {
    id: "1",
    kind: "request",
    dateFrom: "2026-10-07",
    dateTo: "2026-10-07",
    secondsPerDay: 3 * H,
    startTime: "18:00",
    endTime: "21:00",
    reason: "Entrega do PIX",
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
    ...overrides,
  };
}

function renderForm(profile: "standard" | "restricted", onSaved = vi.fn()) {
  render(
    <ManualEntryForm
      client={client}
      project={{ id: "p", name: "SARC" }}
      workItem={{ id: 15647, title: "PIX", workItemType: "Task", iterationPath: null }}
      activityTypes={[]}
      displayName="Ana"
      requireTime
      initialDate="2026-10-07"
      overtime={{ profile }}
      onSaved={onSaved}
    />,
  );
  fireEvent.change(screen.getByLabelText("De"), { target: { value: "17:00" } });
  fireEvent.change(screen.getByLabelText("Até"), { target: { value: "19:30" } });
  return onSaved;
}

afterEach(cleanup);

describe("Lançamento com controle de hora extra", () => {
  beforeEach(() => {
    checkOvertime.mockReset();
    createManualEntry.mockReset().mockResolvedValue({});
  });

  it("perfil padrão: avisa que a hora extra está sujeita à aprovação", async () => {
    checkOvertime.mockResolvedValue(check());
    renderForm("standard");

    expect(await screen.findByRole("note")).toHaveTextContent("Horas extras, sujeitas à aprovação. 01:30 fora do expediente");
    expect(checkOvertime).toHaveBeenLastCalledWith(client, { date: "2026-10-07", startTime: "17:00", durationSeconds: 9000 });
    expect(screen.getByRole("button", { name: "Salvar" })).toBeEnabled();
  });

  it("perfil restrito: separa o que fica a confirmar e só salva com motivo e “Entendi”", async () => {
    checkOvertime.mockResolvedValue(
      check({
        profile: "restricted",
        entries: [{ startTime: "17:00", endTime: "18:00", seconds: H }],
        pending: [{ startTime: "18:00", endTime: "19:30", seconds: 5400 }],
      }),
    );
    createManualEntry.mockResolvedValue({ id: "9", pendingOvertime: [item({ kind: "confirmation", secondsPerDay: 5400 })] });
    const onSaved = renderForm("restricted");

    const box = await screen.findByRole("group", { name: "Hora extra a confirmar" });
    expect(box).toHaveTextContent("17:00–18:00 entra como lançamento.");
    expect(box).toHaveTextContent("18:00–19:30 fora do expediente fica como hora extra a confirmar");
    const save = screen.getByRole("button", { name: "Salvar" });
    expect(save).toBeDisabled();

    fireEvent.change(within(box).getByLabelText("Motivo da hora extra"), { target: { value: " Deploy " } });
    expect(save).toBeDisabled();
    fireEvent.click(within(box).getByRole("checkbox"));
    expect(save).toBeEnabled();

    fireEvent.click(save);
    await waitFor(() => expect(createManualEntry).toHaveBeenCalledTimes(1));
    expect(createManualEntry.mock.calls[0][1]).toMatchObject({ startTime: "17:00", overtimeReason: "Deploy", overtimeAcknowledged: true });
    expect(onSaved).toHaveBeenCalledWith({ localDate: "2026-10-07", minutes: 150, pendingMinutes: 90 });
  });

  it("perfil pré-aprovado não confere nem avisa", async () => {
    render(
      <ManualEntryForm
        client={client}
        project={{ id: "p", name: "SARC" }}
        workItem={{ id: 1, title: "t", workItemType: "Task", iterationPath: null }}
        activityTypes={[]}
        displayName="Ana"
        overtime={{ profile: "preapproved" }}
      />,
    );
    fireEvent.change(screen.getByLabelText("Duração"), { target: { value: "02:00" } });
    await new Promise((resolve) => setTimeout(resolve, 500));
    expect(checkOvertime).not.toHaveBeenCalled();
  });
});

describe("Horas extras na Folha semanal", () => {
  beforeEach(() => {
    fetchCurrentSession.mockReset().mockResolvedValue({
      tenantId: "t",
      organizationName: "smitbr",
      memberId: "1",
      displayName: "Ana",
      hoursRegime: "clt",
      overtimeProfile: "standard",
      overtime: { enabled: true, requireTimeOfDay: true, workdayStart: "09:00", workdayEnd: "18:00" },
    });
    fetchMyOvertime.mockReset();
    informOvertime.mockReset();
    cancelOvertime.mockReset().mockResolvedValue(item({ status: "cancelled" }));
  });

  it("mostra a situação de cada hora extra e informa uma nova", async () => {
    fetchMyOvertime.mockResolvedValue({
      applies: true,
      profile: "standard",
      items: [
        item(),
        item({ id: "2", status: "approved", approvedSecondsPerDay: 2 * H, decidedBy: "Bruno" }),
        item({ id: "3", kind: "confirmation", status: "rejected", decidedBy: "Bruno", decisionNote: "Não combinado" }),
      ],
    });
    informOvertime.mockResolvedValue(item({ id: "4", afterTheFact: true }));
    render(<MyOvertime client={client} />);

    const list = await screen.findByRole("list", { name: "Minhas horas extras" });
    expect(list).toHaveTextContent("Aguardando decisão");
    expect(list).toHaveTextContent("Aprovada até 02:00 por dia por Bruno");
    expect(list).toHaveTextContent("Recusada por Bruno: “Não combinado” (não conta)");

    fireEvent.click(within(list).getByRole("button", { name: "Cancelar" }));
    await waitFor(() => expect(cancelOvertime).toHaveBeenCalledWith(client, "1"));

    fireEvent.click(screen.getByRole("button", { name: "Informar hora extra" }));
    const panel = screen.getByRole("dialog", { name: "Informar hora extra" });
    fireEvent.change(within(panel).getByLabelText("De (data)"), { target: { value: "2026-10-06" } });
    fireEvent.change(within(panel).getByLabelText("Até (data)"), { target: { value: "2026-10-08" } });
    fireEvent.change(within(panel).getByLabelText("Horas por dia"), { target: { value: "01:30" } });
    fireEvent.change(within(panel).getByLabelText("Sugestão de destino"), { target: { value: "bank" } });
    fireEvent.change(within(panel).getByLabelText("Motivo"), { target: { value: "Virada da release" } });
    fireEvent.click(within(panel).getByRole("button", { name: "Informar" }));

    await waitFor(() => expect(informOvertime).toHaveBeenCalledTimes(1));
    expect(informOvertime.mock.calls[0][1]).toEqual({
      dateFrom: "2026-10-06",
      dateTo: "2026-10-08",
      secondsPerDay: 5400,
      reason: "Virada da release",
      suggestedDestination: "bank",
    });
    expect(await screen.findByText("Hora extra informada (depois do fato). O aprovador vai decidir.")).toBeInTheDocument();
  });

  it("não aparece para quem não é CLT", async () => {
    fetchCurrentSession.mockResolvedValue({ tenantId: "t", organizationName: "o", memberId: "1", displayName: "Ana", hoursRegime: "pj", overtime: { enabled: true } });
    const { container } = render(<MyOvertime client={client} />);
    await waitFor(() => expect(fetchCurrentSession).toHaveBeenCalled());
    expect(fetchMyOvertime).not.toHaveBeenCalled();
    expect(container).toBeEmptyDOMElement();
  });
});

describe("Decisão do aprovador", () => {
  beforeEach(() => decideOvertime.mockReset().mockResolvedValue(item({ status: "approved" })));

  it("aprova liberando menos horas por dia ou recusa com motivo", async () => {
    const onDecided = vi.fn();
    const pending = { ...item({ afterTheFact: true }), person: { id: "1", displayName: "Ana" }, weekStatus: null };
    render(
      <ul>
        <OvertimeDecision client={client} item={pending} onDecided={onDecided} />
      </ul>,
    );

    expect(screen.getByText("informada depois")).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText(/Horas liberadas por dia/), { target: { value: "02:00" } });
    fireEvent.click(screen.getByRole("button", { name: "Aprovar" }));
    await waitFor(() => expect(decideOvertime).toHaveBeenCalledWith(client, "1", { approve: true, approvedSecondsPerDay: 7200 }));
    expect(onDecided).toHaveBeenCalledWith("Hora extra aprovada para Ana.");

    cleanup();
    render(
      <ul>
        <OvertimeDecision client={client} item={item({ kind: "confirmation", projectName: "SARC", workItemId: 15647 })} onDecided={onDecided} />
      </ul>,
    );
    fireEvent.click(screen.getByRole("button", { name: "Recusar" }));
    const confirm = screen.getByRole("button", { name: "Confirmar recusa" });
    expect(confirm).toBeDisabled();
    fireEvent.change(screen.getByPlaceholderText("Motivo da recusa (obrigatório)"), { target: { value: "Não combinado" } });
    fireEvent.click(confirm);
    await waitFor(() => expect(decideOvertime).toHaveBeenLastCalledWith(client, "1", { approve: false, note: "Não combinado" }));
  });
});

describe("Timer do perfil restrito", () => {
  it("pede motivo e “Entendi” quando o servidor separa a hora a confirmar", async () => {
    stopTimer
      .mockReset()
      .mockRejectedValueOnce(new ApiError("Pelo seu perfil, a hora fora do expediente vira hora extra a confirmar.", 422, ["overtimeReason"]))
      .mockResolvedValueOnce([{ durationSeconds: H }]);
    const onTimerChange = vi.fn();
    render(
      <TimerPanel
        client={client}
        project={{ id: "p", name: "SARC" }}
        workItem={{ id: 15647, title: "PIX", workItemType: "Task", iterationPath: null }}
        activityTypes={[]}
        timer={{ id: "7", workItemId: 15647, startedAtUtc: new Date().toISOString(), status: "active", activityTypeId: null }}
        onTimerChange={onTimerChange}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "Parar timer" }));
    const box = await screen.findByRole("group", { name: "Hora extra a confirmar" });
    const stop = screen.getByRole("button", { name: "Parar e enviar para confirmação" });
    expect(stop).toBeDisabled();

    fireEvent.change(within(box).getByLabelText("Motivo da hora extra"), { target: { value: "Incidente" } });
    fireEvent.click(within(box).getByRole("checkbox"));
    fireEvent.click(stop);

    await waitFor(() => expect(stopTimer).toHaveBeenCalledTimes(2));
    expect(stopTimer.mock.calls[1][1]).toEqual({ timerId: "7", overtimeReason: "Incidente", overtimeAcknowledged: true });
    expect(onTimerChange).toHaveBeenCalledWith(null);
    expect(await screen.findByRole("status")).toHaveTextContent("ficou como hora extra a confirmar");
  });
});

describe("Perfil de hora extra em Pessoas e papéis", () => {
  it("o administrador escolhe o perfil de quem é CLT", async () => {
    const run = vi.fn(async (action: () => Promise<unknown>) => {
      await action();
      return true;
    });
    render(
      <PeopleSection
        client={client}
        busy={false}
        run={run}
        settings={
          {
            members: [
              { id: "1", name: "Ana", directoryActive: true, hoursRegime: "clt", overtimeProfile: "standard", roles: [] },
              { id: "2", name: "Pedro PJ", directoryActive: true, hoursRegime: "pj", roles: [] },
            ],
            projects: [],
            peopleSyncedAt: null,
          } as never
        }
      />,
    );

    expect(screen.queryByLabelText("Hora extra de Pedro PJ")).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("Hora extra de Ana"), { target: { value: "restricted" } });
    await waitFor(() => expect(setOvertimeProfile).toHaveBeenCalledWith(client, "1", "restricted"));
    expect(run).toHaveBeenLastCalledWith(expect.any(Function), "Ana: hora extra restrita.");
  });
});
