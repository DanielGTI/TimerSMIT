import type { WeekStatus } from "../lib/api/timesheet";

export const WEEK_STATUS_LABELS: Record<WeekStatus, string> = {
  open: "Aberta",
  submitted: "Enviada",
  rejected: "Rejeitada",
  approved: "Aprovada",
};

export function StatusBadge({ status }: { status: WeekStatus }): JSX.Element {
  return <span className={`badge badge--${status}`}>{WEEK_STATUS_LABELS[status]}</span>;
}
