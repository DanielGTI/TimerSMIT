import type { ApiClient } from "./client";

export interface TimerDto {
  id: string;
  workItemId: number;
  startedAtUtc: string;
  status: "active" | "stopped";
  activityTypeId: string | null;
}

export interface TimeEntryDto {
  id: string;
  workItemId: number;
  localDate: string;
  timezone: string;
  durationSeconds: number;
  source: "timer" | "manual";
  billable: boolean;
  activityTypeId: string | null;
  note: string | null;
  revision: number;
}

/** Chave única por tentativa de mutação — permite repetir a mesma chamada
 * com segurança (contracts/openapi.yaml, header Idempotency-Key). */
function idempotencyKey(): string {
  return crypto.randomUUID();
}

export function fetchActiveTimer(client: ApiClient): Promise<TimerDto | null> {
  return client.request<TimerDto | null>("/api/me/timer");
}

export interface StartTimerInput {
  projectId: string;
  projectName: string;
  workItemId: number;
  activityTypeId?: number;
  title?: string;
  workItemType?: string;
}

export function startTimer(client: ApiClient, input: StartTimerInput): Promise<TimerDto> {
  return client.request<TimerDto>("/api/me/timer", {
    method: "POST",
    headers: { "Idempotency-Key": idempotencyKey() },
    body: JSON.stringify(input),
  });
}

export interface StopTimerInput {
  timerId: string;
  note?: string;
  billable?: boolean;
}

export function stopTimer(client: ApiClient, input: StopTimerInput): Promise<TimeEntryDto[]> {
  return client.request<TimeEntryDto[]>("/api/me/timer/stop", {
    method: "POST",
    headers: { "Idempotency-Key": idempotencyKey() },
    body: JSON.stringify(input),
  });
}
