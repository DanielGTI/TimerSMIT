import type { WeekAlertDto } from "../lib/api/timesheet";
import { formatHours } from "../lib/time/format";
import { dayMonth, weekdayShort } from "../lib/time/weeks";

/** "2026-09-29 23:00" → "29/09 23:00". */
function moment(value: string | undefined): string {
  if (!value) return "—";
  const [date, time] = value.split(" ");
  return `${dayMonth(date)} ${time}`;
}

export function alertText(alert: WeekAlertDto): string {
  const day = `${weekdayShort(alert.date)} ${dayMonth(alert.date)}`;

  switch (alert.type) {
    case "daily_extra":
      return `${day}: ${formatHours(alert.seconds)} de horas extras no dia (limite ${formatHours(alert.limitSeconds)}).`;
    case "weekly_hours":
      return `Semana com ${formatHours(alert.seconds)} trabalhadas (limite ${formatHours(alert.limitSeconds)}).`;
    case "rest":
      return `${day}: só ${formatHours(alert.seconds)} de descanso entre ${moment(alert.previousEnd)} e ${moment(alert.nextStart)} (mínimo ${formatHours(alert.limitSeconds)}).`;
  }
}

/**
 * Avisos de limite de jornada da semana, só para administradores (o servidor
 * só os envia a eles): conferência informativa, não impedem a aprovação.
 */
export function WeekAlerts({ alerts }: { alerts: WeekAlertDto[] | undefined }): JSX.Element | null {
  if (!alerts || alerts.length === 0) return null;

  return (
    <div className="banner banner--warning" role="note" aria-label="Avisos de jornada">
      <strong>Avisos de jornada</strong>{" "}
      <span className="muted">(informativo, para conferência do administrador)</span>
      <ul>
        {alerts.map((alert) => (
          <li key={`${alert.type}-${alert.date}`}>{alertText(alert)}</li>
        ))}
      </ul>
    </div>
  );
}
