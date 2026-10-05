import { StatusBadge } from "../../components/StatusBadge";
import type { GroupTotalDto, ReportDto } from "../../lib/api/reports";
import { formatHours } from "../../lib/time/format";
import { dayMonth, weekdayShort } from "../../lib/time/weeks";
import { useColumnWidths } from "./useColumnWidths";

function Breakdown({ title, groups, total }: { title: string; groups: GroupTotalDto[]; total: number }): JSX.Element {
  return (
    <section className="breakdown" aria-label={title}>
      <h3>{title}</h3>
      {groups.length === 0 ? (
        <p className="muted">Sem dados.</p>
      ) : (
        <ul>
          {groups.map((group) => (
            <li key={group.id ?? group.name}>
              <span className="breakdown__line">
                <span>{group.name}</span>
                <strong>{formatHours(group.totalSeconds)}</strong>
              </span>
              <span className="bar" aria-hidden="true">
                <span style={{ width: `${total > 0 ? Math.max(2, Math.round((group.totalSeconds / total) * 100)) : 0}%` }} />
              </span>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

type ResultColumn = "date" | "person" | "project" | "workItem" | "activity" | "duration" | "billable" | "week" | "note";

const RESULT_WIDTH: Record<ResultColumn, number> = {
  date: 100,
  person: 150,
  project: 160,
  workItem: 380,
  activity: 160,
  duration: 90,
  billable: 90,
  week: 120,
  note: 260,
};

interface ReportResultsProps {
  report: ReportDto;
  onPage: (page: number) => void;
  /** Algum projeto usa "faturável". */
  showBillable?: boolean;
  /** Nome da organização no Azure DevOps, para o link do work item. */
  organization?: string | null;
}

/** Cartões de totais, quebras por pessoa/projeto/atividade e a tabela paginada. */
export function ReportResults({ report, onPage, showBillable = false, organization = null }: ReportResultsProps): JSX.Element {
  const { totals, pagination } = report;
  const { resizer, layout } = useColumnWidths("timersmit.report.summaryColumnWidths", RESULT_WIDTH);
  const showPerson = report.scope.canFilterByMember;
  const columns: Array<{ key: ResultColumn; label: string }> = [
    { key: "date", label: "Data" },
    ...(showPerson ? [{ key: "person" as const, label: "Pessoa" }] : []),
    { key: "project", label: "Projeto" },
    { key: "workItem", label: "Work item" },
    { key: "activity", label: "Atividade" },
    { key: "duration", label: "Duração" },
    ...(showBillable ? [{ key: "billable" as const, label: "Faturável" }] : []),
    { key: "week", label: "Semana" },
    { key: "note", label: "Comentário" },
  ];
  const { tableWidth, colgroup } = layout(columns.map((column) => column.key));

  return (
    <>
      <div className="stats" role="group" aria-label="Totais">
        <div className="stat">
          <span className="muted">Total</span>
          <strong>{formatHours(totals.totalSeconds)}</strong>
        </div>
        {showBillable && (
          <>
            <div className="stat">
              <span className="muted">Faturável</span>
              <strong>{formatHours(totals.billableSeconds)}</strong>
            </div>
            <div className="stat">
              <span className="muted">Não faturável</span>
              <strong>{formatHours(totals.nonBillableSeconds)}</strong>
            </div>
          </>
        )}
        <div className="stat">
          <span className="muted">Lançamentos</span>
          <strong>{totals.entryCount}</strong>
        </div>
      </div>

      <div className="breakdowns">
        {report.scope.canFilterByMember && <Breakdown title="Por pessoa" groups={report.byMember} total={totals.totalSeconds} />}
        <Breakdown title="Por projeto" groups={report.byProject} total={totals.totalSeconds} />
        <Breakdown title="Por atividade" groups={report.byActivity} total={totals.totalSeconds} />
      </div>

      {report.rows.length === 0 ? (
        <p className="muted">Nenhum lançamento para esses filtros.</p>
      ) : (
        <div className="table-scroll">
          <table className="entry-table grid-table--report" style={{ width: tableWidth }}>
            <caption className="sr-only">Lançamentos do relatório</caption>
            {colgroup}
            <thead>
              <tr>
                {columns.map((column) => (
                  <th key={column.key} scope="col">
                    {column.label}
                    {resizer(column.key, column.label)}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {report.rows.map((row) => (
                <tr key={row.id}>
                  <td>
                    {weekdayShort(row.localDate)} {dayMonth(row.localDate)}
                  </td>
                  {report.scope.canFilterByMember && <td>{row.memberName}</td>}
                  <td>{row.projectName}</td>
                  <td>
                    {organization ? (
                      <a
                        href={`https://dev.azure.com/${encodeURIComponent(organization)}/${encodeURIComponent(row.projectName)}/_workitems/edit/${row.workItemId}`}
                        target="_blank"
                        rel="noopener noreferrer"
                        title={`Abrir o work item ${row.workItemId} no Azure DevOps`}
                      >
                        #{row.workItemId} {row.workItemTitle ?? ""}
                      </a>
                    ) : (
                      <>
                        #{row.workItemId} {row.workItemTitle ?? ""}
                      </>
                    )}
                  </td>
                  <td>
                    <span className="activity">
                      <span className="swatch" style={{ background: row.activityTypeColor ?? "transparent" }} />
                      {row.activityTypeName ?? "Não definido"}
                    </span>
                  </td>
                  <td>{formatHours(row.durationSeconds)}</td>
                  {showBillable && <td>{row.billable ? "Sim" : "Não"}</td>}
                  <td>
                    <StatusBadge status={row.weekStatus} />
                  </td>
                  <td>{row.note ?? <span className="muted">–</span>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <nav className="pager" aria-label="Paginação">
        <button type="button" className="btn btn--chip" disabled={pagination.page <= 1} onClick={() => onPage(pagination.page - 1)}>
          Anterior
        </button>
        <span className="muted">
          Página {pagination.page} de {pagination.lastPage} · {pagination.total} lançamentos
        </span>
        <button
          type="button"
          className="btn btn--chip"
          disabled={pagination.page >= pagination.lastPage}
          onClick={() => onPage(pagination.page + 1)}
        >
          Próxima
        </button>
      </nav>
    </>
  );
}
