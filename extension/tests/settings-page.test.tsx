import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("../src/lib/api/config", () => ({
  getApiBaseUrl: () => "https://api.example.test",
}));

const api = {
  fetchSettings: vi.fn(),
  updateTimezone: vi.fn(),
  updatePolicy: vi.fn(),
  setProjectEnabled: vi.fn(),
  createActivityType: vi.fn(),
  updateActivityType: vi.fn(),
  grantRole: vi.fn(),
  revokeRole: vi.fn(),
  designateApprover: vi.fn(),
  removeDesignation: vi.fn(),
  syncPeople: vi.fn(),
};

const directory = { fetchActiveDirectoryPeople: vi.fn() };

vi.mock("../src/lib/devops/directory", () => ({
  fetchActiveDirectoryPeople: (...args: unknown[]) => directory.fetchActiveDirectoryPeople(...args),
}));

vi.mock("../src/lib/api/settings", () => ({
  fetchSettings: (...args: unknown[]) => api.fetchSettings(...args),
  updateTimezone: (...args: unknown[]) => api.updateTimezone(...args),
  updatePolicy: (...args: unknown[]) => api.updatePolicy(...args),
  setProjectEnabled: (...args: unknown[]) => api.setProjectEnabled(...args),
  createActivityType: (...args: unknown[]) => api.createActivityType(...args),
  updateActivityType: (...args: unknown[]) => api.updateActivityType(...args),
  grantRole: (...args: unknown[]) => api.grantRole(...args),
  revokeRole: (...args: unknown[]) => api.revokeRole(...args),
  designateApprover: (...args: unknown[]) => api.designateApprover(...args),
  removeDesignation: (...args: unknown[]) => api.removeDesignation(...args),
  syncPeople: (...args: unknown[]) => api.syncPeople(...args),
}));

import type { SettingsDto } from "../src/lib/api/settings";
import { SettingsPage } from "../src/pages/settings/SettingsPage";

function settings(overrides: Partial<SettingsDto> = {}): SettingsDto {
  return {
    organization: { name: "smitbr", timezone: "America/Sao_Paulo" },
    policy: {
      durationIncrementMinutes: 1,
      dailyLimitHours: 24,
      retroactiveWindowDays: 30,
      commentRequired: false,
      version: 0,
      effectiveFrom: null,
    },
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
      version: 1,
      effectiveFrom: "2026-10-02T12:00:00Z",
    },
    holidays: [],
    projects: [
      { id: "1", name: "Projeto A", enabled: true },
      { id: "2", name: "Projeto B", enabled: false },
    ],
    activityTypes: [
      { id: "3", name: "Desenvolvimento", color: "#A6D8F5", enabled: true, defaultBillable: false },
      { id: "4", name: "Suporte", color: "#F87878", enabled: false, defaultBillable: true },
    ],
    members: [
      { id: "10", name: "Ana Admin", directoryActive: true, hoursRegime: "clt", roles: [{ id: "100", role: "admin", projectId: null, projectName: null }] },
      { id: "11", name: "Eva Colaboradora", directoryActive: true, hoursRegime: "clt", roles: [{ id: "101", role: "member", projectId: "1", projectName: "Projeto A" }] },
      { id: "12", name: "Nando Novo", directoryActive: true, hoursRegime: "clt", roles: [] },
    ],
    peopleSyncedAt: null,
    designations: [
      { id: "200", memberId: "11", memberName: "Eva Colaboradora", approverId: "10", approverName: "Ana Admin", projectId: null, projectName: null },
    ],
    ...overrides,
  };
}

async function openTab(name: string) {
  render(<SettingsPage />);
  await screen.findByText(/Configuração · smitbr/);
  fireEvent.click(screen.getByRole("tab", { name }));
  // Ao abrir "Pessoas e papéis" a lista é atualizada sozinha; espera terminar
  // para não clicar em controles ainda desabilitados.
  if (name === "Pessoas e papéis") await screen.findByText(SYNC_NOTICE);
}

const SYNC_NOTICE = "Lista de pessoas atualizada com o Azure DevOps.";

describe("SettingsPage", () => {
  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset());
    directory.fetchActiveDirectoryPeople.mockReset();
    api.fetchSettings.mockResolvedValue(settings());
    directory.fetchActiveDirectoryPeople.mockResolvedValue([
      { identityId: "aaaaaaaa-0000-4000-8000-000000000001", displayName: "Ana Admin" },
    ]);
    api.syncPeople.mockImplementation(async () => settings());
  });

  afterEach(() => {
    cleanup();
  });

  it("administrador vê a organização, as abas e as regras em vigor", async () => {
    render(<SettingsPage />);

    expect(await screen.findByText("Configuração · smitbr")).toBeInTheDocument();
    expect(screen.getAllByRole("tab").map((tab) => tab.textContent)).toEqual([
      "Regras",
      "Horas extras",
      "Horas adicionais",
      "Projetos",
      "Atividades",
      "Pessoas e papéis",
      "Aprovadores",
    ]);
    expect(screen.getByLabelText("Limite diário (horas)")).toHaveValue(24);
    expect(screen.getByLabelText("Janela retroativa (dias)")).toHaveValue(30);
    expect(screen.getByText(/Usando os padrões/)).toBeInTheDocument();
  });

  it("quem não é administrador só vê o motivo da recusa", async () => {
    api.fetchSettings.mockRejectedValue(new Error("Você não tem permissão para esta ação."));
    render(<SettingsPage />);

    expect(await screen.findByRole("alert")).toHaveTextContent("permissão");
    expect(screen.queryByRole("tab")).not.toBeInTheDocument();
  });

  it("salva as regras como nova versão e mostra a versão devolvida", async () => {
    const saved = settings({
      policy: { durationIncrementMinutes: 15, dailyLimitHours: 8, retroactiveWindowDays: 30, commentRequired: true, version: 1, effectiveFrom: "2026-10-01T10:00:00Z" },
    });
    api.updatePolicy.mockResolvedValue(saved);
    await openTab("Regras");

    fireEvent.change(screen.getByLabelText("Incremento de duração"), { target: { value: "15" } });
    fireEvent.change(screen.getByLabelText("Limite diário (horas)"), { target: { value: "8" } });
    fireEvent.click(screen.getByRole("switch", { name: /Comentário obrigatório/ }));
    fireEvent.click(screen.getByRole("button", { name: "Salvar regras" }));

    await waitFor(() =>
      expect(api.updatePolicy).toHaveBeenCalledWith(expect.anything(), {
        durationIncrementMinutes: 15,
        dailyLimitHours: 8,
        retroactiveWindowDays: 30,
        commentRequired: true,
      }),
    );
    expect(await screen.findByText(/o histórico não muda/)).toBeInTheDocument();
    expect(screen.getByText(/Versão 1 em vigor/)).toBeInTheDocument();
  });

  it("salva o fuso só quando ele muda", async () => {
    api.updateTimezone.mockResolvedValue(settings({ organization: { name: "smitbr", timezone: "Europe/Lisbon" } }));
    await openTab("Regras");

    const save = screen.getByRole("button", { name: "Salvar fuso" });
    expect(save).toBeDisabled();

    fireEvent.change(screen.getByLabelText("Fuso"), { target: { value: "Europe/Lisbon" } });
    expect(save).toBeEnabled();
    fireEvent.click(save);

    await waitFor(() => expect(api.updateTimezone).toHaveBeenCalledWith(expect.anything(), "Europe/Lisbon"));
    expect(await screen.findByText(/Vale para os próximos lançamentos/)).toBeInTheDocument();
  });

  it("habilita e desabilita projetos", async () => {
    api.setProjectEnabled.mockResolvedValue(settings());
    await openTab("Projetos");

    fireEvent.click(screen.getByRole("switch", { name: "Projeto A habilitado" }));
    await waitFor(() => expect(api.setProjectEnabled).toHaveBeenCalledWith(expect.anything(), "1", false));

    fireEvent.click(screen.getByRole("switch", { name: "Projeto B habilitado" }));
    await waitFor(() => expect(api.setProjectEnabled).toHaveBeenCalledWith(expect.anything(), "2", true));
  });

  it("renomeia, recolore e desabilita atividades", async () => {
    api.updateActivityType.mockResolvedValue(settings());
    await openTab("Atividades");

    const save = screen.getAllByRole("button", { name: "Salvar" })[0];
    expect(save).toBeDisabled();

    fireEvent.change(screen.getByLabelText("Nome de Desenvolvimento"), { target: { value: "  Dev  " } });
    fireEvent.change(screen.getByLabelText("Cor de Desenvolvimento"), { target: { value: "#112233" } });
    fireEvent.click(save);
    await waitFor(() => expect(api.updateActivityType).toHaveBeenCalledWith(expect.anything(), "3", { name: "Dev", color: "#112233" }));

    fireEvent.click(screen.getByRole("switch", { name: "Desenvolvimento habilitada" }));
    await waitFor(() => expect(api.updateActivityType).toHaveBeenCalledWith(expect.anything(), "3", { enabled: false }));

    fireEvent.click(screen.getByRole("switch", { name: "Suporte: faturável por padrão" }));
    await waitFor(() => expect(api.updateActivityType).toHaveBeenCalledWith(expect.anything(), "4", { defaultBillable: false }));
  });

  it("cria uma atividade nova e limpa o nome só se der certo", async () => {
    api.createActivityType.mockRejectedValueOnce(new Error("Já existe uma atividade com esse nome.")).mockResolvedValueOnce(settings());
    await openTab("Atividades");

    fireEvent.change(screen.getByLabelText("Nome da nova atividade"), { target: { value: "Consultoria" } });
    fireEvent.click(screen.getByRole("button", { name: "Criar" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("Já existe uma atividade");
    expect(screen.getByLabelText("Nome da nova atividade")).toHaveValue("Consultoria");

    fireEvent.click(screen.getByRole("button", { name: "Criar" }));
    await waitFor(() => expect(screen.getByLabelText("Nome da nova atividade")).toHaveValue(""));
    expect(api.createActivityType).toHaveBeenLastCalledWith(expect.anything(), { name: "Consultoria", color: "#6fa58a", defaultBillable: false });
  });

  it("mostra quem está sem acesso e concede um papel", async () => {
    api.grantRole.mockResolvedValue(settings());
    await openTab("Pessoas e papéis");

    const list = screen.getByRole("list");
    const nando = within(list).getByText("Nando Novo").closest("li")!;
    expect(within(nando).getByText("Sem acesso")).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText("Pessoa"), { target: { value: "12" } });
    fireEvent.change(screen.getByLabelText("Papel"), { target: { value: "manager" } });
    fireEvent.change(screen.getByLabelText("Onde vale"), { target: { value: "1" } });
    fireEvent.click(screen.getByRole("button", { name: "Conceder" }));

    await waitFor(() => expect(api.grantRole).toHaveBeenCalledWith(expect.anything(), "12", "manager", "1"));
    expect(await screen.findByText("Gestor concedido a Nando Novo.")).toBeInTheDocument();
  });

  it("ao abrir Pessoas e papéis lê quem está ativo no Azure DevOps e atualiza a lista", async () => {
    const people = [
      { identityId: "aaaaaaaa-0000-4000-8000-000000000001", displayName: "Ana Admin" },
      { identityId: "aaaaaaaa-0000-4000-8000-000000000002", displayName: "Nina Nova" },
    ];
    directory.fetchActiveDirectoryPeople.mockResolvedValue(people);
    api.syncPeople.mockResolvedValue(
      settings({
        peopleSyncedAt: "2026-10-01T12:00:00Z",
        members: [
          { id: "10", name: "Ana Admin", directoryActive: true, hoursRegime: "clt", roles: [] },
          { id: "13", name: "Nina Nova", directoryActive: true, hoursRegime: "clt", roles: [] },
        ],
      }),
    );

    await openTab("Pessoas e papéis");

    expect(directory.fetchActiveDirectoryPeople).toHaveBeenCalledTimes(1);
    expect(api.syncPeople).toHaveBeenCalledWith(expect.anything(), people);
    expect(within(screen.getByRole("list")).getByText("Nina Nova")).toBeInTheDocument();
    expect(screen.getByText(/Lista do Azure DevOps atualizada em/)).toBeInTheDocument();
  });

  it("o botão atualiza de novo a pedido", async () => {
    await openTab("Pessoas e papéis");

    fireEvent.click(screen.getByRole("button", { name: "Atualizar do Azure DevOps" }));

    await waitFor(() => expect(api.syncPeople).toHaveBeenCalledTimes(2));
  });

  it("se a leitura do Azure DevOps falha, mostra o motivo e mantém a lista conhecida", async () => {
    directory.fetchActiveDirectoryPeople.mockRejectedValue(new Error("O Azure DevOps não permitiu ler os usuários da organização."));
    render(<SettingsPage />);
    await screen.findByText(/Configuração · smitbr/);
    fireEvent.click(screen.getByRole("tab", { name: "Pessoas e papéis" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("não permitiu ler os usuários");
    expect(api.syncPeople).not.toHaveBeenCalled();
    expect(within(screen.getByRole("list")).getByText("Nando Novo")).toBeInTheDocument();
  });

  it("quem saiu do Azure DevOps fica numa seção à parte e some das escolhas", async () => {
    api.syncPeople.mockResolvedValue(
      settings({
        members: [
          { id: "10", name: "Ana Admin", directoryActive: true, hoursRegime: "clt", roles: [{ id: "100", role: "admin", projectId: null, projectName: null }] },
          { id: "14", name: "Otávio Ex-colaborador", directoryActive: false, hoursRegime: "clt", roles: [{ id: "104", role: "member", projectId: null, projectName: null }] },
        ],
      }),
    );

    await openTab("Pessoas e papéis");

    expect(screen.getByText("Inativos no Azure DevOps (1)")).toBeInTheDocument();
    const choices = within(screen.getByLabelText("Pessoa")).getAllByRole("option").map((option) => option.textContent);
    expect(choices).toEqual(["Escolha…", "Ana Admin"]);
  });

  it("papel sem projeto vale para a organização inteira", async () => {
    api.grantRole.mockResolvedValue(settings());
    await openTab("Pessoas e papéis");

    fireEvent.change(screen.getByLabelText("Pessoa"), { target: { value: "12" } });
    fireEvent.click(screen.getByRole("button", { name: "Conceder" }));

    await waitFor(() => expect(api.grantRole).toHaveBeenCalledWith(expect.anything(), "12", "member", null));
  });

  it("remove um papel e mostra o erro do último administrador", async () => {
    api.revokeRole.mockRejectedValue(new Error("Não é possível remover o último administrador da organização."));
    await openTab("Pessoas e papéis");

    fireEvent.click(screen.getByRole("button", { name: "Remover Administrador de Ana Admin (Toda a organização)" }));

    await waitFor(() => expect(api.revokeRole).toHaveBeenCalledWith(expect.anything(), "100"));
    expect(await screen.findByRole("alert")).toHaveTextContent("último administrador");
  });

  it("designa aprovador podendo reatribuir as semanas pendentes", async () => {
    api.designateApprover.mockResolvedValue(settings());
    await openTab("Aprovadores");

    expect(screen.getByText(/aprova as semanas de/)).toHaveTextContent("Ana Admin aprova as semanas de Eva Colaboradora");

    fireEvent.change(screen.getByLabelText("Semanas de"), { target: { value: "12" } });
    fireEvent.change(screen.getByLabelText("Aprovador"), { target: { value: "10" } });
    fireEvent.click(screen.getByRole("checkbox", { name: /Aplicar às semanas/ }));
    fireEvent.click(screen.getByRole("button", { name: "Designar" }));

    await waitFor(() => expect(api.designateApprover).toHaveBeenCalledWith(expect.anything(), "12", "10", null, true));
  });

  it("não deixa designar a pessoa como aprovadora de si mesma", async () => {
    await openTab("Aprovadores");

    fireEvent.change(screen.getByLabelText("Semanas de"), { target: { value: "11" } });
    fireEvent.change(screen.getByLabelText("Aprovador"), { target: { value: "11" } });

    expect(screen.getByText(/não pode aprovar a própria semana/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Designar" })).toBeDisabled();
  });

  it("remove a designação com ou sem reatribuir as pendentes", async () => {
    api.removeDesignation.mockResolvedValue(settings({ designations: [] }));
    await openTab("Aprovadores");

    fireEvent.click(screen.getByRole("button", { name: "Remover e reatribuir pendentes" }));

    await waitFor(() => expect(api.removeDesignation).toHaveBeenCalledWith(expect.anything(), "200", true));
    expect(await screen.findByText(/Nenhuma designação/)).toBeInTheDocument();
  });

  it("limpa os avisos ao trocar de aba", async () => {
    api.setProjectEnabled.mockResolvedValue(settings());
    await openTab("Projetos");

    fireEvent.click(screen.getByRole("switch", { name: "Projeto A habilitado" }));
    expect(await screen.findByRole("status")).toHaveTextContent("Projeto Projeto A desabilitado.");

    fireEvent.click(screen.getByRole("tab", { name: "Atividades" }));
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
  });
});
