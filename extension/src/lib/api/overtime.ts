import type { ApiClient } from "./client";
import type { CurrentSession } from "./me";

export type OvertimeProfile = "preapproved" | "standard" | "restricted";

export const OVERTIME_PROFILE_LABELS: Record<OvertimeProfile, string> = {
  preapproved: "Pré-aprovada",
  standard: "Padrão",
  restricted: "Restrita",
};

export const OVERTIME_PROFILE_HINTS: Record<OvertimeProfile, string> = {
  preapproved: "Lança hora extra sem informar antes; a hora já vem como pré-aprovada.",
  standard: "Lança normalmente; sem hora extra informada e aprovada, fica sujeita à aprovação.",
  restricted: "Hora fora do expediente sem pedido aprovado vira hora extra a confirmar e só conta se o aprovador confirmar.",
};

/** Hora extra informada (`request`) ou hora extra a confirmar do perfil restrito (`confirmation`). */
export interface OvertimeItemDto {
  id: string;
  kind: "request" | "confirmation";
  dateFrom: string;
  dateTo: string;
  secondsPerDay: number;
  /** Horário previsto (informada) ou o trecho real (a confirmar); "HH:MM" ou "HH:MM:SS". */
  startTime: string | null;
  endTime: string | null;
  reason: string;
  suggestedDestination: "overtime" | "bank" | null;
  afterTheFact: boolean;
  status: "pending" | "approved" | "rejected" | "cancelled";
  approvedSecondsPerDay: number | null;
  decidedBy: string | null;
  decidedAt: string | null;
  decisionNote: string | null;
  projectName: string | null;
  workItemId: number | null;
  note: string | null;
  createdAt: string | null;
}

/** Na caixa do aprovador: de quem é e, na hora a confirmar, o estado da semana. */
export interface PendingOvertimeDto extends OvertimeItemDto {
  person: { id: string; displayName: string };
  weekStatus: "open" | "submitted" | "rejected" | "approved" | null;
}

export interface MyOvertimeDto {
  /** CLT com o controle de horas adicionais ligado: só aí existe "Informar hora extra". */
  applies: boolean;
  profile: OvertimeProfile;
  items: OvertimeItemDto[];
}

export interface OvertimeInput {
  dateFrom: string;
  dateTo?: string;
  secondsPerDay: number;
  startTime?: string;
  endTime?: string;
  reason: string;
  suggestedDestination?: "overtime" | "bank";
}

/** O que acontece se o lançamento for salvo (aviso e, no perfil restrito, o que fica a confirmar). */
export interface OvertimeCheckDto {
  applies: boolean;
  profile: OvertimeProfile;
  additionalSeconds: number;
  coveredSeconds: number;
  uncoveredSeconds: number;
  entries: Array<{ startTime: string | null; endTime: string | null; seconds: number }>;
  pending: Array<{ startTime: string | null; endTime: string | null; seconds: number }>;
}

/** Controle de hora extra da pessoa: só CLT com o controle de horas adicionais ligado. */
export function overtimeControl(session: CurrentSession): { profile: OvertimeProfile } | null {
  if (!session.overtime?.enabled || (session.hoursRegime ?? "clt") !== "clt") return null;
  return { profile: session.overtimeProfile ?? "standard" };
}

export function fetchMyOvertime(client: ApiClient): Promise<MyOvertimeDto> {
  return client.request<MyOvertimeDto>("/api/me/overtime");
}

export function informOvertime(client: ApiClient, input: OvertimeInput): Promise<OvertimeItemDto> {
  return client.request<OvertimeItemDto>("/api/me/overtime", { method: "POST", body: JSON.stringify(input) });
}

export function cancelOvertime(client: ApiClient, id: string): Promise<OvertimeItemDto> {
  return client.request<OvertimeItemDto>(`/api/me/overtime/${id}/cancel`, { method: "POST" });
}

export function checkOvertime(
  client: ApiClient,
  input: { date: string; startTime: string | null; durationSeconds: number },
): Promise<OvertimeCheckDto> {
  const query = new URLSearchParams({ date: input.date, durationSeconds: String(input.durationSeconds) });
  if (input.startTime) query.set("startTime", input.startTime);
  return client.request<OvertimeCheckDto>(`/api/me/overtime-check?${query.toString()}`);
}

export function fetchPendingOvertime(client: ApiClient): Promise<PendingOvertimeDto[]> {
  return client.request<PendingOvertimeDto[]>("/api/overtime/pending");
}

export function decideOvertime(
  client: ApiClient,
  id: string,
  input: { approve: boolean; approvedSecondsPerDay?: number; note?: string },
): Promise<OvertimeItemDto> {
  return client.request<OvertimeItemDto>(`/api/overtime/${id}/decision`, { method: "POST", body: JSON.stringify(input) });
}

/** "HH:MM:SS" → "HH:MM" (o timer grava com segundos). */
export const shortTime = (time: string | null): string => (time ? time.slice(0, 5) : "");

const brDate = (iso: string): string => {
  const [year, month, day] = iso.split("-");
  return `${day}/${month}/${year}`;
};

/** "07/10/2026" ou "07/10/2026 a 09/10/2026". */
export const periodLabel = (item: Pick<OvertimeItemDto, "dateFrom" | "dateTo">): string =>
  item.dateFrom === item.dateTo ? brDate(item.dateFrom) : `${brDate(item.dateFrom)} a ${brDate(item.dateTo)}`;

export const DESTINATION_LABELS: Record<"overtime" | "bank", string> = {
  overtime: "Hora extra (paga)",
  bank: "Banco de horas",
};
