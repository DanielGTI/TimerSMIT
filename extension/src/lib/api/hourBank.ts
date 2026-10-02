import type { ApiClient } from "./client";
import type { HoursRegime } from "./settings";

export type MovementKind = "time_off" | "payout" | "adjustment";

/** credit: horas da semana aprovada · expiry: o que sobrou de um crédito venceu (vira hora extra a pagar). */
export type HourBankEventType = "credit" | "expiry" | MovementKind;

export interface HourBankSummaryDto {
  balanceSeconds: number;
  /** Venceu sem ser usado: deve ser pago como hora extra. */
  expiredSeconds: number;
  /** Vence nos próximos 30 dias. */
  expiringSoonSeconds: number;
  nextExpiry: string | null;
  /** Uso sem horas para cobrir (não deveria acontecer; aparece se o regime ou os dados mudaram). */
  uncoveredSeconds: number;
}

export interface HourBankEventDto {
  id: string;
  type: HourBankEventType;
  date: string;
  /** Com sinal: positivo entra, negativo sai. */
  seconds: number;
  /** Saldo depois desta linha. */
  balanceSeconds: number;
  note: string | null;
  /** Só em folga, pagamento e ajuste: permite desfazer. */
  movementId: string | null;
  createdBy: string | null;
  entryId: string | null;
  workItemId: number | null;
  workItemTitle: string | null;
  projectName: string | null;
  /** Horas de relógio que geraram o crédito (o crédito pode ter o fator). */
  additionalSeconds: number | null;
  expiresOn: string | null;
  remainingSeconds: number | null;
  /** Na linha de vencimento: dia do crédito que venceu. */
  creditDate: string | null;
  uncoveredSeconds: number | null;
}

export interface HourBankStatementDto {
  member: { id: string; name: string; regime: HoursRegime };
  validityMonths: number;
  summary: HourBankSummaryDto;
  /** Em ordem cronológica. */
  events: HourBankEventDto[];
}

export interface HourBankMemberDto {
  memberId: string;
  memberName: string;
  regime: HoursRegime;
  summary: HourBankSummaryDto;
}

export interface HourBankMovementInput {
  kind: MovementKind;
  /** Só para ajuste: para mais (credit) ou para menos (debit). */
  direction?: "credit" | "debit";
  seconds: number;
  localDate: string;
  note: string;
}

export function fetchMyHourBank(client: ApiClient): Promise<HourBankStatementDto> {
  return client.request<HourBankStatementDto>("/api/me/hour-bank");
}

export async function fetchHourBankOverview(client: ApiClient): Promise<HourBankMemberDto[]> {
  return (await client.request<{ members: HourBankMemberDto[] }>("/api/hour-bank")).members;
}

export function fetchHourBankStatement(client: ApiClient, memberId: string): Promise<HourBankStatementDto> {
  return client.request<HourBankStatementDto>(`/api/hour-bank/${memberId}`);
}

export function addHourBankMovement(client: ApiClient, memberId: string, input: HourBankMovementInput): Promise<HourBankStatementDto> {
  return client.request<HourBankStatementDto>(`/api/hour-bank/${memberId}/movements`, {
    method: "POST",
    headers: { "Idempotency-Key": crypto.randomUUID() },
    body: JSON.stringify(input),
  });
}

export function removeHourBankMovement(client: ApiClient, movementId: string): Promise<HourBankStatementDto> {
  return client.request<HourBankStatementDto>(`/api/hour-bank/movements/${movementId}`, { method: "DELETE" });
}

export const EVENT_LABELS: Record<HourBankEventType, string> = {
  credit: "Horas adicionais",
  time_off: "Folga",
  payout: "Pago em folha",
  adjustment: "Ajuste",
  expiry: "Vencido (hora extra a pagar)",
};
