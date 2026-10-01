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
