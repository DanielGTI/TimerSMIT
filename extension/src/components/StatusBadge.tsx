import type { WeekStatus } from "../lib/api/timesheet";

const LABELS: Record<WeekStatus, string> = {
  open: "Aberta",
  submitted: "Enviada",
  rejected: "Rejeitada",
  approved: "Aprovada",
};

export function StatusBadge({ status }: { status: WeekStatus }): JSX.Element {
  return <span className={`badge badge--${status}`}>{LABELS[status]}</span>;
}
