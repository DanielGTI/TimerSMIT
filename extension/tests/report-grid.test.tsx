import { cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import type { ReportRowDto } from "../src/lib/api/reports";
import { ReportGrid } from "../src/pages/reports/ReportGrid";

function row(overrides: Partial<ReportRowDto> = {}): ReportRowDto {
  return {
    id: "1",
    localDate: "2026-09-01",
    memberId: "3",
    memberName: "Lucas Oliveira",
    projectId: "1",
    projectName: "Reuniões SMIT",
    workItemId: 15703,
    workItemTitle: "Daily 01/09/2026",
    workItemType: "Task",
    iterationPath: "Reuniões SMIT",
    startTime: "09:10",
    endTime: "09:28",
    activityTypeId: "2",
    activityTypeName: "Reunião Interna",
    activityTypeColor: "#F5A6A6",
    durationSeconds: 1080,
    billable: false,
    source: "timer",
    note: null,
    weekStatus: "open",
    ...overrides,
  };
}

const ROWS: ReportRowDto[] = [
  row({ id: "1" }),
  row({ id: "2", localDate: "2026-09-02", workItemId: 15704, workItemTitle: "Daily 02/09/2026", durationSeconds: 1920, startTime: "09:10", endTime: "09:42" }),
  row({
    id: "3",
    memberName: "Willian Chiquinato",
    projectName: "SARC",
    projectId: "2",
    workItemId: 15647,
    workItemTitle: "Implementação do PIX em lote",
    activityTypeName: "Desenvolvimento",
    iterationPath: "SARC",
    durationSeconds: 16320,
    startTime: "09:28",
    endTime: "14:00",
  }),
  row({
    id: "4",
    memberName: "Willian Chiquinato",
    projectName: "SARC",
    projectId: "2",
    localDate: "2026-09-03",
    workItemId: 15707,
    workItemTitle: "Organizar Request",
    workItemType: "Issue",
    activityTypeName: "Desenvolvimento",
    iterationPath: "SARC",
    durationSeconds: 20160,
    startTime: null,
    endTime: null,
    source: "manual",
  }),
];

const renderGrid = (rows = ROWS, showPerson = true) => render(<ReportGrid rows={rows} showPerson={showPerson} organization="smitbr" />);

const bodyRows = () => within(screen.getAllByRole("rowgroup").find((group) => group.tagName === "TBODY")!).getAllByRole("row");

describe("ReportGrid", () => {
  afterEach(cleanup);

  it("mostra as colunas do relatório, o total filtrado e o link do work item", () => {
    renderGrid();

    for (const label of ["Horas", "Pessoa", "Work item", "Data", "Início", "Fim", "Projeto", "Atividade", "Tipo do work item", "Iteração"]) {
      expect(screen.getByRole("columnheader", { name: new RegExp(label) })).toBeInTheDocument();
    }
    expect(screen.getByRole("status")).toHaveTextContent("Linhas filtradas: 4 (10:58 h)"); // 1080+1920+16320+20160 = 39480 s

    const link = screen.getByRole("link", { name: "15703" });
    expect(link).toHaveAttribute("href", "https://dev.azure.com/smitbr/Reuni%C3%B5es%20SMIT/_workitems/edit/15703");
    expect(link).toHaveAttribute("rel", "noopener noreferrer");
    expect(screen.getAllByText("09:10").length).toBeGreaterThan(0);
  });

  it("lançamento manual sem horário mostra um traço", () => {
    renderGrid();

    const manual = bodyRows().find((tr) => within(tr).queryByText("Organizar Request", { exact: false }))!;
    expect(within(manual).getAllByText("–").length).toBeGreaterThanOrEqual(2);
  });

  it("agrupa por projeto com subtotal de horas e linhas, fechado até abrir", () => {
    renderGrid();

    fireEvent.change(screen.getByLabelText("Agrupar por"), { target: { value: "project" } });

    const toggles = screen.getAllByRole("button", { expanded: false });
    expect(toggles.map((button) => button.textContent?.replace(/\s+/g, " ").trim())).toEqual([
      "▸ Projeto: Reuniões SMIT (00:50 h em 2 linhas)",
      "▸ Projeto: SARC (10:08 h em 2 linhas)",
    ]);
    expect(screen.queryByText("Implementação do PIX em lote")).not.toBeInTheDocument();

    fireEvent.click(toggles[1]);
    expect(screen.getByText("Implementação do PIX em lote")).toBeInTheDocument();
    expect(screen.queryByText("Daily 01/09/2026")).not.toBeInTheDocument();
  });

  it("agrupa em dois níveis e expande/recolhe tudo", () => {
    renderGrid();

    fireEvent.change(screen.getByLabelText("Agrupar por"), { target: { value: "project" } });
    fireEvent.change(screen.getByLabelText("Depois por"), { target: { value: "person" } });
    fireEvent.click(screen.getByRole("button", { name: "Expandir tudo" }));

    expect(screen.getAllByRole("button", { expanded: true }).length).toBe(4); // 2 projetos + 2 pessoas
    expect(screen.getByText("Implementação do PIX em lote")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Recolher tudo" }));
    expect(screen.queryByText("Implementação do PIX em lote")).not.toBeInTheDocument();
  });

  it("filtro por coluna refina as linhas e o total, e dá para limpar", () => {
    renderGrid();

    fireEvent.change(screen.getByLabelText("Filtrar Projeto"), { target: { value: "sarc" } });

    expect(bodyRows()).toHaveLength(2);
    expect(screen.getByRole("status")).toHaveTextContent("Linhas filtradas: 2 (10:08 h)");

    fireEvent.change(screen.getByLabelText("Filtrar Tipo do work item"), { target: { value: "issue" } });
    expect(bodyRows()).toHaveLength(1);

    fireEvent.click(screen.getByRole("button", { name: "Limpar filtros" }));
    expect(bodyRows()).toHaveLength(4);
  });

  it("ordena pela coluna e inverte ao clicar de novo", () => {
    renderGrid();

    const firstTitle = () => within(bodyRows()[0]).getByText(/Daily|Implementa|Organizar/).textContent;
    fireEvent.click(screen.getByRole("button", { name: /^Horas/ }));
    expect(firstTitle()).toContain("Daily 01/09/2026"); // menor duração primeiro

    fireEvent.click(screen.getByRole("button", { name: /^Horas/ }));
    expect(firstTitle()).toContain("Organizar Request"); // maior primeiro
    expect(screen.getByRole("columnheader", { name: /Horas/ })).toHaveAttribute("aria-sort", "descending");
  });

  it("escolhe as colunas visíveis", () => {
    renderGrid();
    expect(screen.queryByRole("columnheader", { name: /Comentário/ })).not.toBeInTheDocument();

    fireEvent.click(screen.getByLabelText("Comentário"));
    expect(screen.getByRole("columnheader", { name: /Comentário/ })).toBeInTheDocument();

    fireEvent.click(screen.getByLabelText("Início"));
    expect(screen.queryByRole("columnheader", { name: /Início/ })).not.toBeInTheDocument();
  });

  it("sem acesso a outras pessoas, a coluna Pessoa não aparece", () => {
    renderGrid(ROWS, false);

    expect(screen.queryByRole("columnheader", { name: /Pessoa/ })).not.toBeInTheDocument();
    expect(within(screen.getByLabelText("Agrupar por")).queryByRole("option", { name: "Pessoa" })).not.toBeInTheDocument();
  });

  it("limita as linhas desenhadas e deixa mostrar mais", () => {
    const many = Array.from({ length: 620 }, (_, index) => row({ id: String(index + 1), workItemId: 1000 + index }));
    renderGrid(many);

    expect(bodyRows()).toHaveLength(500);
    expect(screen.getByText(/faltam 120/)).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Mostrar mais 120" }));
    expect(bodyRows()).toHaveLength(620);
  });
});
