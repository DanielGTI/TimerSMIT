import { useState } from "react";
import type { ApiClient } from "../../lib/api/client";
import {
  decideOvertime,
  DESTINATION_LABELS,
  periodLabel,
  shortTime,
  type OvertimeItemDto,
  type PendingOvertimeDto,
} from "../../lib/api/overtime";
import { formatDuration, formatHours, parseDuration } from "../../lib/time/format";

const errorText = (failure: unknown): string => (failure instanceof Error ? failure.message : String(failure));

interface OvertimeDecisionProps {
  client: ApiClient;
  item: OvertimeItemDto | PendingOvertimeDto;
  /** Depois de decidir: quem mostra recarrega a lista (e a semana). */
  onDecided: (message: string) => void;
}

/**
 * Uma hora extra para o aprovador decidir: a informada pela pessoa (aprovar,
 * podendo liberar menos horas por dia, ou recusar com motivo) ou a hora a
 * confirmar do perfil restrito (confirmar, e ela vira lançamento, ou recusar).
 */
export function OvertimeDecision({ client, item, onDecided }: OvertimeDecisionProps): JSX.Element {
  const [allowed, setAllowed] = useState(formatDuration(Math.round(item.secondsPerDay / 60)));
  const [refusing, setRefusing] = useState(false);
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const person = "person" in item ? item.person.displayName : null;
  const isRequest = item.kind === "request";
  const allowedMinutes = parseDuration(allowed);
  const label = `${isRequest ? "hora extra informada" : "hora extra a confirmar"}${person ? ` de ${person}` : ""} em ${periodLabel(item)}`;

  async function decide(approve: boolean) {
    setBusy(true);
    setError(null);
    try {
      await decideOvertime(client, item.id, {
        approve,
        ...(approve && isRequest && allowedMinutes !== null ? { approvedSecondsPerDay: allowedMinutes * 60 } : {}),
        ...(note.trim() ? { note: note.trim() } : {}),
      });
      onDecided(
        approve
          ? isRequest
            ? `Hora extra aprovada${person ? ` para ${person}` : ""}.`
            : `Hora extra confirmada${person ? ` para ${person}` : ""}: virou lançamento.`
          : `Hora extra recusada${person ? ` para ${person}` : ""}.`,
      );
    } catch (failure) {
      setError(errorText(failure));
      setBusy(false);
    }
  }

  return (
    <li className="overtime-decision" aria-label={label}>
      <div>
        {person && <strong>{person} · </strong>}
        <strong>{isRequest ? "Hora extra informada" : "Hora extra a confirmar"}</strong> · {periodLabel(item)}
        {item.startTime && item.endTime && ` · ${shortTime(item.startTime)}–${shortTime(item.endTime)}`} ·{" "}
        {formatHours(item.secondsPerDay)}
        {isRequest && item.dateFrom !== item.dateTo && " por dia"}
        {isRequest && item.afterTheFact && <span className="badge badge--rejected">informada depois</span>}
      </div>
      <div className="muted">
        Motivo: {item.reason}
        {item.suggestedDestination && ` · Sugestão: ${DESTINATION_LABELS[item.suggestedDestination]}`}
        {!isRequest && item.projectName && ` · ${item.projectName} #${item.workItemId}`}
        {!isRequest && item.note && ` · “${item.note}”`}
      </div>

      {refusing ? (
        <div className="overtime-decision__actions">
          <input
            className="input"
            aria-label={`Motivo da recusa (${label})`}
            placeholder="Motivo da recusa (obrigatório)"
            maxLength={1000}
            value={note}
            disabled={busy}
            onChange={(event) => setNote(event.target.value)}
          />
          <button type="button" className="btn btn--danger btn--small" disabled={busy || note.trim() === ""} onClick={() => void decide(false)}>
            Confirmar recusa
          </button>
          <button type="button" className="btn btn--small" disabled={busy} onClick={() => setRefusing(false)}>
            Voltar
          </button>
        </div>
      ) : (
        <div className="overtime-decision__actions">
          {isRequest && (
            <label className="overtime-decision__allow">
              Liberar por dia
              <input
                className={allowedMinutes === null ? "input input--compact input--invalid" : "input input--compact"}
                aria-label={`Horas liberadas por dia (${label})`}
                value={allowed}
                disabled={busy}
                onChange={(event) => setAllowed(event.target.value)}
              />
            </label>
          )}
          <button
            type="button"
            className="btn btn--primary btn--small"
            disabled={busy || (isRequest && (allowedMinutes === null || allowedMinutes <= 0))}
            onClick={() => void decide(true)}
          >
            {isRequest ? "Aprovar" : "Confirmar"}
          </button>
          <button type="button" className="btn btn--small" disabled={busy} onClick={() => setRefusing(true)}>
            Recusar
          </button>
        </div>
      )}
      {error && (
        <p className="alert" role="alert">
          {error}
        </p>
      )}
    </li>
  );
}
