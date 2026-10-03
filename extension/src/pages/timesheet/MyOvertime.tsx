import { useEffect, useId, useState, type FormEvent } from "react";
import type { ApiClient } from "../../lib/api/client";
import { fetchCurrentSession } from "../../lib/api/me";
import {
  cancelOvertime,
  DESTINATION_LABELS,
  fetchMyOvertime,
  informOvertime,
  OVERTIME_PROFILE_HINTS,
  overtimeControl,
  periodLabel,
  shortTime,
  type MyOvertimeDto,
  type OvertimeItemDto,
} from "../../lib/api/overtime";
import { formatHours, parseDuration, todayLocalIso } from "../../lib/time/format";

const errorText = (failure: unknown): string => (failure instanceof Error ? failure.message : String(failure));

const BADGES: Record<OvertimeItemDto["status"], string> = {
  pending: "badge badge--submitted",
  approved: "badge badge--approved",
  rejected: "badge badge--rejected",
  cancelled: "badge badge--open",
};

/** Situação de uma hora extra informada ou a confirmar, como a pessoa lê. */
export function overtimeStatusText(item: OvertimeItemDto): string {
  const by = item.decidedBy ? ` por ${item.decidedBy}` : "";
  const note = item.decisionNote ? `: “${item.decisionNote}”` : "";

  if (item.kind === "confirmation") {
    if (item.status === "approved") return `Confirmada${by}`;
    if (item.status === "rejected") return `Recusada${by}${note} (não conta)`;
    return "A confirmar";
  }

  switch (item.status) {
    case "approved": {
      const less = item.approvedSecondsPerDay !== null && item.approvedSecondsPerDay < item.secondsPerDay;
      return `Aprovada${less ? ` até ${formatHours(item.approvedSecondsPerDay!)} por dia` : ""}${by}${note}`;
    }
    case "rejected":
      return `Recusada${by}${note}`;
    case "cancelled":
      return "Cancelada";
    default:
      return "Aguardando decisão";
  }
}

/**
 * Hora extra na Folha semanal (Fase 4): "Informar hora extra" (antes de fazer,
 * ou depois, para regularizar) e a situação de cada pedido e de cada hora
 * extra a confirmar. Só aparece para quem é CLT com o controle ligado.
 */
export function MyOvertime({ client, reloadTick = 0, onChanged }: { client: ApiClient; reloadTick?: number; onChanged?: () => void }): JSX.Element | null {
  const [data, setData] = useState<MyOvertimeDto | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [informing, setInforming] = useState(false);
  const [busy, setBusy] = useState(false);
  const [tick, setTick] = useState(0);

  useEffect(() => {
    let cancelled = false;

    fetchCurrentSession(client)
      .then(async (session) => {
        if (!overtimeControl(session)) return;
        const mine = await fetchMyOvertime(client);
        if (!cancelled && mine.applies) setData(mine);
      })
      .catch((failure: unknown) => !cancelled && setError(errorText(failure)));

    return () => {
      cancelled = true;
    };
  }, [client, reloadTick, tick]);

  if (!data && !error) return null;

  async function cancel(item: OvertimeItemDto) {
    setBusy(true);
    setError(null);
    try {
      await cancelOvertime(client, item.id);
      setNotice("Hora extra informada cancelada.");
      setTick((value) => value + 1);
    } catch (failure) {
      setError(errorText(failure));
    } finally {
      setBusy(false);
    }
  }

  const items = data?.items ?? [];

  return (
    <section className="card" aria-label="Horas extras informadas">
      <div className="toolbar">
        <h2>Horas extras</h2>
        {data && (
          <button type="button" className="btn" onClick={() => setInforming(true)}>
            Informar hora extra
          </button>
        )}
      </div>
      {data && <p className="muted">{OVERTIME_PROFILE_HINTS[data.profile]} Combine antes com o seu gestor.</p>}

      {notice && (
        <p className="notice" role="status">
          {notice}
        </p>
      )}
      {error && (
        <p className="alert" role="alert">
          {error}
        </p>
      )}

      {items.length === 0 ? (
        data && <p className="muted">Nenhuma hora extra informada nos últimos 90 dias.</p>
      ) : (
        <ul className="overtime-list" aria-label="Minhas horas extras">
          {items.map((item) => (
            <li key={item.id}>
              <div>
                <strong>{item.kind === "request" ? "Hora extra informada" : "Hora extra a confirmar"}</strong> ·{" "}
                {periodLabel(item)}
                {item.startTime && item.endTime && ` · ${shortTime(item.startTime)}–${shortTime(item.endTime)}`} ·{" "}
                {formatHours(item.secondsPerDay)}
                {item.kind === "request" && item.dateFrom !== item.dateTo && " por dia"}
                {item.afterTheFact && item.kind === "request" && <span className="muted"> · informada depois</span>}
              </div>
              <div className="muted">Motivo: {item.reason}</div>
              <div className="overtime-list__status">
                <span className={BADGES[item.status]}>{overtimeStatusText(item)}</span>
                {item.kind === "request" && item.status === "pending" && (
                  <button type="button" className="btn btn--small" disabled={busy} onClick={() => void cancel(item)}>
                    Cancelar
                  </button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}

      {informing && (
        <InformOvertimePanel
          client={client}
          onClose={() => setInforming(false)}
          onSaved={(saved) => {
            setInforming(false);
            setNotice(
              saved.afterTheFact
                ? "Hora extra informada (depois do fato). O aprovador vai decidir."
                : "Hora extra informada. O aprovador vai decidir; acompanhe aqui.",
            );
            setTick((value) => value + 1);
            onChanged?.();
          }}
        />
      )}
    </section>
  );
}

function InformOvertimePanel({
  client,
  onClose,
  onSaved,
}: {
  client: ApiClient;
  onClose: () => void;
  onSaved: (saved: OvertimeItemDto) => void;
}): JSX.Element {
  const ids = useId();
  const today = todayLocalIso();
  const [dateFrom, setDateFrom] = useState(today);
  const [dateTo, setDateTo] = useState(today);
  const [hoursText, setHoursText] = useState("02:00");
  const [startTime, setStartTime] = useState("");
  const [endTime, setEndTime] = useState("");
  const [destination, setDestination] = useState<"" | "overtime" | "bank">("");
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    function closeOnEscape(event: KeyboardEvent) {
      if (event.key === "Escape") onClose();
    }
    document.addEventListener("keydown", closeOnEscape);
    return () => document.removeEventListener("keydown", closeOnEscape);
  }, [onClose]);

  const minutes = parseDuration(hoursText);
  const timeIncomplete = (startTime === "") !== (endTime === "");
  const canSave =
    !busy && minutes !== null && minutes > 0 && dateFrom !== "" && dateTo >= dateFrom && reason.trim() !== "" && !timeIncomplete;

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (!canSave || minutes === null) return;
    setBusy(true);
    setError(null);
    try {
      onSaved(
        await informOvertime(client, {
          dateFrom,
          ...(dateTo !== dateFrom ? { dateTo } : {}),
          secondsPerDay: minutes * 60,
          ...(startTime && endTime ? { startTime, endTime } : {}),
          reason: reason.trim(),
          ...(destination ? { suggestedDestination: destination } : {}),
        }),
      );
    } catch (failure) {
      setError(errorText(failure));
      setBusy(false);
    }
  }

  return (
    <div
      className="drawer-backdrop"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose();
      }}
    >
      <aside className="drawer" role="dialog" aria-modal="true" aria-label="Informar hora extra">
        <form className="add-time" onSubmit={(event) => void submit(event)}>
          <div className="add-time__header">
            <h2>Informar hora extra</h2>
            <button type="button" className="btn btn--icon btn--ghost" aria-label="Fechar" onClick={onClose}>
              ×
            </button>
          </div>
          <p className="muted">
            Avise antes de fazer (ou depois, para regularizar). O aprovador decide e pode liberar menos horas do que o
            pedido. A hora extra que você lançar no dia é comparada com o que foi aprovado.
          </p>

          <div className="field-row">
            <div className="field">
              <label htmlFor={`${ids}-from`}>De (data)</label>
              <input
                id={`${ids}-from`}
                className="input input--short"
                type="date"
                value={dateFrom}
                onChange={(event) => {
                  setDateFrom(event.target.value);
                  if (dateTo < event.target.value) setDateTo(event.target.value);
                }}
                required
              />
            </div>
            <div className="field">
              <label htmlFor={`${ids}-to`}>Até (data)</label>
              <input id={`${ids}-to`} className="input input--short" type="date" value={dateTo} min={dateFrom} onChange={(event) => setDateTo(event.target.value)} />
            </div>
          </div>

          <div className="field">
            <label htmlFor={`${ids}-hours`}>Horas por dia</label>
            <input
              id={`${ids}-hours`}
              className={minutes === null ? "input input--short input--invalid" : "input input--short"}
              inputMode="numeric"
              placeholder="02:00"
              value={hoursText}
              onChange={(event) => setHoursText(event.target.value)}
            />
          </div>

          <div className="field-row">
            <div className="field">
              <label htmlFor={`${ids}-start`}>Horário previsto: início</label>
              <input id={`${ids}-start`} className="input input--short" type="time" value={startTime} onChange={(event) => setStartTime(event.target.value)} />
            </div>
            <div className="field">
              <label htmlFor={`${ids}-end`}>Fim</label>
              <input id={`${ids}-end`} className="input input--short" type="time" value={endTime} onChange={(event) => setEndTime(event.target.value)} />
            </div>
          </div>
          <p className="muted">Opcional. Se informar, preencha os dois.</p>

          <div className="field">
            <label htmlFor={`${ids}-destination`}>Sugestão de destino</label>
            <select
              id={`${ids}-destination`}
              className="input"
              value={destination}
              onChange={(event) => setDestination(event.target.value as "" | "overtime" | "bank")}
            >
              <option value="">Sem preferência</option>
              <option value="overtime">{DESTINATION_LABELS.overtime}</option>
              <option value="bank">{DESTINATION_LABELS.bank}</option>
            </select>
          </div>

          <div className="field">
            <label htmlFor={`${ids}-reason`}>Motivo</label>
            <textarea id={`${ids}-reason`} className="input" maxLength={1000} value={reason} onChange={(event) => setReason(event.target.value)} required />
          </div>

          {error && (
            <p className="alert" role="alert">
              {error}
            </p>
          )}

          <div className="actions">
            <button type="button" className="btn" onClick={onClose}>
              Cancelar
            </button>
            <button type="submit" className="btn btn--primary" disabled={!canSave}>
              Informar
            </button>
          </div>
        </form>
      </aside>
    </div>
  );
}
