import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("../src/lib/api/config", () => ({
  getApiBaseUrl: () => "https://api.example.test",
}));

vi.mock("../src/lib/devops/sdk", () => ({
  getWebContext: vi.fn(async () => ({ project: { id: "project-guid-1", name: "SMIT LEARN IA" } })),
}));

vi.mock("../src/lib/devops/workItems", () => ({
  getCurrentWorkItem: vi.fn(async () => ({ id: 42, title: "Teste Tracker", workItemType: "Task" })),
}));

vi.mock("../src/lib/api/me", () => ({
  fetchCurrentSession: vi.fn(async () => ({
    tenantId: "1",
    organizationName: "smitbr",
    memberId: "1",
    displayName: "Daniel Ferreira",
  })),
}));

const fetchActiveTimer = vi.fn();
const startTimer = vi.fn();
const stopTimer = vi.fn();

vi.mock("../src/lib/api/timer", () => ({
  fetchActiveTimer: (...args: unknown[]) => fetchActiveTimer(...args),
  startTimer: (...args: unknown[]) => startTimer(...args),
  stopTimer: (...args: unknown[]) => stopTimer(...args),
}));

vi.mock("../src/lib/api/entries", () => ({
  createManualEntry: vi.fn(),
}));

import { WorkItemGuide } from "../src/pages/work-item/WorkItemGuide";

describe("WorkItemGuide", () => {
  beforeEach(() => {
    fetchActiveTimer.mockReset();
    startTimer.mockReset();
    stopTimer.mockReset();
  });

  afterEach(() => {
    cleanup();
  });

  it("mostra o botão de iniciar quando não há timer ativo", async () => {
    fetchActiveTimer.mockResolvedValue(null);

    render(<WorkItemGuide />);

    expect(await screen.findByRole("button", { name: "Iniciar timer" })).toBeInTheDocument();
  });

  it("inicia o timer e passa a mostrar o botão de parar", async () => {
    fetchActiveTimer.mockResolvedValue(null);
    startTimer.mockResolvedValue({
      id: "10",
      workItemId: 42,
      startedAtUtc: new Date().toISOString(),
      status: "active",
    });

    render(<WorkItemGuide />);

    const startButton = await screen.findByRole("button", { name: "Iniciar timer" });
    fireEvent.click(startButton);

    expect(await screen.findByRole("button", { name: "Parar timer" })).toBeInTheDocument();
    expect(startTimer).toHaveBeenCalledWith(
      expect.anything(),
      expect.objectContaining({ projectId: "project-guid-1", workItemId: 42 }),
    );
  });

  it("avisa quando o timer ativo é de outro work item", async () => {
    fetchActiveTimer.mockResolvedValue({
      id: "99",
      workItemId: 7,
      startedAtUtc: new Date().toISOString(),
      status: "active",
    });

    render(<WorkItemGuide />);

    await waitFor(() => {
      expect(screen.getByText(/Há um timer ativo em outro work item \(#7\)/)).toBeInTheDocument();
    });
    expect(screen.queryByRole("button", { name: "Iniciar timer" })).not.toBeInTheDocument();
  });

  it("para o timer ativo deste work item", async () => {
    fetchActiveTimer.mockResolvedValue({
      id: "10",
      workItemId: 42,
      startedAtUtc: new Date().toISOString(),
      status: "active",
    });
    stopTimer.mockResolvedValue([]);

    render(<WorkItemGuide />);

    const stopButton = await screen.findByRole("button", { name: "Parar timer" });
    fireEvent.click(stopButton);

    expect(await screen.findByRole("button", { name: "Iniciar timer" })).toBeInTheDocument();
    expect(stopTimer).toHaveBeenCalledWith(expect.anything(), { timerId: "10" });
  });
});
