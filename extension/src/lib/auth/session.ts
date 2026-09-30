import { getAppToken, getHostContext, getUserContext } from "../devops/sdk";

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
 * própria do backend. O backend valida a assinatura do token localmente
 * (ver api/app/Services/DevOpsIdentityVerifier.php); organização e nome de
 * exibição enviados aqui são afirmações do cliente, usadas só para
 * provisionamento/exibição, nunca como prova de identidade.
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
  const [appToken, hostContext, userContext] = await Promise.all([
    getAppToken(),
    getHostContext(),
    getUserContext(),
  ]);

  const response = await fetch(`${apiBaseUrl}/api/auth/session`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      appToken,
      claimedOrganizationId: hostContext.id,
      claimedOrganizationName: hostContext.name,
      displayName: userContext.displayName,
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
