import type { ApiClient } from "./client";
import type { TimeEntryDto } from "./timer";

/** O que o administrador muda num lançamento; só vai o que mudou. */
export interface AdminEntryChanges {
  localDate?: string;
  /** "HH:MM" define o início; `null` apaga o horário. */
  startTime?: string | null;
  durationSeconds?: number;
  activityTypeId?: number | null;
  note?: string | null;
  billable?: boolean;
  /** Outro work item: vai com o projeto dele no Azure DevOps (GUID e nome). */
  workItemId?: number;
  projectId?: string;
  projectName?: string;
  title?: string;
  workItemType?: string;
  iterationPath?: string;
}

/** Correção de lançamento de qualquer pessoa (só administrador; semana aberta). */
export function adminUpdateEntry(client: ApiClient, entryId: string, revision: number, changes: AdminEntryChanges): Promise<TimeEntryDto> {
  return client.request<TimeEntryDto>(`/api/admin/entries/${entryId}`, {
    method: "PATCH",
    headers: { "If-Match": String(revision) },
    body: JSON.stringify(changes),
  });
}

export function adminDeleteEntry(client: ApiClient, entryId: string): Promise<void> {
  return client.request<void>(`/api/admin/entries/${entryId}`, { method: "DELETE" });
}
