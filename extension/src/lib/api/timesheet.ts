import type { ApiClient } from "./client";
import type { TimeEntryDto } from "./timer";

export type WeekStatus = "open" | "submitted" | "rejected" | "approved";

export type AdditionalStatus = "pending" | "overtime" | "bank" | "payable";

/** Parte do lançamento fora do expediente (ou em fim de semana/feriado), já com os fatores. */
export interface AdditionalHoursDto {
  seconds: number;
  weightedSeconds: number;
  nightSeconds: number;
  dayType: "weekday" | "saturday" | "sunday" | "holiday";
  /** "pending" até o administrador classificar: para quem lançou, "a validar". */
  status: AdditionalStatus;
  /** O aprovador marcou como não autorizada (a hora continua registrada). */
  denied: { reason: string } | null;
}

export interface WeekEntryDto extends TimeEntryDto {
  projectId: string;
  projectName: string;
  workItemTitle: string | null;
  workItemType: string | null;
  activityTypeName: string | null;
  activityTypeColor: string | null;
  /** Nulo quando o lançamento não tem hora adicional. */
  additional?: AdditionalHoursDto | null;
}

export interface DayTotal {
  date: string;
  totalSeconds: number;
}

export interface DecisionDto {
  revision: number;
  decision: "approved" | "rejected" | "reopened";
  reason: string | null;
  approverName: string | null;
  selfDecision: boolean;
  decidedAt: string;
}

export interface WeekDto {
  weekStartDate: string;
  weekEndDate: string;
  status: WeekStatus;
  revision: number;
  submittedAt: string | null;
  /** Id da submissão (para reabrir); null enquanto a semana nunca foi enviada. */
  submissionId: string | null;
  /** Semana aprovada de um administrador: ele pode reabrir direto da folha. */
  canReopen: boolean;
  totalSeconds: number;
  additionalTotals?: { seconds: number; weightedSeconds: number; pendingSeconds: number };
  decisions: DecisionDto[];
  days: DayTotal[];
  entries: WeekEntryDto[];
}

export interface MonthDto {
  month: string;
  totalSeconds: number;
  days: DayTotal[];
  weeks: Array<{ weekStartDate: string; status: WeekStatus }>;
}

export function fetchWeek(client: ApiClient, weekStart: string): Promise<WeekDto> {
  return client.request<WeekDto>(`/api/me/weeks/${weekStart}`);
}

export function fetchMonth(client: ApiClient, month: string): Promise<MonthDto> {
  return client.request<MonthDto>(`/api/me/months/${month}`);
}

export function submitWeek(client: ApiClient, weekStart: string): Promise<WeekDto> {
  return client.request<WeekDto>(`/api/me/weeks/${weekStart}/submit`, {
    method: "POST",
    headers: { "Idempotency-Key": crypto.randomUUID() },
  });
}

/** Recolhe o envio (enviada → aberta) enquanto ninguém decidiu, para lançar de novo. */
export function recallWeek(client: ApiClient, weekStart: string): Promise<WeekDto> {
  return client.request<WeekDto>(`/api/me/weeks/${weekStart}/recall`, {
    method: "POST",
    headers: { "Idempotency-Key": crypto.randomUUID() },
  });
}

export interface EntryChanges {
  durationSeconds?: number;
  /** "HH:MM" define o início; `null` apaga o horário. */
  startTime?: string | null;
  note?: string;
  billable?: boolean;
}

export function updateEntry(
  client: ApiClient,
  entryId: string,
  revision: number,
  changes: EntryChanges,
): Promise<TimeEntryDto> {
  return client.request<TimeEntryDto>(`/api/entries/${entryId}`, {
    method: "PATCH",
    headers: { "If-Match": String(revision) },
    body: JSON.stringify(changes),
  });
}

export function deleteEntry(client: ApiClient, entryId: string): Promise<void> {
  return client.request<void>(`/api/entries/${entryId}`, { method: "DELETE" });
}
