import { EVENT_LABELS, type HourBankEventDto, type HourBankStatementDto } from "../lib/api/hourBank";
import { formatHours } from "../lib/time/format";

const formatDate = (iso: string): string => {
  const [year, month, day] = iso.split("-");
  return `${day}/${month}/${year}`;
};

/** Segundos com sinal: "+06:00", "−04:00", "00:00". */
export function signedHours(seconds: number): string {
  if (seconds === 0) return "00:00";
  return `${seconds > 0 ? "+" : "−"}${formatHours(Math.abs(seconds))}`;
}

function Description({ event }: { event: HourBankEventDto }): JSX.Element {
  if (event.type === "credit") {
    const used = event.remainingSeconds !== null && event.remainingSeconds < event.seconds;
    return (
      <>
        {EVENT_LABELS.credit} · #{event.workItemId} {event.workItemTitle ?? ""}
        <span className="muted block">
          {event.additionalSeconds !== null && `${formatHours(event.additionalSeconds)} trabalhadas · `}
          {event.expiresOn && `vence em ${formatDate(event.expiresOn)}`}
          {used && event.remainingSeconds! > 0 && ` · restam ${formatHours(event.remainingSeconds!)}`}
          {used && event.remainingSeconds === 0 && " · usado"}
        </span>
        {event.note && <span className="muted block">{event.note}</span>}
      </>
    );
  }

  if (event.type === "expiry") {
    return (
      <>
        {EVENT_LABELS.expiry}
        <span className="muted block">Sobra do crédito de {event.creditDate ? formatDate(event.creditDate) : "—"}, não usada no prazo.</span>
      </>
    );
  }

  return (
    <>
      {EVENT_LABELS[event.type]}
      {event.type === "adjustment" && event.seconds > 0 && event.expiresOn && (
        <span className="muted"> · vence em {formatDate(event.expiresOn)}</span>
      )}
      {event.note && <span className="block">{event.note}</span>}
      {event.createdBy && <span className="muted block">Lançado por {event.createdBy}</span>}
    </>
  );
}

interface HourBankStatementProps {
  statement: HourBankStatementDto;
  /** Administrador: permite desfazer folgas, pagamentos e ajustes. */
  onRemove?: (event: HourBankEventDto) => void;
  busy?: boolean;
  /** Falso: só o resumo (o extrato fica para quando a pessoa pedir). */
  showEvents?: boolean;
}

/**
 * Saldo e extrato do banco de horas (mais recente primeiro). Mesma visão
 * para a pessoa (Folha semanal) e para o administrador (Configuração).
 */
export function HourBankStatement({ statement, onRemove, busy = false, showEvents = true }: HourBankStatementProps): JSX.Element {
  const { summary } = statement;
  const events = [...statement.events].reverse();

  return (
    <>
      <dl className="bank-summary">
        <div>
          <dt>Saldo</dt>
          <dd className={summary.balanceSeconds < 0 ? "field__error" : undefined}>
            <strong>{summary.balanceSeconds < 0 ? signedHours(summary.balanceSeconds) : formatHours(summary.balanceSeconds)}</strong>
          </dd>
        </div>
        <div>
          <dt>Vence em até 30 dias</dt>
          <dd>
            {formatHours(summary.expiringSoonSeconds)}
            {summary.nextExpiry && <span className="muted"> (a partir de {formatDate(summary.nextExpiry)})</span>}
          </dd>
        </div>
        <div>
          <dt>Vencido, a pagar como hora extra</dt>
          <dd>{formatHours(summary.expiredSeconds)}</dd>
        </div>
        <div>
          <dt>Prazo para compensar</dt>
          <dd>{statement.validityMonths} meses</dd>
        </div>
      </dl>

      {summary.uncoveredSeconds > 0 && (
        <p className="alert" role="alert">
          {formatHours(summary.uncoveredSeconds)} de folga ou pagamento sem horas no banco para cobrir. Revise os lançamentos.
        </p>
      )}

      {!showEvents ? null : events.length === 0 ? (
        <p className="muted">Nenhum movimento no banco de horas.</p>
      ) : (
        <div className="table-scroll">
          <table className="entry-table">
            <caption className="sr-only">Extrato do banco de horas de {statement.member.name}</caption>
            <thead>
              <tr>
                <th scope="col">Data</th>
                <th scope="col">Lançamento</th>
                <th scope="col">Horas</th>
                <th scope="col">Saldo</th>
                {onRemove && (
                  <th scope="col">
                    <span className="sr-only">Ações</span>
                  </th>
                )}
              </tr>
            </thead>
            <tbody>
              {events.map((event) => (
                <tr key={event.id}>
                  <td>{formatDate(event.date)}</td>
                  <td>
                    <Description event={event} />
                  </td>
                  <td className={event.seconds < 0 ? "bank-out" : "bank-in"}>{signedHours(event.seconds)}</td>
                  <td>{event.balanceSeconds < 0 ? signedHours(event.balanceSeconds) : formatHours(event.balanceSeconds)}</td>
                  {onRemove && (
                    <td>
                      {event.movementId && (
                        <button
                          type="button"
                          className="btn btn--ghost"
                          disabled={busy}
                          aria-label={`Desfazer ${EVENT_LABELS[event.type].toLowerCase()} de ${formatDate(event.date)}`}
                          onClick={() => onRemove(event)}
                        >
                          Desfazer
                        </button>
                      )}
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}
