import { useEffect, useId, useState } from "react";
import { downloadProjectHoursXlsx, fetchProjectHours, type ProjectHoursDto, type ProjectHoursQuery } from "../../lib/api/projectHours";
import { saveBlob } from "../../lib/download";
import { todayLocalIso } from "../../lib/time/format";
import { addMonths, monthLabel, monthOf } from "../../lib/time/weeks";
import type { SectionProps } from "./sections";

const errorText = (failure: unknown): string => (failure instanceof Error ? failure.message : String(failure));

/** Segundos como no Excel: 587160 → "163:06:00". */
export function clock(seconds: number): string {
  const total = Math.max(0, Math.round(seconds));
  const pad = (value: number) => String(value).padStart(2, "0");
  return `${Math.floor(total / 3600)}:${pad(Math.floor((total % 3600) / 60))}:${pad(total % 60)}`;
}

const capitalize = (text: string): string => text.charAt(0).toUpperCase() + text.slice(1);

/**
 * Horas do mês por projeto e pessoa (administrador), no formato enviado ao
 * administrativo para o centro de custo de cada projeto: TOTAL = horas nos
 * projetos; Horas Ociosas = base de jornada − TOTAL. Exporta em Excel.
 */
export function ProjectHoursSection({ client }: SectionProps): JSX.Element {
  const ids = useId();
  // O relatório vai depois que o mês fecha: começa no mês anterior.
  const [filters, setFilters] = useState<ProjectHoursQuery>(() => ({
    month: addMonths(monthOf(todayLocalIso()), -1),
    dailyHours: 8,
    approvedOnly: false,
  }));
  const [report, setReport] = useState<ProjectHoursDto | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!/^\d{4}-\d{2}$/.test(filters.month) || !(filters.dailyHours >= 1 && filters.dailyHours <= 24)) return;
    let cancelled = false;
    setReport(null);
    setError(null);
    fetchProjectHours(client, filters)
      .then((data) => !cancelled && setReport(data))
      .catch((failure: unknown) => !cancelled && setError(errorText(failure)));
    return () => {
      cancelled = true;
    };
  }, [client, filters]);

  async function exportXlsx() {
    setBusy(true);
    setError(null);
    try {
      saveBlob(await downloadProjectHoursXlsx(client, filters), `horas_por_projeto_${filters.month}.xlsx`);
    } catch (failure) {
      setError(errorText(failure));
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="card" aria-label="Horas por projeto">
      <div className="toolbar">
        <h2>Horas por projeto</h2>
        <button type="button" className="btn btn--primary nowrap" disabled={busy || !report} onClick={() => void exportXlsx()}>
          Exportar Excel
        </button>
      </div>

      <div className="filters__grid">
        <div className="field">
          <label htmlFor={`${ids}-month`}>Mês</label>
          <input
            id={`${ids}-month`}
            className="input"
            type="month"
            value={filters.month}
            onChange={(e) => setFilters({ ...filters, month: e.target.value })}
          />
        </div>
        <div className="field">
          <label htmlFor={`${ids}-daily`}>Jornada diária (horas)</label>
          <input
            id={`${ids}-daily`}
            className="input"
            type="number"
            min={1}
            max={24}
            step={0.5}
            value={filters.dailyHours}
            onChange={(e) => setFilters({ ...filters, dailyHours: Number(e.target.value) })}
          />
        </div>
        <div className="field">
          <label className="checkbox">
            <input
              type="checkbox"
              checked={filters.approvedOnly}
              onChange={(e) => setFilters({ ...filters, approvedOnly: e.target.checked })}
            />{" "}
            Só semanas aprovadas
          </label>
        </div>
      </div>
      <p className="muted settings-hint">
        Base de jornada = dias úteis − feriados do calendário − folgas do banco de horas de cada pessoa, vezes a jornada
        diária. Projetos marcados como “conta como hora ociosa” (em Projetos) entram em Horas Ociosas.
      </p>

      {error && (
        <p className="alert" role="alert">
          {error}
        </p>
      )}

      {report === null ? (
        !error && <p className="muted">Carregando…</p>
      ) : report.people.length === 0 ? (
        <p className="muted">Nenhum lançamento em {monthLabel(report.month)}.</p>
      ) : (
        <>
          <h3 className="settings-subtitle">{capitalize(monthLabel(report.month))}</h3>
          <p className="muted">
            <em>{report.note}</em>
          </p>
          <div className="table-scroll">
            <table className="entry-table project-hours">
              <caption className="sr-only">Horas por projeto e pessoa em {monthLabel(report.month)}</caption>
              <thead>
                <tr>
                  <th scope="col">Projetos</th>
                  {report.people.map((person) => (
                    <th key={person.memberId} scope="col">
                      {person.name}
                    </th>
                  ))}
                  <th scope="col">TOTAL</th>
                </tr>
              </thead>
              <tbody>
                {report.projects.map((project) => (
                  <tr key={project.projectId}>
                    <th scope="row">{project.name}</th>
                    {report.people.map((person) => {
                      const seconds = project.seconds[person.memberId] ?? 0;
                      return <td key={person.memberId}>{seconds > 0 ? clock(seconds) : ""}</td>;
                    })}
                    <td>{clock(project.totalSeconds)}</td>
                  </tr>
                ))}
                <tr className="project-hours__idle">
                  <th scope="row">Horas Ociosas</th>
                  {report.people.map((person) => (
                    <td key={person.memberId}>{clock(person.idleSeconds)}</td>
                  ))}
                  <td>{clock(report.totals.idleSeconds)}</td>
                </tr>
                <tr className="project-hours__total">
                  <th scope="row">TOTAL</th>
                  {report.people.map((person) => (
                    <td key={person.memberId}>{clock(person.totalSeconds)}</td>
                  ))}
                  <td>{clock(report.totals.totalSeconds)}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </>
      )}
    </section>
  );
}
