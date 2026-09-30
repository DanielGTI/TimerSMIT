import type { ApiClient } from "./client";
import type { TimeEntryDto } from "./timer";

export interface CreateManualEntryInput {
  projectId: string;
  projectName: string;
  workItemId: number;
  localDate: string;
  durationSeconds: number;
  activityTypeId?: number;
  note?: string;
  billable?: boolean;
  title?: string;
  workItemType?: string;
}

export function createManualEntry(client: ApiClient, input: CreateManualEntryInput): Promise<TimeEntryDto> {
  return client.request<TimeEntryDto>("/api/entries", {
    method: "POST",
    headers: { "Idempotency-Key": crypto.randomUUID() },
    body: JSON.stringify(input),
  });
}
