import * as SDK from "azure-devops-extension-sdk";
import { IWorkItemFormService, WorkItemTrackingServiceIds } from "azure-devops-extension-api/WorkItemTracking";
import { ensureSdkReady } from "./sdk";

export interface CurrentWorkItem {
  id: number;
  title: string;
  workItemType: string;
}

let formServicePromise: Promise<IWorkItemFormService> | null = null;

function getFormService(): Promise<IWorkItemFormService> {
  if (!formServicePromise) {
    formServicePromise = SDK.getService<IWorkItemFormService>(WorkItemTrackingServiceIds.WorkItemFormService);
  }
  return formServicePromise;
}

/**
 * Lê o work item já aberto no formulário (contribution
 * ms.vss-work-web.work-item-form-page) — sem chamada de API própria, o host
 * do Azure DevOps já tem os dados carregados. Título/tipo são cosméticos
 * (ver WorkItemAccessService no backend); a autorização real depende só do
 * projeto + papel do membro, nunca destes campos.
 */
export async function getCurrentWorkItem(): Promise<CurrentWorkItem> {
  await ensureSdkReady();
  const service = await getFormService();

  const [id, title, workItemType] = await Promise.all([
    service.getId(),
    service.getFieldValue("System.Title") as Promise<string>,
    service.getFieldValue("System.WorkItemType") as Promise<string>,
  ]);

  return { id, title, workItemType };
}
