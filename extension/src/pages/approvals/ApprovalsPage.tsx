import { useCallback, useEffect, useMemo, useState } from "react";
import { DecisionHistory } from "../../components/DecisionHistory";
import { StatusBadge } from "../../components/StatusBadge";
import { TabList } from "../../components/TabList";
import {
  decideApproval,
  fetchApproval,
  fetchDecidedApprovals,
  fetchPendingApprovals,
  reopenApproval,
  type ApprovalDetailDto,
  type DecidedApprovalDto,
  type PendingApprovalDto,
} from "../../lib/api/approvals";
import { createApiClient } from "../../lib/api/client";
import { getApiBaseUrl } from "../../lib/api/config";
import { formatHours, todayLocalIso } from "../../lib/time/format";
import { formatWeekRange } from "../../lib/time/weeks";
import { EntryList } from "../timesheet/EntryList";
import { WeekAlerts } from "../../components/WeekAlerts";
import { AdditionalHoursReview, type Denials } from "./AdditionalHoursReview";
import { OvertimeDecision } from "./OvertimeDecision";
import { fetchPendingOvertime, type PendingOvertimeDto } from "../../lib/api/overtime";
import { overtimeStatusText } from "../timesheet/MyOvertime";
import { WeekGrid } from "../timesheet/WeekGrid";

type View = "pending" | "decided" | "overtime";
type Mode = "idle" | "approve" | "reject" | "reopen";

const errorText = (failure: unknown): string => (failure instanceof Error ? failure.message : String(failure));
const dateTime = (iso: string | null): string => (iso ? new Date(iso).toLocaleString("pt-BR") : "—");

/**
 * Caixa de aprovações (US3, T029): semanas aguardando a decisão de quem está
 * logado, detalhe com os lançamentos e o histórico, e as ações permitidas.
 * Quem pode o quê vem do servidor (`permissions`) — a tela só obedece.
 */
export function ApprovalsPage(): JSX.Element {
  const client = useMemo(() => createApiClient({ apiBaseUrl: getApiBaseUrl() }), []);
  const today = todayLocalIso();

  const [view, setView] = useState<View>("pending");
  const [pending, setPending] = useState<PendingApprovalDto[] | null>(null);
  const [decided, setDecided] = useState<DecidedApprovalDto[] | null>(null);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [detail, setDetail] = useState<ApprovalDetailDto | null>(null);
  const [mode, setMode] = useState<Mode>("idle");
  const [reason, setReason] = useState("");
  const [denials, setDenials] = useState<Denials>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [tick, setTick] = useState(0);
  const [pendingOvertime, setPendingOvertime] = useState<PendingOvertimeDto[] | null>(null);
  const [overtimeError, setOvertimeError] = useState<string | null>(null);

  const reload = useCallback(() => setTick((value) => value + 1), []);

  useEffect(() => {
    let cancelled = false;

    Promise.all([fetchPendingApprovals(client), fetchDecidedApprovals(client)])
      .then(([pendingItems, decidedItems]) => {
        if (cancelled) return;
        setPending(pendingItems);
        setDecided(decidedItems);
      })
      .catch((failure: unknown) => !cancelled && setError(errorText(failure)));

    return () => {
      cancelled = true;
    };
  }, [client, tick]);

  // Horas extras informadas e a confirmar, aguardando a decisão de quem está logado.
  useEffect(() => {
    let cancelled = false;

    fetchPendingOvertime(client)
      .then((items) => {
        if (cancelled) return;
        setPendingOvertime(items);
        setOvertimeError(null);
      })
      .catch((failure: unknown) => !cancelled && setOvertimeError(errorText(failure)));

    return () => {
      cancelled = true;
    };
  }, [client, tick]);

  useEffect(() => {
    if (!selectedId) {
      setDetail(null);
      return;
    }

    let cancelled = false;

    fetchApproval(client, selectedId)
      .then((data) => !cancelled && setDetail(data))
      .catch((failure: unknown) => !cancelled && setError(errorText(failure)));

    return () => {
      cancelled = true;
    };
  }, [client, selectedId, tick]);

  function select(id: string) {
    setSelectedId(id);
    setMode("idle");
    setReason("");
    setDenials({});
    setError(null);
    setNotice(null);
  }

  async function act(action: () => Promise<ApprovalDetailDto>, success: string) {
    setBusy(true);
    setError(null);
    try {
      setDetail(await action());
      setNotice(success);
      setMode("idle");
      setReason("");
    } catch (failure) {
      setError(errorText(failure));
      setMode("idle");
    } finally {
      setBusy(false);
      reload();
    }
  }

  const approve = () => {
    const unauthorized = Object.entries(denials).map(([entryId, text]) => ({ entryId, reason: text.trim() }));
    if (unauthorized.some((item) => item.reason === "")) {
      setError("Informe o motivo de cada hora adicional não autorizada.");
      return Promise.resolve();
    }
    return act(
      () =>
        decideApproval(client, detail!.submission.id, {
          decision: "approve",
          revision: detail!.submission.revision,
          ...(unauthorized.length > 0 ? { unauthorized } : {}),
        }),
      unauthorized.length > 0
        ? `Semana aprovada. ${unauthorized.length} hora(s) adicional(is) marcada(s) como não autorizada(s).`
        : "Semana aprovada.",
    );
  };

  const reject = () => {
    if (reason.trim() === "") {
      setError("Informe o motivo da rejeição.");
      return Promise.resolve();
    }
    return act(
      () =>
        decideApproval(client, detail!.submission.id, {
          decision: "reject",
          revision: detail!.submission.revision,
          reason: reason.trim(),
        }),
      "Semana rejeitada. O colaborador verá o motivo e poderá corrigir e reenviar.",
    );
  };

  const reopen = () => {
    if (reason.trim() === "") {
      setError("Informe a justificativa da reabertura.");
      return Promise.resolve();
    }
    return act(() => reopenApproval(client, detail!.submission.id, reason.trim()), "Semana reaberta para edição.");
  };

  const pendingConfirmations = (detail?.week.overtimeConfirmations ?? []).filter((item) => item.status === "pending").length;

  const cancel = () => {
    setMode("idle");
    setReason("");
    setDenials({});
    setError(null);
  };

  return (
    <div className="page page--wide">
      <section className="card">
        <div className="toolbar">
          <h2>Aprovações</h2>
          <TabList
            label="Filtro"
            tabs={[
              { id: "pending", label: `Pendentes${pending ? ` (${pending.length})` : ""}` },
              { id: "decided", label: "Decididas por mim" },
              { id: "overtime", label: `Horas extras${pendingOvertime ? ` (${pendingOvertime.length})` : ""}` },
            ]}
            value={view}
            onChange={setView}
          />
        </div>

        {error && !detail && (
          <p className="alert" role="alert">
            {error}
          </p>
        )}

        {view === "overtime" && (
          <div className="overtime-inbox">
            <p className="muted">
              Horas extras informadas pelas pessoas (antes ou depois de fazer) e horas a confirmar de quem tem hora extra
              restrita. Hora a confirmar só vira lançamento se você confirmar.
            </p>
            {notice && (
              <p className="notice" role="status">
                {notice}
              </p>
            )}
            {overtimeError && (
              <p className="alert" role="alert">
                {overtimeError}
              </p>
            )}
            {pendingOvertime === null && !overtimeError && <p className="muted">Carregando…</p>}
            {pendingOvertime?.length === 0 && <p className="muted">Nenhuma hora extra aguardando sua decisão.</p>}
            {pendingOvertime && pendingOvertime.length > 0 && (
              <ul className="overtime-decisions" aria-label="Horas extras para decidir">
                {pendingOvertime.map((item) => (
                  <OvertimeDecision
                    key={item.id}
                    client={client}
                    item={item}
                    onDecided={(message) => {
                      setNotice(message);
                      reload();
                    }}
                  />
                ))}
              </ul>
            )}
          </div>
        )}

        <div className="split" hidden={view === "overtime"}>
          <nav className="split__list" aria-label="Semanas">
            {view === "pending" && pending === null && <p className="muted">Carregando…</p>}
            {view === "pending" && pending?.length === 0 && (
              <p className="muted">Nenhuma semana aguardando sua aprovação.</p>
            )}
            {view === "pending" &&
              pending?.map((item) => (
                <button
                  key={item.id}
                  type="button"
                  className={item.id === selectedId ? "list-item list-item--active" : "list-item"}
                  onClick={() => select(item.id)}
                >
                  <strong>{item.submitter.displayName}</strong>
                  <span>{formatWeekRange(item.weekStartDate)}</span>
                  <span className="muted">
                    {formatHours(item.totalSeconds)} · {item.entryCount} lançamentos
                    {item.revision > 1 ? ` · reenvio (revisão ${item.revision})` : ""}
                  </span>
                  <span className="muted">Enviada em {dateTime(item.submittedAt)}</span>
                </button>
              ))}

            {view === "decided" && decided?.length === 0 && <p className="muted">Você ainda não decidiu nenhuma semana.</p>}
            {view === "decided" &&
              decided?.map((item) => (
                <button
                  key={`${item.id}-${item.decidedAt}`}
                  type="button"
                  className={item.id === selectedId ? "list-item list-item--active" : "list-item"}
                  onClick={() => select(item.id)}
                >
                  <strong>{item.submitter.displayName}</strong>
                  <span>{formatWeekRange(item.weekStartDate)}</span>
                  <span className="muted">
                    {item.decision === "approved" ? "Aprovada" : "Rejeitada"} em {dateTime(item.decidedAt)}
                  </span>
                </button>
              ))}
          </nav>

          <div className="split__detail">
            {!detail && <p className="muted">Selecione uma semana para revisar.</p>}

            {detail && (
              <>
                <div className="summary">
                  <h2>{detail.submission.submitter.displayName}</h2>
                  <span>{formatWeekRange(detail.submission.weekStartDate)}</span>
                  <StatusBadge status={detail.submission.status} />
                  <span>
                    Total: <strong>{formatHours(detail.week.totalSeconds)}</strong>
                  </span>
                  <span className="muted">
                    Revisão {detail.submission.revision} · enviada em {dateTime(detail.submission.submittedAt)}
                  </span>
                </div>

                <WeekAlerts alerts={detail.week.alerts} />
                <WeekGrid week={detail.week} today={today} />
                <h3>Lançamentos</h3>
                <EntryList client={client} entries={detail.week.entries} editable={false} onChanged={reload} />

                {(detail.week.overtimeConfirmations ?? []).length > 0 && (
                  <section className="overtime-week" aria-label="Horas extras a confirmar da semana">
                    <h3>Horas extras a confirmar</h3>
                    <p className="muted">
                      Fora do expediente, sem hora extra aprovada antes. Só viram lançamento se você confirmar; decida cada
                      uma antes de aprovar a semana.
                    </p>
                    <ul className="overtime-decisions">
                      {detail.week.overtimeConfirmations!.map((item) =>
                        item.status === "pending" && detail.submission.status !== "approved" ? (
                          <OvertimeDecision
                            key={item.id}
                            client={client}
                            item={item}
                            onDecided={(message) => {
                              setNotice(message);
                              reload();
                            }}
                          />
                        ) : (
                          <li key={item.id} className="muted">
                            {item.dateFrom.split("-").reverse().join("/")} · {item.startTime?.slice(0, 5)}–{item.endTime?.slice(0, 5)} ·{" "}
                            {formatHours(item.secondsPerDay)} · {overtimeStatusText(item)}
                          </li>
                        ),
                      )}
                    </ul>
                  </section>
                )}

                {detail.week.decisions.length > 0 && <h3>Histórico</h3>}
                <DecisionHistory decisions={detail.week.decisions} />

                {detail.permissions.canDecide && detail.permissions.ownWeek && (
                  <p className="muted">
                    Esta é a sua própria semana. Como administrador você pode decidi-la, e isso fica registrado.
                  </p>
                )}

                {mode === "idle" && (
                  <div className="actions actions--start">
                    {detail.permissions.canDecide && (
                      <>
                        <button type="button" className="btn btn--primary" onClick={() => setMode("approve")}>
                          Aprovar semana
                        </button>
                        <button type="button" className="btn" onClick={() => setMode("reject")}>
                          Rejeitar
                        </button>
                      </>
                    )}
                    {detail.permissions.canReopen && (
                      <button type="button" className="btn" onClick={() => setMode("reopen")}>
                        Reabrir semana
                      </button>
                    )}
                  </div>
                )}

                {mode === "approve" && (
                  <div className="confirm-box" role="group" aria-label="Confirmar aprovação">
                    <p>Depois de aprovada, a semana e seus lançamentos ficam bloqueados.</p>
                    {pendingConfirmations > 0 && (
                      <p className="banner banner--warning" role="note">
                        Há {pendingConfirmations} hora(s) extra(s) a confirmar nesta semana. Confirme ou recuse cada uma acima antes
                        de aprovar.
                      </p>
                    )}
                    <AdditionalHoursReview entries={detail.week.entries} denials={denials} onChange={setDenials} disabled={busy} />
                    <button type="button" className="btn btn--primary" disabled={busy || pendingConfirmations > 0} onClick={() => void approve()}>
                      Confirmar aprovação
                    </button>
                    <button type="button" className="btn" disabled={busy} onClick={cancel}>
                      Cancelar
                    </button>
                  </div>
                )}

                {(mode === "reject" || mode === "reopen") && (
                  <div className="confirm-box" role="group" aria-label={mode === "reject" ? "Rejeitar semana" : "Reabrir semana"}>
                    <label htmlFor="decision-reason">
                      {mode === "reject" ? "Motivo da rejeição (obrigatório)" : "Justificativa da reabertura (obrigatória)"}
                    </label>
                    <textarea
                      id="decision-reason"
                      className="input"
                      maxLength={2000}
                      value={reason}
                      onChange={(event) => setReason(event.target.value)}
                    />
                    <div className="actions actions--start">
                      <button
                        type="button"
                        className="btn btn--danger"
                        disabled={busy}
                        onClick={() => void (mode === "reject" ? reject() : reopen())}
                      >
                        {mode === "reject" ? "Confirmar rejeição" : "Confirmar reabertura"}
                      </button>
                      <button type="button" className="btn" disabled={busy} onClick={cancel}>
                        Cancelar
                      </button>
                    </div>
                  </div>
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
              </>
            )}
          </div>
        </div>
      </section>
    </div>
  );
}
