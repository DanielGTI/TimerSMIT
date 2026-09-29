import { getAppToken, getHostContext } from "../devops/sdk";

export interface BackendSession {
  sessionToken: string;
  expiresAt: string;
  tenantId: string;
}

interface SessionExchangeResponse {
  sessionToken: string;
  expiresAt: string;
  tenantId: string;
}

let cachedSession: BackendSession | null = null;
let inFlight: Promise<BackendSession> | null = null;

/**
 * Troca o token de app do Azure DevOps (prova de identidade) por uma sessão
 * própria do backend. O backend é quem valida o token junto ao Azure DevOps
 * (ver api/app/Services/DevOpsIdentityVerifier.php) — o cliente nunca afirma
 * quem é; ele apenas repassa o token que o host emitiu.
 */
export async function getBackendSession(apiBaseUrl: string): Promise<BackendSession> {
  if (cachedSession && new Date(cachedSession.expiresAt).getTime() > Date.now() + 5_000) {
    return cachedSession;
  }
  if (inFlight) {
    return inFlight;
  }

  inFlight = exchangeSession(apiBaseUrl).finally(() => {
    inFlight = null;
  });
  return inFlight;
}

export function clearBackendSession(): void {
  cachedSession = null;
}

async function exchangeSession(apiBaseUrl: string): Promise<BackendSession> {
  const [appToken, hostContext] = await Promise.all([getAppToken(), getHostContext()]);

  const response = await fetch(`${apiBaseUrl}/api/auth/session`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      appToken,
      // Usado só para montar a URL de verificação no backend; o backend
      // resolve o tenant real a partir da resposta do Azure DevOps, não
      // confia neste campo (ver DevOpsIdentityVerifier).
      claimedOrganization: hostContext.name,
    }),
  });

  if (!response.ok) {
    throw new Error(`Falha ao estabelecer sessão com o backend (HTTP ${response.status})`);
  }

  const body = (await response.json()) as SessionExchangeResponse;
  cachedSession = {
    sessionToken: body.sessionToken,
    expiresAt: body.expiresAt,
    tenantId: body.tenantId,
  };
  return cachedSession;
}
