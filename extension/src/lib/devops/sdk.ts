import * as SDK from "azure-devops-extension-sdk";

let readyPromise: Promise<void> | null = null;

/**
 * Inicializa o SDK da extensão uma única vez por página e aguarda o handshake
 * com o host do Azure DevOps antes de liberar chamadas de contexto/token.
 */
export function ensureSdkReady(): Promise<void> {
  if (!readyPromise) {
    SDK.init({ loaded: false });
    readyPromise = SDK.ready();
  }
  return readyPromise;
}

export async function getWebContext() {
  await ensureSdkReady();
  return SDK.getWebContext();
}

/**
 * Organização (host) atual — `IHostContext.name` é o nome da organização do
 * Azure DevOps, usado apenas para rotear a verificação no backend.
 */
export async function getHostContext() {
  await ensureSdkReady();
  return SDK.getHost();
}

export async function getExtensionContext() {
  await ensureSdkReady();
  return SDK.getExtensionContext();
}

/**
 * Token de acesso emitido pelo Azure DevOps para o usuário/organização atuais.
 * É a única prova de identidade repassada ao backend — nunca enviar
 * organização/usuário como campos soltos vindos do contexto do cliente.
 */
export async function getAppToken(): Promise<string> {
  await ensureSdkReady();
  return SDK.getAppToken();
}

export function notifyLoadSucceeded(): void {
  SDK.notifyLoadSucceeded();
}
