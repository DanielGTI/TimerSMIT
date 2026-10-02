import type { ApiClient } from "./client";

/** Regras de lançamento em vigor na organização (as mesmas que o servidor aplica). */
export interface PolicyRules {
  durationIncrementMinutes: number;
  dailyLimitHours: number;
  retroactiveWindowDays: number;
  commentRequired: boolean;
}

export interface CurrentSession {
  tenantId: string;
  organizationName: string;
  memberId: string;
  displayName: string;
  policy?: PolicyRules;
}

export function fetchCurrentSession(client: ApiClient): Promise<CurrentSession> {
  return client.request<CurrentSession>("/api/me");
}
