import type { ApiClient } from "./client";

export type Role = "member" | "approver" | "manager" | "admin";

export interface PolicyDto {
  durationIncrementMinutes: number;
  dailyLimitHours: number;
  retroactiveWindowDays: number;
  commentRequired: boolean;
  version: number;
  effectiveFrom: string | null;
}

/** Regras de hora adicional (versionadas como as regras de lançamento). */
export interface OvertimeRulesDto {
  enabled: boolean;
  workdayStart: string;
  workdayEnd: string;
  factorWeekday: number;
  factorSaturday: number;
  factorSunday: number;
  factorHoliday: number;
  nightStart: string;
  nightEnd: string;
  nightPercent: number;
  nightReducedHour: boolean;
  requireTimeOfDay: boolean;
  /** Prazo para compensar o banco de horas (1 a 12 meses). */
  bankValidityMonths: number;
  /** O banco recebe as horas ponderadas (com o fator) em vez das horas de relógio. */
  bankWeighted: boolean;
  /** Limites que geram aviso, em horas (0 desliga). */
  alertDailyExtraHours: number;
  alertWeeklyHours: number;
  alertRestHours: number;
  version: number;
  effectiveFrom: string | null;
}

export type OvertimeRulesInput = Omit<OvertimeRulesDto, "version" | "effectiveFrom">;

export interface HolidayDto {
  id: string;
  date: string;
  name: string;
}

/** clt: hora extra, banco ou a pagar · pj: só a pagar · none: não controla jornada. */
export type HoursRegime = "clt" | "pj" | "none";

export interface SettingsActivityDto {
  id: string;
  name: string;
  color: string | null;
  enabled: boolean;
  defaultBillable: boolean;
}

export interface SettingsMemberDto {
  id: string;
  name: string;
  /** Situação na última sincronização com o Azure DevOps: `null` = nunca sincronizada. */
  directoryActive: boolean | null;
  hoursRegime: HoursRegime;
  roles: Array<{ id: string; role: Role; projectId: string | null; projectName: string | null }>;
}

export interface DesignationDto {
  id: string;
  memberId: string;
  memberName: string | null;
  approverId: string;
  approverName: string | null;
  projectId: string | null;
  projectName: string | null;
}

export interface SettingsDto {
  organization: { name: string; timezone: string };
  policy: PolicyDto;
  overtime: OvertimeRulesDto;
  holidays: HolidayDto[];
  projects: Array<{ id: string; name: string; enabled: boolean }>;
  activityTypes: SettingsActivityDto[];
  members: SettingsMemberDto[];
  /** Quando a lista de pessoas foi atualizada com o Azure DevOps pela última vez. */
  peopleSyncedAt: string | null;
  designations: DesignationDto[];
}

export type PolicyInput = Pick<
  PolicyDto,
  "durationIncrementMinutes" | "dailyLimitHours" | "retroactiveWindowDays" | "commentRequired"
>;

const send = (client: ApiClient, method: string, path: string, body?: unknown) =>
  client.request<SettingsDto>(`/api/settings${path}`, {
    method,
    ...(body === undefined ? {} : { body: JSON.stringify(body) }),
  });

export const fetchSettings = (client: ApiClient) => send(client, "GET", "");

export const updateTimezone = (client: ApiClient, timezone: string) =>
  send(client, "PUT", "/organization", { timezone });

export const updatePolicy = (client: ApiClient, policy: PolicyInput) => send(client, "PUT", "/policy", policy);

export const updateOvertimeRules = (client: ApiClient, rules: OvertimeRulesInput) =>
  send(client, "PUT", "/overtime-rules", rules);

export const addHoliday = (client: ApiClient, date: string, name: string) =>
  send(client, "POST", "/holidays", { date, name });

export const addNationalHolidays = (client: ApiClient, year: number) =>
  send(client, "POST", "/holidays/national", { year });

export const removeHoliday = (client: ApiClient, holidayId: string) => send(client, "DELETE", `/holidays/${holidayId}`);

export const setHoursRegime = (client: ApiClient, memberId: string, regime: HoursRegime) =>
  send(client, "PUT", `/members/${memberId}/hours-regime`, { regime });

export const setProjectEnabled = (client: ApiClient, projectId: string, enabled: boolean) =>
  send(client, "PATCH", `/projects/${projectId}`, { enabled });

export interface ActivityInput {
  name: string;
  color?: string | null;
  defaultBillable?: boolean;
}

export const createActivityType = (client: ApiClient, input: ActivityInput) =>
  send(client, "POST", "/activity-types", input);

export const updateActivityType = (
  client: ApiClient,
  id: string,
  changes: Partial<ActivityInput> & { enabled?: boolean },
) => send(client, "PATCH", `/activity-types/${id}`, changes);

export interface DirectoryPersonInput {
  identityId: string;
  displayName: string;
}

export const syncPeople = (client: ApiClient, people: DirectoryPersonInput[]) =>
  send(client, "POST", "/people/sync", { people });

export const grantRole = (client: ApiClient, memberId: string, role: Role, projectId: string | null) =>
  send(client, "POST", "/role-assignments", { memberId, role, ...(projectId ? { projectId } : {}) });

export const revokeRole = (client: ApiClient, assignmentId: string) =>
  send(client, "DELETE", `/role-assignments/${assignmentId}`);

export const designateApprover = (
  client: ApiClient,
  memberId: string,
  approverId: string,
  projectId: string | null,
  applyToPending: boolean,
) =>
  send(client, "POST", "/approver-assignments", {
    memberId,
    approverId,
    applyToPending,
    ...(projectId ? { projectId } : {}),
  });

export const removeDesignation = (client: ApiClient, assignmentId: string, applyToPending: boolean) =>
  send(client, "DELETE", `/approver-assignments/${assignmentId}`, { applyToPending });
