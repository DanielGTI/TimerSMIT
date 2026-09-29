import { useEffect, useState } from "react";
import { createApiClient } from "../../lib/api/client";
import { getApiBaseUrl } from "../../lib/api/config";
import { fetchCurrentSession, type CurrentSession } from "../../lib/api/me";

type Status = { kind: "loading" } | { kind: "error"; message: string } | { kind: "ready"; session: CurrentSession };

/**
 * Guia do work item (US1, tasks.md T019). Nesta fase (T001-T012) o objetivo
 * é apenas provar a cadeia completa: SDK -> token de app -> sessão do
 * backend -> chamada autenticada. Registro de tempo entra em US1.
 */
export function WorkItemGuide(): JSX.Element {
  const [status, setStatus] = useState<Status>({ kind: "loading" });

  useEffect(() => {
    let cancelled = false;

    async function load() {
      try {
        const client = createApiClient({ apiBaseUrl: getApiBaseUrl() });
        const session = await fetchCurrentSession(client);
        if (!cancelled) {
          setStatus({ kind: "ready", session });
        }
      } catch (error) {
        if (!cancelled) {
          setStatus({ kind: "error", message: error instanceof Error ? error.message : String(error) });
        }
      }
    }

    void load();
    return () => {
      cancelled = true;
    };
  }, []);

  if (status.kind === "loading") {
    return <p>Conectando ao backend…</p>;
  }

  if (status.kind === "error") {
    return <p role="alert">Não foi possível estabelecer sessão: {status.message}</p>;
  }

  return (
    <div>
      <p>
        Sessão ativa para <strong>{status.session.displayName}</strong> em{" "}
        <strong>{status.session.organizationName}</strong>.
      </p>
      <p>Registro de tempo neste work item chega em US1 (tasks.md T013-T020).</p>
    </div>
  );
}
