import { cleanup, render, screen, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("../src/lib/api/config", () => ({
  getApiBaseUrl: () => "https://api.example.test",
}));

const fetchCurrentSession = vi.fn();

vi.mock("../src/lib/api/me", () => ({
  fetchCurrentSession: (...args: unknown[]) => fetchCurrentSession(...args),
}));

import { InstructionsPage } from "../src/pages/instructions/InstructionsPage";

const session = (policy?: object) => ({
  tenantId: "1",
  organizationName: "smitbr",
  memberId: "2",
  displayName: "Daniel Ferreira",
  ...(policy ? { policy } : {}),
});

describe("InstructionsPage", () => {
  beforeEach(() => {
    fetchCurrentSession.mockReset();
  });
  afterEach(() => cleanup());

  it("explica o fluxo, os estados da semana e a regra de aprovação", async () => {
    fetchCurrentSession.mockResolvedValue(session());
    render(<InstructionsPage />);

    expect(screen.getByRole("heading", { level: 1, name: "Como usar o Controle de horas" })).toBeInTheDocument();
    for (const title of [
      "Em resumo",
      "Lançar horas",
      "Timer",
      "A semana e seus estados",
      "Como funciona a aprovação",
      "Regras e limites",
      "Para não perder horas",
      "Relatórios e Configuração",
      "Dúvidas comuns",
    ]) {
      expect(screen.getByRole("heading", { level: 2, name: title })).toBeInTheDocument();
    }

    const states = screen.getByRole("table", { name: "O que dá para fazer em cada estado da semana" });
    for (const state of ["Aberta", "Enviada", "Rejeitada", "Aprovada"]) {
      expect(within(states).getByText(state)).toBeInTheDocument();
    }
    expect(screen.getByText(/Ninguém aprova a própria semana/)).toBeInTheDocument();
    expect(screen.getByText(/Só um administrador reabre/)).toBeInTheDocument();
  });

  it("o índice leva a cada seção", async () => {
    fetchCurrentSession.mockResolvedValue(session());
    render(<InstructionsPage />);

    const index = screen.getByRole("navigation", { name: "Seções desta página" });
    const links = within(index).getAllByRole("link");
    expect(links).toHaveLength(10);
    for (const link of links) {
      const id = link.getAttribute("href")!.slice(1);
      expect(document.getElementById(id), `seção #${id}`).not.toBeNull();
    }
  });

  it("mostra os limites reais da organização", async () => {
    fetchCurrentSession.mockResolvedValue(
      session({ dailyLimitHours: 10, retroactiveWindowDays: 7, durationIncrementMinutes: 15, commentRequired: true }),
    );
    render(<InstructionsPage />);

    expect(await screen.findByText("Limite por dia: 10 horas.")).toBeInTheDocument();
    expect(screen.getByText("Lançamento retroativo: até 7 dias para trás.")).toBeInTheDocument();
    expect(screen.getByText("Duração em múltiplos de 15 minutos.")).toBeInTheDocument();
    expect(screen.getByText("Comentário obrigatório")).toBeInTheDocument();
  });

  it("concorda no singular e nos padrões (sem incremento nem comentário)", async () => {
    fetchCurrentSession.mockResolvedValue(
      session({ dailyLimitHours: 1, retroactiveWindowDays: 1, durationIncrementMinutes: 1, commentRequired: false }),
    );
    render(<InstructionsPage />);

    expect(await screen.findByText("Limite por dia: 1 hora.")).toBeInTheDocument();
    expect(screen.getByText("Lançamento retroativo: até 1 dia para trás.")).toBeInTheDocument();
    expect(screen.getByText("Duração livre, minuto a minuto.")).toBeInTheDocument();
    expect(screen.getByText("Comentário opcional")).toBeInTheDocument();
  });

  it("sem resposta do servidor a página continua útil e só os números ficam de fora", async () => {
    fetchCurrentSession.mockRejectedValue(new Error("HTTP 500"));
    render(<InstructionsPage />);

    expect(await screen.findByText(/Os valores desta organização estão carregando/)).toBeInTheDocument();
    expect(screen.getByRole("heading", { level: 2, name: "Como funciona a aprovação" })).toBeInTheDocument();
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
  });
});
