import type { ApiClient } from "./client";
import type { OvertimeProfile } from "./overtime";

/** Regras de lançamento em vigor na organização (as mesmas que o servidor aplica). */
export interface PolicyRules {
  durationIncrementMinutes: number;
  /** Duração mínima do lançamento manual (1 = sem mínimo). */
  minDurationMinutes?: number;
  dailyLimitHours: number;
  retroactiveWindowDays: number;
  commentRequired: boolean;
}

export interface CurrentSession {
  tenantId: string;
  organizationName: string;
  memberId: string;
  displayName: string;
  hoursRegime?: "clt" | "pj" | "none";
  /** Perfil de hora extra: pré-aprovada, padrão ou restrita. */
  overtimeProfile?: OvertimeProfile;
  policy?: PolicyRules;
  /** Projetos (id do Azure DevOps) que cobram o cliente por hora: só neles aparece "faturável". */
  billableProjectIds?: string[];
  /** `requireTimeOfDay`: o lançamento manual precisa de De/Até. */
  overtime?: {
    enabled: boolean;
    requireTimeOfDay: boolean;
    workdayStart: string;
    workdayEnd: string;
  };
}

export function fetchCurrentSession(client: ApiClient): Promise<CurrentSession> {
  return client.request<CurrentSession>("/api/me");
}
