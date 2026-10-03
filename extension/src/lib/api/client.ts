import { clearBackendSession, getBackendSession } from "../auth/session";

export interface ApiClientOptions {
  apiBaseUrl: string;
}

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    /** Campos com erro de validação (422), para a tela reagir a um campo específico. */
    readonly fields: string[] = [],
  ) {
    super(message);
    this.name = "ApiError";
  }
}

/**
 * Erros 4xx trazem a mensagem do backend (regra violada, conflito, acesso
 * negado) — é o que a pessoa precisa ler. 5xx fica genérico: o detalhe é do
 * servidor, não da tela.
 */
async function toApiError(path: string, response: Response): Promise<ApiError> {
  const generic = `Falha na chamada à API (${path}): HTTP ${response.status}`;

  if (response.status >= 500) {
    return new ApiError(generic, response.status);
  }

  try {
    const body = (await response.json()) as { message?: string; errors?: Record<string, string[]> };
    const firstFieldError = Object.values(body.errors ?? {}).flat()[0];
    return new ApiError(firstFieldError ?? body.message ?? generic, response.status, Object.keys(body.errors ?? {}));
  } catch {
    return new ApiError(generic, response.status);
  }
}

/**
 * Fetch autenticado com a sessão do backend (não o token do Azure DevOps).
 * Em um 401 tenta renovar a sessão uma única vez antes de desistir, já que
 * o token de app pode ter expirado entre a abertura da página e a chamada.
 */
export function createApiClient({ apiBaseUrl }: ApiClientOptions) {
  async function send(path: string, init: RequestInit, retry = true): Promise<Response> {
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
      return send(path, init, false);
    }

    if (!response.ok) {
      throw await toApiError(path, response);
    }

    return response;
  }

  async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
    const response = await send(path, init);

    if (response.status === 204) {
      return undefined as T;
    }

    return (await response.json()) as T;
  }

  /** Arquivo (ex.: CSV) com a mesma autenticação e tratamento de erro do `request`. */
  async function download(path: string): Promise<Blob> {
    return (await send(path, {})).blob();
  }

  return { request, download };
}

export type ApiClient = ReturnType<typeof createApiClient>;
