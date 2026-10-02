import { StatusBadge } from "../../components/StatusBadge";
import type { DayTotal, MonthDto, WeekStatus } from "../../lib/api/timesheet";
import { formatHours } from "../../lib/time/format";
import { dayMonth, dayOfMonth, monthGrid, monthLabel, weekdayShort } from "../../lib/time/weeks";

interface MonthCalendarProps {
  month: string;
  data: MonthDto | null;
  /** Totais da semana aberta na folha: dão as horas dos dias dela que caem fora do mês. */
  selectedWeekDays?: DayTotal[];
  today: string;
  onMonthChange: (delta: number) => void;
  onPickDay: (date: string) => void;
  /** "+" do dia: abre o lançamento com a data pronta (semana enviada pede antes para cancelar o envio). */
  onAddTime?: (date: string, weekStatus: WeekStatus) => void;
}

/**
 * Visão mensal: cada dia mostra suas horas na data local correta. A linha
 * inteira é uma semana, com o estado dela — a unidade de aprovação não muda
 * mesmo quando a semana atravessa dois meses. O "+" no canto do dia lança
 * horas naquela data; em semana enviada ou aprovada o "+" leva ao pedido de cancelar o envio ou reabrir. Os dias de
 * outro mês que completam a primeira e a última linha também abrem a semana
 * e têm "+", só aparecem mais apagados.
 */
export function MonthCalendar({
  month,
  data,
  selectedWeekDays,
  today,
  onMonthChange,
  onPickDay,
  onAddTime,
}: MonthCalendarProps): JSX.Element {
  const secondsByDate = new Map((data?.days ?? []).map((day) => [day.date, day.totalSeconds]));
  // O resumo do mês só traz os dias dele; fora do mês, só a semana aberta tem totais conhecidos.
  for (const day of selectedWeekDays ?? []) {
    if (!day.date.startsWith(month)) secondsByDate.set(day.date, day.totalSeconds);
  }
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
            const canAdd = onAddTime !== undefined;
            return (
              <tr key={start}>
                <th scope="row">
                  <StatusBadge status={weekStatus} />
                </th>
                {weekDays.map((date) => {
                  const inMonth = date.startsWith(month);
                  const seconds = secondsByDate.get(date);
                  const known = seconds !== undefined;
                  return (
                    <td key={date} className={date === today ? "calendar__cell is-today" : "calendar__cell"}>
                      <button
                        type="button"
                        className={inMonth ? "calendar__day" : "calendar__day calendar__day--outside"}
                        // Fora do mês o número sozinho repetiria o de um dia do mês ("dia 2").
                        aria-label={`Abrir a semana do dia ${inMonth ? dayOfMonth(date) : dayMonth(date)}`}
                        onClick={() => onPickDay(date)}
                      >
                        <span className="calendar__number">{dayOfMonth(date)}</span>
                        <span className={known && seconds > 0 ? "calendar__hours" : "calendar__hours muted"}>
                          {known && seconds > 0 ? formatHours(seconds) : inMonth || known ? "–" : null}
                        </span>
                      </button>
                      {canAdd && (
                        <button
                          type="button"
                          className="calendar__add"
                          aria-label={`Adicionar tempo em ${dayMonth(date)}`}
                          title={
                            weekStatus === "submitted"
                              ? "Semana enviada: cancele o envio para lançar"
                              : weekStatus === "approved"
                                ? "Semana aprovada: é preciso reabri-la para lançar"
                                : "Adicionar tempo"
                          }
                          onClick={() => onAddTime(date, weekStatus)}
                        >
                          +
                        </button>
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
