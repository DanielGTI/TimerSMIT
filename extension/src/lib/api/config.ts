/**
 * URL do backend próprio, definida em tempo de build por ambiente
 * (dev/piloto/produção). Nunca hardcode aqui — configurar via `.env.local`
 * (não versionado) usando `VITE_API_BASE_URL`.
 */
export function getApiBaseUrl(): string {
  const url = import.meta.env.VITE_API_BASE_URL as string | undefined;

  if (!url) {
    throw new Error("VITE_API_BASE_URL não configurado (ver extension/.env.example).");
  }

  return url.replace(/\/$/, "");
}
