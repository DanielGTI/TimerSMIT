import type { ApiClient } from "./client";
import type { WeekStatus } from "./timesheet";

export interface ReportFilters {
  from: string;
  to: string;
  projectId?: string;
  memberId?: string;
  workItemId?: string;
  activityTypeId?: string;
  billable?: "true" | "false";
  status?: WeekStatus;
}

export interface ReportRowDto {
  id: string;
  localDate: string;
  memberId: string;
  memberName: string;
  projectId: string;
  projectName: string;
  workItemId: number;
  workItemTitle: string | null;
  activityTypeId: string | null;
  activityTypeName: string | null;
  activityTypeColor: string | null;
  durationSeconds: number;
  billable: boolean;
  source: "timer" | "manual";
  note: string | null;
  weekStatus: WeekStatus;
}

export interface GroupTotalDto {
  id: string | null;
  name: string;
  totalSeconds: number;
  entryCount: number;
}

export interface ReportDto {
  scope: { level: "all" | "projects" | "self"; canFilterByMember: boolean };
  totals: { totalSeconds: number; billableSeconds: number; nonBillableSeconds: number; entryCount: number };
  byMember: GroupTotalDto[];
  byProject: GroupTotalDto[];
  byActivity: GroupTotalDto[];
  rows: ReportRowDto[];
  pagination: { page: number; perPage: number; total: number; lastPage: number };
}

export interface ReportOptionsDto {
  scope: ReportDto["scope"];
  members: Array<{ id: string; name: string }>;
  projects: Array<{ id: string; name: string }>;
  activityTypes: Array<{ id: string; name: string; color: string | null; enabled: boolean }>;
}

/** Só os filtros preenchidos vão na URL — o servidor trata ausente como "todos". */
export function reportQuery(filters: ReportFilters, extra: Record<string, string | number> = {}): string {
  const params = new URLSearchParams();

  for (const [key, value] of Object.entries({ ...filters, ...extra })) {
    if (value !== undefined && value !== "") {
      params.set(key, String(value));
    }
  }

  return params.toString();
}

export function fetchReport(client: ApiClient, filters: ReportFilters, page: number, perPage = 50): Promise<ReportDto> {
  return client.request<ReportDto>(`/api/reports/time?${reportQuery(filters, { page, perPage })}`);
}

export function fetchReportOptions(client: ApiClient): Promise<ReportOptionsDto> {
  return client.request<ReportOptionsDto>("/api/reports/options");
}

export function downloadReportCsv(client: ApiClient, filters: ReportFilters): Promise<Blob> {
  return client.download(`/api/reports/time.csv?${reportQuery(filters)}`);
}
