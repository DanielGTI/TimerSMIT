import { StatusBadge } from "../../components/StatusBadge";
import type { MonthDto } from "../../lib/api/timesheet";
import { formatHours } from "../../lib/time/format";
import { dayMonth, dayOfMonth, monthGrid, monthLabel, weekdayShort } from "../../lib/time/weeks";

interface MonthCalendarProps {
  month: string;
  data: MonthDto | null;
  selectedWeekStart: string;
  today: string;
  onMonthChange: (delta: number) => void;
  onPickDay: (date: string) => void;
  /** "+" do dia: abre o lançamento com a data pronta (só em semanas editáveis). */
  onAddTime?: (date: string) => void;
}

/**
 * Visão mensal: cada dia mostra suas horas na data local correta. A linha
 * inteira é uma semana, com o estado dela — a unidade de aprovação não muda
 * mesmo quando a semana atravessa dois meses. O "+" no canto do dia lança
 * horas naquela data; semana enviada ou aprovada não tem "+".
 */
export function MonthCalendar({
  month,
  data,
  selectedWeekStart,
  today,
  onMonthChange,
  onPickDay,
  onAddTime,
}: MonthCalendarProps): JSX.Element {
  const secondsByDate = new Map((data?.days ?? []).map((day) => [day.date, day.totalSeconds]));
  const statusByWeek = new Map((data?.weeks ?? []).map((week) => [week.weekStartDate, week.status]));
  const headers = monthGrid(month)[0].map(weekdayShort);

  return (
    <section className="card" aria-label="Resumo mensal">
      <div className="toolbar">
        <h2>Resumo mensal</h2>
        <div className="toolbar__group">
          <button type="button" className="btn btn--chip btn--icon" aria-label="Mês anterior" onClick={() => onMonthChange(-1)}>
            ‹
          </button>
          <strong className="toolbar__label">{monthLabel(month)}</strong>
          <button type="button" className="btn btn--chip btn--icon" aria-label="Próximo mês" onClick={() => onMonthChange(1)}>
            ›
          </button>
        </div>
      </div>

      <table className="calendar">
        <caption className="sr-only">Horas por dia em {monthLabel(month)}</caption>
        <thead>
          <tr>
            <th scope="col">Semana</th>
            {headers.map((header) => (
              <th key={header} scope="col">
                {header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {monthGrid(month).map((weekDays) => {
            const start = weekDays[0];
            const weekStatus = statusByWeek.get(start) ?? "open";
            const canAdd = onAddTime !== undefined && (weekStatus === "open" || weekStatus === "rejected");
            return (
              <tr key={start} className={start === selectedWeekStart ? "is-selected" : undefined}>
                <th scope="row">
                  <StatusBadge status={weekStatus} />
                </th>
                {weekDays.map((date) => {
                  const inMonth = date.startsWith(month);
                  const seconds = secondsByDate.get(date) ?? 0;
                  return (
                    <td key={date} className={date === today ? "calendar__cell is-today" : "calendar__cell"}>
                      {inMonth ? (
                        <>
                          <button
                            type="button"
                            className="calendar__day"
                            aria-label={`Abrir a semana do dia ${dayOfMonth(date)}`}
                            onClick={() => onPickDay(date)}
                          >
                            <span className="calendar__number">{dayOfMonth(date)}</span>
                            <span className={seconds > 0 ? "calendar__hours" : "calendar__hours muted"}>
                              {seconds > 0 ? formatHours(seconds) : "–"}
                            </span>
                          </button>
                          {canAdd && (
                            <button
                              type="button"
                              className="calendar__add"
                              aria-label={`Adicionar tempo em ${dayMonth(date)}`}
                              title="Adicionar tempo"
                              onClick={() => onAddTime(date)}
                            >
                              +
                            </button>
                          )}
                        </>
                      ) : (
                        <span className="calendar__outside" aria-hidden="true">
                          {dayOfMonth(date)}
                        </span>
                      )}
                    </td>
                  );
                })}
              </tr>
            );
          })}
        </tbody>
      </table>

      <p className="muted calendar__total">
        Total do mês: <strong>{formatHours(data?.totalSeconds ?? 0)}</strong>
      </p>
    </section>
  );
}
