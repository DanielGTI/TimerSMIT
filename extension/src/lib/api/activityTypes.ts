import type { ApiClient } from "./client";

export interface ActivityTypeDto {
  id: string;
  name: string;
  color: string | null;
  defaultBillable: boolean;
}

export function fetchActivityTypes(client: ApiClient): Promise<ActivityTypeDto[]> {
  return client.request<ActivityTypeDto[]>("/api/activity-types");
}
