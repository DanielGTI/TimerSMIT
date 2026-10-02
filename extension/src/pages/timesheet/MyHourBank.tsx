import { useEffect, useState } from "react";
import { HourBankStatement } from "../../components/HourBankStatement";
import type { ApiClient } from "../../lib/api/client";
import { fetchMyHourBank, type HourBankStatementDto } from "../../lib/api/hourBank";
import { fetchCurrentSession } from "../../lib/api/me";

const errorText = (failure: unknown): string => (failure instanceof Error ? failure.message : String(failure));

/**
 * Saldo do banco de horas na Folha semanal. Só aparece para quem é CLT com o
 * controle de horas adicionais ligado (ou para quem ainda tem movimento no banco).
 */
export function MyHourBank({ client }: { client: ApiClient }): JSX.Element | null {
  const [statement, setStatement] = useState<HourBankStatementDto | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [open, setOpen] = useState(false);

  useEffect(() => {
    let cancelled = false;

    fetchCurrentSession(client)
      .then(async (session) => {
        if (!session.overtime?.enabled || (session.hoursRegime ?? "clt") === "none") return;
        const data = await fetchMyHourBank(client);
        const shown = (session.hoursRegime ?? "clt") === "clt" || data.events.length > 0;
        if (!cancelled && shown) setStatement(data);
      })
      .catch((failure: unknown) => !cancelled && setError(errorText(failure)));

    return () => {
      cancelled = true;
    };
  }, [client]);

  if (!statement && !error) return null;

  return (
    <section className="card" aria-label="Meu banco de horas">
      <div className="toolbar">
        <h2>Banco de horas</h2>
        {statement && statement.events.length > 0 && (
          <button type="button" className="btn btn--chip" aria-expanded={open} onClick={() => setOpen((value) => !value)}>
            {open ? "Ocultar extrato" : "Ver extrato"}
          </button>
        )}
      </div>
      {error ? (
        <p className="alert" role="alert">
          {error}
        </p>
      ) : (
        statement && (
          <>
            <p className="muted">
              Entram aqui as horas adicionais que o administrador manda para o banco. Elas são usadas em folgas dentro do
              prazo; o que vencer sem uso é pago como hora extra.
            </p>
            <HourBankStatement statement={statement} showEvents={open} />
          </>
        )
      )}
    </section>
  );
}
