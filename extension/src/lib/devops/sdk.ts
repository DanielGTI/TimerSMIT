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
 * Organização (host) atual — `id`/`name` são afirmações do cliente sobre
 * qual organização é esta; o backend não verifica isso criptograficamente
 * (o token da extensão não carrega o ID da organização, só o tenant do
 * Entra — ver DevOpsIdentityVerifier no backend). Servem para rotear e
 * exibir, não como prova de identidade.
 */
export async function getHostContext() {
  await ensureSdkReady();
  return SDK.getHost();
}

/**
 * Nome de exibição do usuário — puramente cosmético (aparece em telas e
 * auditoria legível). Nunca usado para autorização; quem decide acesso é o
 * `nameid` verificado dentro do token da extensão.
 */
export async function getUserContext() {
  await ensureSdkReady();
  return SDK.getUser();
}

export async function getExtensionContext() {
  await ensureSdkReady();
  return SDK.getExtensionContext();
}

/**
 * JWT assinado pelo Azure DevOps com o segredo da própria extensão
 * (não confundir com token de acesso à API do Azure DevOps — para isso
 * seria `getAccessToken()`, que não usamos). É a única prova
 * criptográfica de identidade repassada ao backend; organização e nome de
 * exibição enviados à parte são afirmações do cliente, não prova.
 */
export async function getAppToken(): Promise<string> {
  await ensureSdkReady();
  return SDK.getAppToken();
}

export function notifyLoadSucceeded(): void {
  SDK.notifyLoadSucceeded();
}

export function notifyLoadFailed(error: unknown): void {
  SDK.notifyLoadFailed(error instanceof Error ? error : String(error));
}
