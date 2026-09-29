import { clearBackendSession, getBackendSession } from "../auth/session";

export interface ApiClientOptions {
  apiBaseUrl: string;
}

/**
 * Fetch autenticado com a sessão do backend (não o token do Azure DevOps).
 * Em um 401 tenta renovar a sessão uma única vez antes de desistir, já que
 * o token de app pode ter expirado entre a abertura da página e a chamada.
 */
export function createApiClient({ apiBaseUrl }: ApiClientOptions) {
  async function request<T>(path: string, init: RequestInit = {}, retry = true): Promise<T> {
    const session = await getBackendSession(apiBaseUrl);

    const response = await fetch(`${apiBaseUrl}${path}`, {
      ...init,
      headers: {
        ...init.headers,
        Authorization: `Bearer ${session.sessionToken}`,
        "Content-Type": "application/json",
      },
    });

    if (response.status === 401 && retry) {
      clearBackendSession();
      return request<T>(path, init, false);
    }

    if (!response.ok) {
      throw new Error(`Falha na chamada à API (${path}): HTTP ${response.status}`);
    }

    return (await response.json()) as T;
  }

  return { request };
}

export type ApiClient = ReturnType<typeof createApiClient>;
