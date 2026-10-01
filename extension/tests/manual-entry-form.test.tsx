import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const createManualEntry = vi.fn();

vi.mock("../src/lib/api/entries", () => ({
  createManualEntry: (...args: unknown[]) => createManualEntry(...args),
}));

import type { ApiClient } from "../src/lib/api/client";
import { ManualEntryForm } from "../src/pages/work-item/ManualEntryForm";

const client = {} as ApiClient;
const activityTypes = [
  { id: "3", name: "Desenvolvimento", color: "#A6D8F5", defaultBillable: false },
  { id: "9", name: "Suporte ao Cliente", color: "#F87878", defaultBillable: true },
];

function renderForm() {
  return render(
    <ManualEntryForm
      client={client}
      project={{ id: "project-guid-1", name: "SMIT LEARN IA" }}
      workItem={{ id: 42, title: "Teste Tracker", workItemType: "Task", iterationPath: "SMIT LEARN IA\\Sprint 3" }}
      activityTypes={activityTypes}
      displayName="Daniel Ferreira"
    />,
  );
}

const durationInput = () => screen.getByLabelText("Duração") as HTMLInputElement;
const toInput = () => screen.getByLabelText("Até") as HTMLInputElement;
const saveButton = () => screen.getByRole("button", { name: "Salvar" });

function chooseActivity(name: string) {
  fireEvent.click(screen.getByRole("combobox", { name: "Atividade do lançamento" }));
  fireEvent.click(screen.getByRole("option", { name }));
}

describe("ManualEntryForm", () => {
  beforeEach(() => {
    createManualEntry.mockReset();
  });

  afterEach(() => {
    cleanup();
  });

  it("mostra o usuário com iniciais e começa com duração zerada e salvar desabilitado", () => {
    renderForm();

    expect(screen.getByText("Daniel Ferreira")).toBeInTheDocument();
    expect(screen.getByText("DF")).toBeInTheDocument();
    expect(durationInput().value).toBe("00:00");
    expect(saveButton()).toBeDisabled();
  });

  it("atalhos somam à duração e deslocam o horário final a partir do inicial", () => {
    renderForm();
    fireEvent.change(screen.getByLabelText("De"), { target: { value: "10:00" } });
    fireEvent.change(toInput(), { target: { value: "10:00" } });

    fireEvent.click(screen.getByRole("button", { name: "+1h" }));
    fireEvent.click(screen.getByRole("button", { name: "+0,5h" }));

    expect(durationInput().value).toBe("01:30");
    expect(toInput().value).toBe("11:30");
  });

  it("alterar o intervalo De/Até recalcula a duração", () => {
    renderForm();
    fireEvent.change(screen.getByLabelText("De"), { target: { value: "09:00" } });
    fireEvent.change(toInput(), { target: { value: "10:15" } });

    expect(durationInput().value).toBe("01:15");
  });

  it("recusa duração fora do formato HH:MM", () => {
    renderForm();
    fireEvent.change(durationInput(), { target: { value: "abc" } });

    expect(screen.getByText(/Use o formato HH:MM/)).toBeInTheDocument();
    expect(saveButton()).toBeDisabled();
  });

  it("envia data, duração, atividade, faturável e comentário", async () => {
    createManualEntry.mockResolvedValue({});
    renderForm();

    fireEvent.change(screen.getByLabelText("Data"), { target: { value: "2026-09-30" } });
    fireEvent.change(durationInput(), { target: { value: "01:30" } });
    chooseActivity("Desenvolvimento");
    fireEvent.click(screen.getByRole("switch", { name: "Horas faturáveis" }));
    fireEvent.change(screen.getByLabelText("Comentário"), { target: { value: "  Reunião de planejamento  " } });
    fireEvent.click(saveButton());

    await waitFor(() => expect(createManualEntry).toHaveBeenCalledTimes(1));
    expect(createManualEntry).toHaveBeenCalledWith(client, {
      projectId: "project-guid-1",
      projectName: "SMIT LEARN IA",
      workItemId: 42,
      localDate: "2026-09-30",
      durationSeconds: 5400,
      activityTypeId: 3,
      billable: true,
      note: "Reunião de planejamento",
      title: "Teste Tracker",
      workItemType: "Task",
      iterationPath: "SMIT LEARN IA\\Sprint 3",
    });
    expect(await screen.findByText("Lançamento de 01:30 registrado.")).toBeInTheDocument();
    expect(durationInput().value).toBe("00:00");
  });

  it("sem atividade escolhida não envia activityTypeId", async () => {
    createManualEntry.mockResolvedValue({});
    renderForm();

    fireEvent.change(durationInput(), { target: { value: "00:45" } });
    fireEvent.click(saveButton());

    await waitFor(() => expect(createManualEntry).toHaveBeenCalledTimes(1));
    expect(createManualEntry.mock.calls[0][1]).toMatchObject({ durationSeconds: 2700, activityTypeId: undefined });
  });

  it("escolher atividade aplica o faturável padrão dela", () => {
    renderForm();
    const toggle = screen.getByRole("switch", { name: "Horas faturáveis" });
    expect(toggle).toHaveAttribute("aria-checked", "false");

    chooseActivity("Suporte ao Cliente");

    expect(toggle).toHaveAttribute("aria-checked", "true");
  });

  it("mostra a mensagem do backend quando o lançamento é recusado", async () => {
    createManualEntry.mockRejectedValue(new Error("Limite diário de horas excedido para esta data."));
    renderForm();

    fireEvent.change(durationInput(), { target: { value: "10:00" } });
    fireEvent.click(saveButton());

    expect(await screen.findByRole("alert")).toHaveTextContent("Limite diário de horas excedido para esta data.");
  });
});
