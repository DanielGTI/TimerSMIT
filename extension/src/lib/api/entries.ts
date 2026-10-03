import type { ApiClient } from "./client";
import type { OvertimeItemDto } from "./overtime";
import type { TimeEntryDto } from "./timer";

export interface CreateManualEntryInput {
  projectId: string;
  projectName: string;
  workItemId: number;
  localDate: string;
  durationSeconds: number;
  /** Início opcional (hora local HH:MM); o fim é início + duração. */
  startTime?: string;
  activityTypeId?: number;
  note?: string;
  billable?: boolean;
  title?: string;
  workItemType?: string;
  iterationPath?: string;
  /** Perfil restrito: motivo e ciência da hora extra a confirmar. */
  overtimeReason?: string;
  overtimeAcknowledged?: boolean;
}

/** Perfil restrito: o que entrou e o que ficou a confirmar (só quando houve separação). */
export interface ManualEntryResult extends Partial<TimeEntryDto> {
  entries?: TimeEntryDto[];
  pendingOvertime?: OvertimeItemDto[];
}

export function createManualEntry(client: ApiClient, input: CreateManualEntryInput): Promise<ManualEntryResult> {
  return client.request<ManualEntryResult>("/api/entries", {
    method: "POST",
    headers: { "Idempotency-Key": crypto.randomUUID() },
    body: JSON.stringify(input),
  });
}
