import type { ApiClient } from "./client";

export interface ImportPersonDto {
  name: string;
  rows: number;
  seconds: number;
  memberId: string | null;
  memberName: string | null;
  /** Como a pessoa foi encontrada: nome igual, começo do nome ou escolha na tela. */
  match: "exact" | "prefix" | "manual" | "ignored" | null;
}

export interface ImportProjectDto {
  name: string;
  rows: number;
  seconds: number;
  sampleWorkItemId: number;
  projectId: string | null;
  devopsProjectId: string | null;
  /** existing: já está no TimerSMIT · create: será criado · missing: sem o GUID do Azure DevOps. */
  status: "existing" | "create" | "missing";
}

export interface ImportPreviewDto {
  dryRun: boolean;
  rows: number;
  totalSeconds: number;
  from: string | null;
  to: string | null;
  people: ImportPersonDto[];
  projects: ImportProjectDto[];
  activities: Array<{ name: string; rows: number; status: "existing" | "create" | "none" }>;
  toImport: number;
  toImportSeconds: number;
  skipped: { unknownPerson: number; unknownProject: number; alreadyImported: number; alreadyLogged: number; lockedWeek: number };
  warnings: string[];
  imported: number;
}

export interface ImportRequest {
  /** Planilha .xlsx em base64. */
  file: string;
  dryRun: boolean;
  /** Nome na planilha → id do membro ("none" = deixar de fora; ausente = automático). */
  personMap?: Record<string, string>;
  /** Nome do projeto → GUID no Azure DevOps. */
  projectIds?: Record<string, string>;
}

export function importSevenPace(client: ApiClient, request: ImportRequest): Promise<ImportPreviewDto> {
  return client.request<ImportPreviewDto>("/api/settings/import/7pace", {
    method: "POST",
    body: JSON.stringify(request),
  });
}

/** Conteúdo do arquivo em base64 (sem o prefixo "data:"). */
export async function fileToBase64(file: Blob): Promise<string> {
  const bytes = new Uint8Array(await file.arrayBuffer());
  let binary = "";
  for (let index = 0; index < bytes.length; index += 0x8000) {
    binary += String.fromCharCode(...bytes.subarray(index, index + 0x8000));
  }
  return btoa(binary);
}
