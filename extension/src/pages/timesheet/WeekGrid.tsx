import type { WeekDto, WeekEntryDto } from "../../lib/api/timesheet";
import { formatHours } from "../../lib/time/format";
import { dayMonth, weekdayShort } from "../../lib/time/weeks";

interface Row {
  key: string;
  workItemId: number;
  title: string | null;
  projectName: string;
  secondsByDate: Map<string, number>;
  totalSeconds: number;
}

/** Uma linha por work item; cada célula soma os segundos do dia (FR-005). */
function groupByWorkItem(entries: WeekEntryDto[]): Row[] {
  const rows = new Map<string, Row>();

  for (const entry of entries) {
    const key = `${entry.projectId}:${entry.workItemId}`;
    const row =
      rows.get(key) ??
      ({
        key,
        workItemId: entry.workItemId,
        title: entry.workItemTitle,
        projectName: entry.projectName,
        secondsByDate: new Map<string, number>(),
        totalSeconds: 0,
      } satisfies Row);

    row.secondsByDate.set(entry.localDate, (row.secondsByDate.get(entry.localDate) ?? 0) + entry.durationSeconds);
    row.totalSeconds += entry.durationSeconds;
    rows.set(key, row);
  }

  return [...rows.values()];
}

export function WeekGrid({ week, today }: { week: WeekDto; today: string }): JSX.Element {
  const rows = groupByWorkItem(week.entries);

  if (rows.length === 0) {
    return <p className="muted">Nenhum lançamento nesta semana.</p>;
  }

  return (
    <div className="table-scroll">
      <table className="grid-table">
        <caption className="sr-only">Horas por work item e dia</caption>
        <thead>
          <tr>
            <th scope="col">Work item</th>
            {week.days.map((day) => (
              <th key={day.date} scope="col" className={day.date === today ? "is-today" : undefined}>
                <span>{weekdayShort(day.date)}</span>
                <span className="muted">{dayMonth(day.date)}</span>
              </th>
            ))}
            <th scope="col">Total</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.key}>
              <th scope="row">
                <span>
                  #{row.workItemId} {row.title ?? ""}
                </span>
                <span className="muted">{row.projectName}</span>
              </th>
              {week.days.map((day) => {
                const seconds = row.secondsByDate.get(day.date) ?? 0;
                return (
                  <td key={day.date} className={day.date === today ? "is-today" : undefined}>
                    {seconds > 0 ? formatHours(seconds) : <span className="muted">–</span>}
                  </td>
                );
              })}
              <td className="is-total">{formatHours(row.totalSeconds)}</td>
            </tr>
          ))}
        </tbody>
        <tfoot>
          <tr>
            <th scope="row">Total do dia</th>
            {week.days.map((day) => (
              <td key={day.date} className={day.date === today ? "is-today" : undefined}>
                {day.totalSeconds > 0 ? formatHours(day.totalSeconds) : <span className="muted">–</span>}
              </td>
            ))}
            <td className="is-total">{formatHours(week.totalSeconds)}</td>
          </tr>
        </tfoot>
      </table>
    </div>
  );
}
