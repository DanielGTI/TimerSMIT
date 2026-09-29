import type { ApiClient } from "./client";

export interface CurrentSession {
  tenantId: string;
  organizationName: string;
  memberId: string;
  displayName: string;
}

export function fetchCurrentSession(client: ApiClient): Promise<CurrentSession> {
  return client.request<CurrentSession>("/api/me");
}
