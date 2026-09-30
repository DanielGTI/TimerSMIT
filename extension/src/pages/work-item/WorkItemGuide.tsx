import { useEffect, useMemo, useState, type FormEvent } from "react";
import { createApiClient, type ApiClient } from "../../lib/api/client";
import { getApiBaseUrl } from "../../lib/api/config";
import { createManualEntry } from "../../lib/api/entries";
import { fetchCurrentSession, type CurrentSession } from "../../lib/api/me";
import { fetchActiveTimer, startTimer, stopTimer, type TimerDto } from "../../lib/api/timer";
import { getWebContext } from "../../lib/devops/sdk";
import { getCurrentWorkItem, type CurrentWorkItem } from "../../lib/devops/workItems";

interface ProjectRef {
  id: string;
  name: string;
}

type Status = { kind: "loading" } | { kind: "error"; message: string } | { kind: "ready" };

/**
 * Guia do work item (US1, T019): estabelece sessão, mostra o timer do
 * membro para este item (ou avisa que há um ativo em outro) e permite
 * lançamento manual. Título/tipo do work item vêm do form já aberto
 * (extension/src/lib/devops/workItems.ts) — enviados ao backend só como
 * cosmético (ver WorkItemAccessService).
 */
export function WorkItemGuide(): JSX.Element {
  const [status, setStatus] = useState<Status>({ kind: "loading" });
  const [session, setSession] = useState<CurrentSession | null>(null);
  const [workItem, setWorkItem] = useState<CurrentWorkItem | null>(null);
  const [project, setProject] = useState<ProjectRef | null>(null);
  const [timer, setTimer] = useState<TimerDto | null>(null);
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [, setTick] = useState(0);

  const client = useMemo(() => createApiClient({ apiBaseUrl: getApiBaseUrl() }), []);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      try {
        const [currentSession, currentWorkItem, webContext, activeTimer] = await Promise.all([
          fetchCurrentSession(client),
          getCurrentWorkItem(),
          getWebContext(),
          fetchActiveTimer(client),
        ]);

        if (cancelled) return;

        setSession(currentSession);
        setWorkItem(currentWorkItem);
        setProject({ id: webContext.project.id, name: webContext.project.name });
        setTimer(activeTimer);
        setStatus({ kind: "ready" });
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
  }, [client]);

  useEffect(() => {
    if (!timer || timer.workItemId !== workItem?.id) {
      return;
    }
    const interval = setInterval(() => setTick((value) => value + 1), 1000);
    return () => clearInterval(interval);
  }, [timer, workItem]);

  if (status.kind === "loading") {
    return <p>Conectando ao backend…</p>;
  }

  if (status.kind === "error") {
    return <p role="alert">Não foi possível carregar o controle de horas: {status.message}</p>;
  }

  const timerIsForThisWorkItem = timer !== null && workItem !== null && timer.workItemId === workItem.id;

  async function handleStart() {
    if (!project || !workItem) return;
    setBusy(true);
    setActionError(null);
    try {
      const started = await startTimer(client, {
        projectId: project.id,
        projectName: project.name,
        workItemId: workItem.id,
        title: workItem.title,
        workItemType: workItem.workItemType,
      });
      setTimer(started);
    } catch (error) {
      setActionError(error instanceof Error ? error.message : String(error));
    } finally {
      setBusy(false);
    }
  }

  async function handleStop() {
    if (!timer) return;
    setBusy(true);
    setActionError(null);
    try {
      await stopTimer(client, { timerId: timer.id });
      setTimer(null);
    } catch (error) {
      setActionError(error instanceof Error ? error.message : String(error));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div style={{ padding: 12 }}>
      <p>
        Sessão ativa para <strong>{session?.displayName}</strong> em <strong>{session?.organizationName}</strong>.
      </p>

      {timerIsForThisWorkItem && timer ? (
        <TimerRunning timer={timer} busy={busy} onStop={handleStop} />
      ) : timer ? (
        <p>Há um timer ativo em outro work item (#{timer.workItemId}). Pare-o antes de iniciar um novo aqui.</p>
      ) : (
        <button type="button" disabled={busy} onClick={() => void handleStart()}>
          Iniciar timer
        </button>
      )}

      {actionError && <p role="alert">{actionError}</p>}

      {project && workItem && <ManualEntryForm client={client} project={project} workItem={workItem} />}
    </div>
  );
}

function TimerRunning({
  timer,
  busy,
  onStop,
}: {
  timer: TimerDto;
  busy: boolean;
  onStop: () => void;
}): JSX.Element {
  const elapsedSeconds = Math.max(0, Math.floor((Date.now() - new Date(timer.startedAtUtc).getTime()) / 1000));
  const hh = String(Math.floor(elapsedSeconds / 3600)).padStart(2, "0");
  const mm = String(Math.floor((elapsedSeconds % 3600) / 60)).padStart(2, "0");
  const ss = String(elapsedSeconds % 60).padStart(2, "0");

  return (
    <div>
      <p>
        Timer rodando: {hh}:{mm}:{ss}
      </p>
      <button type="button" disabled={busy} onClick={onStop}>
        Parar timer
      </button>
    </div>
  );
}

function ManualEntryForm({
  client,
  project,
  workItem,
}: {
  client: ApiClient;
  project: ProjectRef;
  workItem: CurrentWorkItem;
}): JSX.Element {
  const [localDate, setLocalDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [minutes, setMinutes] = useState(30);
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [feedback, setFeedback] = useState<string | null>(null);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setFeedback(null);
    try {
      await createManualEntry(client, {
        projectId: project.id,
        projectName: project.name,
        workItemId: workItem.id,
        localDate,
        durationSeconds: minutes * 60,
        note: note || undefined,
        title: workItem.title,
        workItemType: workItem.workItemType,
      });
      setFeedback("Lançamento manual registrado.");
      setNote("");
    } catch (error) {
      setFeedback(error instanceof Error ? error.message : String(error));
    } finally {
      setBusy(false);
    }
  }

  return (
    <form onSubmit={(event) => void handleSubmit(event)} style={{ marginTop: 16, borderTop: "1px solid #ccc", paddingTop: 12 }}>
      <h3>Lançamento manual</h3>
      <div>
        <label>
          Data{" "}
          <input type="date" value={localDate} onChange={(e) => setLocalDate(e.target.value)} required />
        </label>
      </div>
      <div>
        <label>
          Minutos{" "}
          <input
            type="number"
            min={1}
            value={minutes}
            onChange={(e) => setMinutes(Number(e.target.value))}
            required
          />
        </label>
      </div>
      <div>
        <label>
          Comentário{" "}
          <input type="text" value={note} onChange={(e) => setNote(e.target.value)} maxLength={2000} />
        </label>
      </div>
      <button type="submit" disabled={busy}>
        Lançar
      </button>
      {feedback && <p>{feedback}</p>}
    </form>
  );
}
