import * as SDK from "azure-devops-extension-sdk";
import type { IWorkItemFormService } from "azure-devops-extension-api/WorkItemTracking";
import { ensureSdkReady } from "./sdk";

export interface CurrentWorkItem {
  id: number;
  title: string;
  workItemType: string;
  /** System.IterationPath; vazio se o formulário não informar. */
  iterationPath: string | null;
}

// Valor literal (igual a WorkItemTrackingServiceIds.WorkItemFormService) em
// vez de importar o enum do pacote: seu módulo é um UMD/AMD que o Vite não
// consegue empacotar como ESM puro (referência a `define` vira `(void 0)` no
// bundle final, quebrando a extensão inteira ao carregar). Só o tipo
// (`import type`, sempre apagado na compilação) é seguro de importar dali.
const WORK_ITEM_FORM_SERVICE_ID = "ms.vss-work-web.work-item-form";

let formServicePromise: Promise<IWorkItemFormService> | null = null;

function getFormService(): Promise<IWorkItemFormService> {
  if (!formServicePromise) {
    formServicePromise = SDK.getService<IWorkItemFormService>(WORK_ITEM_FORM_SERVICE_ID);
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

  const [id, title, workItemType, iterationPath] = await Promise.all([
    service.getId(),
    service.getFieldValue("System.Title") as Promise<string>,
    service.getFieldValue("System.WorkItemType") as Promise<string>,
    service.getFieldValue("System.IterationPath") as Promise<string | undefined>,
  ]);

  return { id, title, workItemType, iterationPath: iterationPath ? String(iterationPath) : null };
}
