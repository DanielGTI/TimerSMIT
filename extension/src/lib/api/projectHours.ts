import type { ApiClient } from "./client";

export interface ProjectHoursPersonDto {
  memberId: string;
  name: string;
  /** Dias úteis − feriados − folgas do banco dela, × jornada diária. */
  baseSeconds: number;
  timeOff: Array<{ date: string; seconds: number }>;
  /** Horas nos projetos (sem os que contam como ociosa). */
  totalSeconds: number;
  idleProjectSeconds: number;
  /** Base − total, nunca negativo. */
  idleSeconds: number;
}

export interface ProjectHoursRowDto {
  projectId: string;
  name: string;
  /** Conta como hora ociosa: a linha vem zerada. */
  countsAsIdle: boolean;
  /** Por `memberId`. */
  seconds: Record<string, number>;
  totalSeconds: number;
}

export interface ProjectHoursDto {
  month: string;
  from: string;
  to: string;
  dailyHours: number;
  approvedOnly: boolean;
  weekdays: number;
  holidays: Array<{ date: string; name: string }>;
  calendarBaseSeconds: number;
  idleProjects: string[];
  people: ProjectHoursPersonDto[];
  projects: ProjectHoursRowDto[];
  totals: { totalSeconds: number; idleSeconds: number };
  /** Ex.: "Base de jornada: 168h (23 dias úteis; 09/07 feriado; 10/07 banco de horas)." */
  note: string;
}

export interface ProjectHoursQuery {
  month: string;
  dailyHours: number;
  approvedOnly: boolean;
}

function query({ month, dailyHours, approvedOnly }: ProjectHoursQuery): string {
  const params = new URLSearchParams({ month, dailyHours: String(dailyHours) });
  if (approvedOnly) params.set("approvedOnly", "1");
  return params.toString();
}

export function fetchProjectHours(client: ApiClient, filters: ProjectHoursQuery): Promise<ProjectHoursDto> {
  return client.request<ProjectHoursDto>(`/api/project-hours?${query(filters)}`);
}

export function downloadProjectHoursXlsx(client: ApiClient, filters: ProjectHoursQuery): Promise<Blob> {
  return client.download(`/api/project-hours.xlsx?${query(filters)}`);
}
