import type { ApiClient } from "./client";
import type { WeekDto, WeekStatus } from "./timesheet";

export interface PersonDto {
  id: string;
  displayName: string;
}

export interface PendingApprovalDto {
  id: string;
  weekStartDate: string;
  status: WeekStatus;
  revision: number;
  submittedAt: string | null;
  totalSeconds: number;
  entryCount: number;
  submitter: PersonDto;
  ownWeek: boolean;
}

export interface DecidedApprovalDto {
  id: string;
  weekStartDate: string;
  status: WeekStatus;
  decision: "approved" | "rejected";
  reason: string | null;
  revision: number;
  decidedAt: string;
  submitter: PersonDto;
}

export interface ApprovalDetailDto {
  submission: {
    id: string;
    weekStartDate: string;
    status: WeekStatus;
    revision: number;
    submittedAt: string | null;
    submitter: PersonDto;
  };
  week: WeekDto;
  permissions: { canDecide: boolean; canReopen: boolean; ownWeek: boolean };
}

export function fetchPendingApprovals(client: ApiClient): Promise<PendingApprovalDto[]> {
  return client.request<PendingApprovalDto[]>("/api/approvals?view=pending");
}

export function fetchDecidedApprovals(client: ApiClient): Promise<DecidedApprovalDto[]> {
  return client.request<DecidedApprovalDto[]>("/api/approvals?view=decided");
}

export function fetchApproval(client: ApiClient, id: string): Promise<ApprovalDetailDto> {
  return client.request<ApprovalDetailDto>(`/api/approvals/${id}`);
}

export interface DecisionInput {
  decision: "approve" | "reject";
  revision: number;
  reason?: string;
  /** Ao aprovar: horas adicionais que o aprovador não autoriza, com motivo. */
  unauthorized?: Array<{ entryId: string; reason: string }>;
}

export function decideApproval(client: ApiClient, id: string, input: DecisionInput): Promise<ApprovalDetailDto> {
  return client.request<ApprovalDetailDto>(`/api/approvals/${id}/decision`, {
    method: "POST",
    headers: { "Idempotency-Key": crypto.randomUUID() },
    body: JSON.stringify(input),
  });
}

export function reopenApproval(client: ApiClient, id: string, reason: string): Promise<ApprovalDetailDto> {
  return client.request<ApprovalDetailDto>(`/api/approvals/${id}/reopen`, {
    method: "POST",
    headers: { "Idempotency-Key": crypto.randomUUID() },
    body: JSON.stringify({ reason }),
  });
}
