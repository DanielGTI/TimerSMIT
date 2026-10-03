import type { ApiClient } from "./client";
import type { HoursRegime } from "./settings";
import type { AdditionalHoursDto, AdditionalStatus } from "./timesheet";

export type Classification = Exclude<AdditionalStatus, "pending">;

/** Uma linha da fila: hora adicional de uma semana já aprovada. */
export interface AdditionalHoursItemDto {
  entryId: string;
  memberId: string;
  memberName: string;
  regime: HoursRegime;
  localDate: string;
  startTime: string | null;
  endTime: string | null;
  projectName: string;
  workItemId: number;
  workItemTitle: string | null;
  note: string | null;
  durationSeconds: number;
  additional: AdditionalHoursDto;
  classifiedAt: string | null;
  classificationNote: string | null;
}

export interface AdditionalHoursListDto {
  from: string;
  to: string;
  items: AdditionalHoursItemDto[];
}

export interface AdditionalHoursFilters {
  from: string;
  to: string;
  status: "pending" | "classified" | "all";
  memberId?: string;
}

export function fetchAdditionalHours(client: ApiClient, filters: AdditionalHoursFilters): Promise<AdditionalHoursListDto> {
  const query = new URLSearchParams({ from: filters.from, to: filters.to, status: filters.status });
  if (filters.memberId) query.set("memberId", filters.memberId);
  return client.request<AdditionalHoursListDto>(`/api/additional-hours?${query.toString()}`);
}

/** `pending` desfaz a classificação (a hora volta a "a validar"). */
export function classifyAdditionalHours(
  client: ApiClient,
  entryIds: string[],
  classification: Classification | "pending",
  note?: string,
): Promise<{ updated: number }> {
  return client.request<{ updated: number }>("/api/additional-hours/classify", {
    method: "POST",
    body: JSON.stringify({ entryIds: entryIds.map(Number), classification, ...(note ? { note } : {}) }),
  });
}

export const CLASSIFICATION_LABELS: Record<AdditionalStatus, string> = {
  pending: "Horas adicionais a validar",
  overtime: "Hora extra",
  bank: "Banco de horas",
  payable: "A pagar",
};

export const DAY_TYPE_LABELS: Record<AdditionalHoursDto["dayType"], string> = {
  weekday: "Dia útil, fora do expediente",
  saturday: "Sábado",
  sunday: "Domingo",
  holiday: "Feriado",
};

export type ClosingCategory = "overtime" | "payable" | "bank" | "pending" | "unapproved";

export interface ClosingHoursDto {
  seconds: number;
  nightSeconds: number;
  weightedSeconds: number;
}

export interface ClosingLineDto extends ClosingHoursDto {
  category: ClosingCategory;
  /** Fator do dia (1,5; 2...); null para o que ainda não foi decidido. */
  factor: number | null;
}

export interface ClosingMemberDto {
  memberId: string;
  memberName: string;
  regime: HoursRegime;
  totals: Record<ClosingCategory, ClosingHoursDto>;
  lines: ClosingLineDto[];
  /** Movimento do banco no mês; null se a pessoa não tem banco. */
  bank: {
    creditedSeconds: number;
    timeOffSeconds: number;
    payoutSeconds: number;
    adjustmentSeconds: number;
    expiredSeconds: number;
    balanceSeconds: number;
  } | null;
  alerts: { daily_extra: number; weekly_hours: number; rest: number };
  deniedCount: number;
  /** Hora adicional sem pedido aprovado e hora a confirmar recusada (informativo). */
  overtime?: { withoutRequestSeconds: number; refusedSeconds: number };
}

export interface ClosingDto {
  month: string;
  from: string;
  to: string;
  members: ClosingMemberDto[];
}

/** Fechamento do mês (YYYY-MM) para o DP. */
export function fetchClosing(client: ApiClient, month: string): Promise<ClosingDto> {
  return client.request<ClosingDto>(`/api/additional-hours/closing?month=${month}`);
}

export function downloadClosingCsv(client: ApiClient, month: string): Promise<Blob> {
  return client.download(`/api/additional-hours/closing.csv?month=${month}`);
}
