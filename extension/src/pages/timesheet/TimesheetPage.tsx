import { useCallback, useEffect, useMemo, useState } from "react";
import { DecisionHistory } from "../../components/DecisionHistory";
import { StatusBadge } from "../../components/StatusBadge";
import { fetchActivityTypes } from "../../lib/api/activityTypes";
import { createApiClient } from "../../lib/api/client";
import { getApiBaseUrl } from "../../lib/api/config";
import { fetchCurrentSession } from "../../lib/api/me";
import { fetchMonth, fetchWeek, submitWeek, type MonthDto, type WeekDto } from "../../lib/api/timesheet";
import { formatDuration, formatHours, todayLocalIso } from "../../lib/time/format";
import { addDays, addMonths, dayMonth, formatWeekRange, mondayOf, monthOf } from "../../lib/time/weeks";
import { AddTimePanel, type AddTimeResources } from "./AddTimePanel";
import { EntryList } from "./EntryList";
import { MonthCalendar } from "./MonthCalendar";
import { WeekGrid } from "./WeekGrid";

const errorText = (failure: unknown): string => (failure instanceof Error ? failure.message : String(failure));

/** O que a pessoa precisa saber sobre a última decisão, conforme o estado atual da semana. */
function decisionBanner(week: WeekDto): { className: string; text: string } | null {
  const last = week.decisions[week.decisions.length - 1];
  if (!last) return null;

  const who = `${last.approverName ?? "—"} em ${new Date(last.decidedAt).toLocaleString("pt-BR")}`;

  if (week.status === "rejected" && last.decision === "rejected") {
    return {
      className: "banner banner--rejected",
      text: `Semana rejeitada por ${who}: “${last.reason ?? ""}”. Corrija os lançamentos e envie novamente.`,
    };
  }
  if (week.status === "approved" && last.decision === "approved") {
    return { className: "banner banner--approved", text: `Semana aprovada por ${who}.` };
  }
  if (week.status === "open" && last.decision === "reopened") {
    return {
      className: "banner",
      text: `Semana reaberta por ${who}: “${last.reason ?? ""}”. Você pode editar e enviar de novo.`,
    };
  }
  return null;
}

/**
 * Folha semanal (US2, T024): grade por work item e dia, lançamentos com
 * editar/excluir, envio da semana e resumo mensal. Os totais vêm prontos do
 * servidor — a tela só formata. O "+" de cada dia do calendário (e o botão
 * "Adicionar tempo") lança horas escolhendo o work item pela busca.
 */
export function TimesheetPage(): JSX.Element {
  const client = useMemo(() => createApiClient({ apiBaseUrl: getApiBaseUrl() }), []);
  const today = todayLocalIso();

  const [weekStart, setWeekStart] = useState(() => mondayOf(today));
  const [month, setMonth] = useState(() => monthOf(mondayOf(today)));
  const [week, setWeek] = useState<WeekDto | null>(null);
  const [monthData, setMonthData] = useState<MonthDto | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [confirming, setConfirming] = useState(false);
  const [busy, setBusy] = useState(false);
  const [reloadTick, setReloadTick] = useState(0);
  const [addingOn, setAddingOn] = useState<string | null>(null);
  const [addTimeResources, setAddTimeResources] = useState<AddTimeResources | null>(null);
  const [addTimeError, setAddTimeError] = useState<string | null>(null);

  const reload = () => setReloadTick((tick) => tick + 1);

  useEffect(() => {
    let cancelled = false;
    setError(null);

    fetchWeek(client, weekStart)
      .then((data) => !cancelled && setWeek(data))
      .catch((failure: unknown) => !cancelled && setError(errorText(failure)));

    return () => {
      cancelled = true;
    };
  }, [client, weekStart, reloadTick]);

  useEffect(() => {
    let cancelled = false;

    fetchMonth(client, month)
      .then((data) => !cancelled && setMonthData(data))
      .catch((failure: unknown) => !cancelled && setError(errorText(failure)));

    return () => {
      cancelled = true;
    };
  }, [client, month, reloadTick]);

  // Nome e atividades só são lidos na primeira vez que o painel abre.
  useEffect(() => {
    if (addingOn === null || addTimeResources) return;
    let cancelled = false;
    setAddTimeError(null);

    Promise.all([fetchCurrentSession(client), fetchActivityTypes(client)])
      .then(([session, activityTypes]) => !cancelled && setAddTimeResources({ displayName: session.displayName, activityTypes }))
      .catch((failure: unknown) => !cancelled && setAddTimeError(errorText(failure)));

    return () => {
      cancelled = true;
    };
  }, [client, addingOn, addTimeResources]);

  const closeAddTime = useCallback(() => setAddingOn(null), []);

  function goToWeek(start: string) {
    setWeekStart(start);
    setConfirming(false);
    setNotice(null);
    // O calendário acompanha a semana quando ela sai do mês exibido.
    if (monthOf(start) !== month && monthOf(addDays(start, 6)) !== month) {
      setMonth(monthOf(start));
    }
  }

  async function handleSubmit() {
    setBusy(true);
    setError(null);
    try {
      setWeek(await submitWeek(client, weekStart));
      setNotice("Semana enviada para aprovação.");
      setConfirming(false);
      reload();
    } catch (failure) {
      setError(errorText(failure));
      setConfirming(false);
    } finally {
      setBusy(false);
    }
  }

  function handleAddTimeSaved({ localDate, minutes }: { localDate: string; minutes: number }) {
    setAddingOn(null);
    goToWeek(mondayOf(localDate));
    setNotice(`Lançamento de ${formatDuration(minutes)} registrado em ${dayMonth(localDate)}.`);
    reload();
  }

  const editable = week !== null && (week.status === "open" || week.status === "rejected");
  const canSubmit = editable && week.entries.length > 0 && !busy;
  // Sem data escolhida no calendário, vale hoje se estiver nesta semana.
  const defaultAddDate = mondayOf(today) === weekStart ? today : weekStart;

  return (
    <div className="page page--wide">
      <section className="card">
        <div className="toolbar">
          <h2>Folha semanal</h2>
          <div className="toolbar__group">
            <button type="button" className="btn btn--chip btn--icon" aria-label="Semana anterior" onClick={() => goToWeek(addDays(weekStart, -7))}>
              ‹
            </button>
            <strong className="toolbar__label">{formatWeekRange(weekStart)}</strong>
            <button type="button" className="btn btn--chip btn--icon" aria-label="Próxima semana" onClick={() => goToWeek(addDays(weekStart, 7))}>
              ›
            </button>
            <button type="button" className="btn btn--chip" onClick={() => goToWeek(mondayOf(today))}>
              Hoje
            </button>
          </div>
        </div>

        {week && (
          <div className="summary">
            <StatusBadge status={week.status} />
            <span>
              Total da semana: <strong>{formatHours(week.totalSeconds)}</strong>
            </span>
            {week.submittedAt && week.status !== "open" && (
              <span className="muted">
                Enviada em {new Date(week.submittedAt).toLocaleString("pt-BR")} (revisão {week.revision})
              </span>
            )}

            <span className="summary__spacer" />

            {editable && (
              <button type="button" className="btn" onClick={() => setAddingOn(defaultAddDate)}>
                + Adicionar tempo
              </button>
            )}

            {confirming ? (
              <span className="confirm" role="group" aria-label="Confirmar envio da semana">
                <span>Depois de enviada, a semana fica bloqueada para edição.</span>
                <button type="button" className="btn btn--primary" disabled={busy} onClick={() => void handleSubmit()}>
                  Confirmar envio
                </button>
                <button type="button" className="btn" disabled={busy} onClick={() => setConfirming(false)}>
                  Cancelar
                </button>
              </span>
            ) : (
              <button type="button" className="btn btn--primary" disabled={!canSubmit} onClick={() => setConfirming(true)}>
                Enviar semana
              </button>
            )}
          </div>
        )}

        {week && decisionBanner(week) && (
          <p className={decisionBanner(week)!.className} role="status">
            {decisionBanner(week)!.text}
          </p>
        )}

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

        {week ? <WeekGrid week={week} today={today} /> : !error && <p className="muted">Carregando…</p>}
      </section>

      {week && (
        <section className="card">
          <h2>Lançamentos</h2>
          <EntryList client={client} entries={week.entries} editable={editable} onChanged={reload} />
        </section>
      )}

      {week && week.decisions.length > 0 && (
        <section className="card">
          <h2>Histórico de decisões</h2>
          <DecisionHistory decisions={week.decisions} />
        </section>
      )}

      <MonthCalendar
        month={month}
        data={monthData}
        selectedWeekStart={weekStart}
        today={today}
        onMonthChange={(delta) => setMonth(addMonths(month, delta))}
        onPickDay={(date) => goToWeek(mondayOf(date))}
        onAddTime={setAddingOn}
      />

      {addingOn !== null && (
        <AddTimePanel
          client={client}
          date={addingOn}
          resources={addTimeResources}
          resourcesError={addTimeError}
          onClose={closeAddTime}
          onSaved={handleAddTimeSaved}
        />
      )}
    </div>
  );
}
