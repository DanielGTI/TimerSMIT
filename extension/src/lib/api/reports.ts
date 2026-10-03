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
  workItemType: string | null;
  iterationPath: string | null;
  /** Hora local "HH:MM"; nulo em lançamento manual. */
  startTime: string | null;
  endTime: string | null;
  activityTypeId: string | null;
  activityTypeName: string | null;
  activityTypeColor: string | null;
  durationSeconds: number;
  billable: boolean;
  source: "timer" | "manual";
  note: string | null;
  /** Revisão do lançamento (If-Match da correção pelo administrador). */
  revision?: number;
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

/** Todas as linhas do filtro (até o limite do servidor) para a grade detalhada. */
export interface ReportDetailDto {
  scope: ReportDto["scope"];
  totals: ReportDto["totals"];
  rows: ReportRowDto[];
  /** Verdadeiro quando o período tem mais linhas do que o servidor devolve de uma vez. */
  truncated: boolean;
  /** Administrador: pode corrigir lançamentos de qualquer pessoa na grade. */
  canEditEntries?: boolean;
}

export interface ReportOptionsDto {
  scope: ReportDto["scope"];
  /** Algum projeto usa "faturável"; se não, a tela esconde filtro, totais e coluna. */
  billableInUse?: boolean;
  members: Array<{ id: string; name: string }>;
  projects: Array<{ id: string; name: string; usesBillable?: boolean }>;
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

export function fetchReportDetail(client: ApiClient, filters: ReportFilters): Promise<ReportDetailDto> {
  return client.request<ReportDetailDto>(`/api/reports/time/detail?${reportQuery(filters)}`);
}

export function fetchReportOptions(client: ApiClient): Promise<ReportOptionsDto> {
  return client.request<ReportOptionsDto>("/api/reports/options");
}

export function downloadReportCsv(client: ApiClient, filters: ReportFilters): Promise<Blob> {
  return client.download(`/api/reports/time.csv?${reportQuery(filters)}`);
}
