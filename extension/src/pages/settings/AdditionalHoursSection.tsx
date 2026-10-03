import { useCallback, useEffect, useId, useState } from "react";
import { coverageLabel } from "../../components/AdditionalTag";
import {
  CLASSIFICATION_LABELS,
  DAY_TYPE_LABELS,
  classifyAdditionalHours,
  fetchAdditionalHours,
  type AdditionalHoursFilters,
  type AdditionalHoursItemDto,
  type Classification,
} from "../../lib/api/additionalHours";
import { DESTINATION_LABELS } from "../../lib/api/overtime";
import { formatHours, todayLocalIso } from "../../lib/time/format";
import { addDays } from "../../lib/time/weeks";
import { REGIME_LABELS } from "./OvertimeSection";
import type { SectionProps } from "./sections";

const errorText = (failure: unknown): string => (failure instanceof Error ? failure.message : String(failure));

const formatDate = (iso: string): string => {
  const [year, month, day] = iso.split("-");
  return `${day}/${month}/${year}`;
};

const ACTIONS: Array<{ value: Classification | "pending"; label: string }> = [
  { value: "overtime", label: "Hora extra" },
  { value: "bank", label: "Banco de horas" },
  { value: "payable", label: "A pagar" },
  { value: "pending", label: "Voltar para a validar" },
];

/**
 * Fila do administrador: horas adicionais de semanas já aprovadas. Ele
 * escolhe o destino de cada uma (ou de várias de uma vez). Até isso, para
 * quem lançou, elas aparecem como "horas adicionais a validar".
 */
export function AdditionalHoursSection({ settings, client }: SectionProps): JSX.Element {
  const ids = useId();
  const today = todayLocalIso();

  const [filters, setFilters] = useState<AdditionalHoursFilters>({ from: addDays(today, -90), to: today, status: "pending" });
  const [items, setItems] = useState<AdditionalHoursItemDto[] | null>(null);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    setError(null);
    try {
      const data = await fetchAdditionalHours(client, filters);
      setItems(data.items);
      setSelected(new Set());
    } catch (failure) {
      setError(errorText(failure));
    }
  }, [client, filters]);

  useEffect(() => {
    void load();
  }, [load]);

  const chosen = (items ?? []).filter((item) => selected.has(item.entryId));
  const hasNonClt = chosen.some((item) => item.regime !== "clt");
  const allSelected = items !== null && items.length > 0 && chosen.length === items.length;

  function toggle(entryId: string) {
    setSelected((previous) => {
      const next = new Set(previous);
      if (next.has(entryId)) next.delete(entryId);
      else next.add(entryId);
      return next;
    });
  }

  async function classify(classification: Classification | "pending") {
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const { updated } = await classifyAdditionalHours(client, [...selected], classification, note.trim() || undefined);
      setNotice(
        classification === "pending"
          ? `${updated} lançamento(s) voltaram para "a validar".`
          : `${updated} lançamento(s) classificados como ${CLASSIFICATION_LABELS[classification]}.`,
      );
      setNote("");
      await load();
    } catch (failure) {
      setError(errorText(failure));
    } finally {
      setBusy(false);
    }
  }

  const totals = chosen.reduce(
    (sum, item) => ({ seconds: sum.seconds + item.additional.seconds, weighted: sum.weighted + item.additional.weightedSeconds }),
    { seconds: 0, weighted: 0 },
  );

  return (
    <section className="card" aria-label="Classificar horas adicionais">
      <h2>Horas adicionais a classificar</h2>
      <p className="muted">
        Só aparecem horas de semanas <strong>aprovadas</strong>. Escolha o destino: hora extra (paga), banco de horas
        (folga depois; só CLT) ou a pagar (PJ). As horas ponderadas já trazem o fator do dia e o adicional noturno.
      </p>

      <div className="filters__grid">
        <div className="field">
          <label htmlFor={`${ids}-from`}>De</label>
          <input id={`${ids}-from`} className="input" type="date" value={filters.from} onChange={(e) => setFilters({ ...filters, from: e.target.value })} />
        </div>
        <div className="field">
          <label htmlFor={`${ids}-to`}>Até</label>
          <input id={`${ids}-to`} className="input" type="date" value={filters.to} onChange={(e) => setFilters({ ...filters, to: e.target.value })} />
        </div>
        <div className="field">
          <label htmlFor={`${ids}-status`}>Situação</label>
          <select
            id={`${ids}-status`}
            className="input"
            value={filters.status}
            onChange={(e) => setFilters({ ...filters, status: e.target.value as AdditionalHoursFilters["status"] })}
          >
            <option value="pending">A classificar</option>
            <option value="classified">Já classificadas</option>
            <option value="all">Todas</option>
          </select>
        </div>
        <div className="field">
          <label htmlFor={`${ids}-member`}>Pessoa</label>
          <select
            id={`${ids}-member`}
            className="input"
            value={filters.memberId ?? ""}
            onChange={(e) => setFilters({ ...filters, memberId: e.target.value || undefined })}
          >
            <option value="">Todas</option>
            {settings.members.map((member) => (
              <option key={member.id} value={member.id}>
                {member.name}
              </option>
            ))}
          </select>
        </div>
      </div>

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

      {items === null ? (
        !error && <p className="muted">Carregando…</p>
      ) : items.length === 0 ? (
        <p className="muted">Nenhuma hora adicional neste filtro.</p>
      ) : (
        <>
          <div className="table-scroll">
            <table className="entry-table">
              <caption className="sr-only">Horas adicionais de semanas aprovadas</caption>
              <thead>
                <tr>
                  <th scope="col">
                    <input
                      type="checkbox"
                      aria-label="Selecionar todas"
                      checked={allSelected}
                      onChange={() => setSelected(allSelected ? new Set() : new Set(items.map((item) => item.entryId)))}
                    />
                  </th>
                  <th scope="col">Pessoa</th>
                  <th scope="col">Data</th>
                  <th scope="col">Tipo</th>
                  <th scope="col">Work item</th>
                  <th scope="col">Horas</th>
                  <th scope="col">Ponderadas</th>
                  <th scope="col">Situação</th>
                </tr>
              </thead>
              <tbody>
                {items.map((item) => (
                  <tr key={item.entryId}>
                    <td>
                      <input
                        type="checkbox"
                        aria-label={`Selecionar ${item.memberName} em ${formatDate(item.localDate)}`}
                        checked={selected.has(item.entryId)}
                        onChange={() => toggle(item.entryId)}
                      />
                    </td>
                    <td>
                      {item.memberName}
                      {item.regime !== "clt" && <span className="muted block">{REGIME_LABELS[item.regime]}</span>}
                    </td>
                    <td>
                      {formatDate(item.localDate)}
                      {item.startTime && item.endTime && (
                        <span className="muted block">
                          {item.startTime}–{item.endTime}
                        </span>
                      )}
                    </td>
                    <td>
                      {DAY_TYPE_LABELS[item.additional.dayType]}
                      {item.additional.nightSeconds > 0 && (
                        <span className="muted block">{formatHours(item.additional.nightSeconds)} noturnas</span>
                      )}
                    </td>
                    <td>
                      #{item.workItemId} {item.workItemTitle ?? ""}
                      <span className="muted block">{item.projectName}</span>
                    </td>
                    <td>{formatHours(item.additional.seconds)}</td>
                    <td>
                      <strong>{formatHours(item.additional.weightedSeconds)}</strong>
                    </td>
                    <td>
                      {CLASSIFICATION_LABELS[item.additional.status]}
                      {item.additional.coverage && <span className="muted block">{coverageLabel(item.additional.coverage)}</span>}
                      {item.additional.coverage?.suggestedDestination && (
                        <span className="muted block">Sugestão do pedido: {DESTINATION_LABELS[item.additional.coverage.suggestedDestination]}</span>
                      )}
                      {item.additional.denied && (
                        <span className="field__error block">Não autorizada pelo aprovador: {item.additional.denied.reason}</span>
                      )}
                      {item.classificationNote && <span className="muted block">{item.classificationNote}</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="classify-bar" role="group" aria-label="Classificar selecionadas">
            <span>
              {chosen.length === 0
                ? "Selecione as horas para classificar."
                : `${chosen.length} selecionada(s): ${formatHours(totals.seconds)} (${formatHours(totals.weighted)} ponderadas).`}
            </span>
            <label htmlFor={`${ids}-note`} className="sr-only">
              Observação
            </label>
            <input
              id={`${ids}-note`}
              className="input input--medium"
              placeholder="Observação (opcional)"
              maxLength={500}
              value={note}
              onChange={(e) => setNote(e.target.value)}
            />
            {ACTIONS.map((action) => (
              <button
                key={action.value}
                type="button"
                className={action.value === "pending" ? "btn" : "btn btn--primary"}
                disabled={busy || chosen.length === 0 || (action.value === "bank" && hasNonClt)}
                title={action.value === "bank" && hasNonClt ? "Banco de horas só vale para CLT" : undefined}
                onClick={() => void classify(action.value)}
              >
                {action.label}
              </button>
            ))}
          </div>
        </>
      )}
    </section>
  );
}
