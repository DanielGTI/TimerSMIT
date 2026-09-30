import { StatusBadge } from "../../components/StatusBadge";
import type { GroupTotalDto, ReportDto } from "../../lib/api/reports";
import { formatHours } from "../../lib/time/format";
import { dayMonth, weekdayShort } from "../../lib/time/weeks";

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

interface ReportResultsProps {
  report: ReportDto;
  onPage: (page: number) => void;
}

/** Cartões de totais, quebras por pessoa/projeto/atividade e a tabela paginada. */
export function ReportResults({ report, onPage }: ReportResultsProps): JSX.Element {
  const { totals, pagination } = report;

  return (
    <>
      <div className="stats" role="group" aria-label="Totais">
        <div className="stat">
          <span className="muted">Total</span>
          <strong>{formatHours(totals.totalSeconds)}</strong>
        </div>
        <div className="stat">
          <span className="muted">Faturável</span>
          <strong>{formatHours(totals.billableSeconds)}</strong>
        </div>
        <div className="stat">
          <span className="muted">Não faturável</span>
          <strong>{formatHours(totals.nonBillableSeconds)}</strong>
        </div>
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
          <table className="entry-table">
            <caption className="sr-only">Lançamentos do relatório</caption>
            <thead>
              <tr>
                <th scope="col">Data</th>
                {report.scope.canFilterByMember && <th scope="col">Pessoa</th>}
                <th scope="col">Projeto</th>
                <th scope="col">Work item</th>
                <th scope="col">Atividade</th>
                <th scope="col">Duração</th>
                <th scope="col">Faturável</th>
                <th scope="col">Semana</th>
                <th scope="col">Comentário</th>
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
                    #{row.workItemId} {row.workItemTitle ?? ""}
                  </td>
                  <td>
                    <span className="activity">
                      <span className="swatch" style={{ background: row.activityTypeColor ?? "transparent" }} />
                      {row.activityTypeName ?? "Não definido"}
                    </span>
                  </td>
                  <td>{formatHours(row.durationSeconds)}</td>
                  <td>{row.billable ? "Sim" : "Não"}</td>
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
