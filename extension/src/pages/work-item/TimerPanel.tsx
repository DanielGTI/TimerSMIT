import { useEffect, useState } from "react";
import { ActivitySelect } from "../../components/ActivitySelect";
import type { ActivityTypeDto } from "../../lib/api/activityTypes";
import type { ApiClient } from "../../lib/api/client";
import { startTimer, stopTimer, type TimerDto } from "../../lib/api/timer";
import type { CurrentWorkItem } from "../../lib/devops/workItems";
import { formatElapsed } from "../../lib/time/format";

interface TimerPanelProps {
  client: ApiClient;
  project: { id: string; name: string };
  workItem: CurrentWorkItem;
  activityTypes: ActivityTypeDto[];
  timer: TimerDto | null;
  onTimerChange: (timer: TimerDto | null) => void;
}

export function TimerPanel({
  client,
  project,
  workItem,
  activityTypes,
  timer,
  onTimerChange,
}: TimerPanelProps): JSX.Element {
  const [activityId, setActivityId] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  async function run(action: () => Promise<void>) {
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      await action();
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : String(failure));
    } finally {
      setBusy(false);
    }
  }

  const handleStart = () =>
    run(async () => {
      const started = await startTimer(client, {
        projectId: project.id,
        projectName: project.name,
        workItemId: workItem.id,
        activityTypeId: activityId ? Number(activityId) : undefined,
        title: workItem.title,
        workItemType: workItem.workItemType,
      });
      onTimerChange(started);
    });

  const handleStop = () =>
    run(async () => {
      if (!timer) return;
      const entries = await stopTimer(client, { timerId: timer.id });
      const totalSeconds = entries.reduce((sum, entry) => sum + entry.durationSeconds, 0);
      onTimerChange(null);
      setNotice(`Timer parado: ${formatElapsed(totalSeconds)} registrados.`);
    });

  const isForThisWorkItem = timer !== null && timer.workItemId === workItem.id;

  return (
    <section className="card">
      <h2>Timer</h2>

      {timer === null && (
        <div className="timer-actions">
          <div className="field">
            <span className="field__label">Atividade</span>
            <ActivitySelect
              label="Atividade do timer"
              options={activityTypes}
              value={activityId}
              onChange={setActivityId}
              disabled={busy}
            />
          </div>
          <button type="button" className="btn btn--primary" disabled={busy} onClick={() => void handleStart()}>
            Iniciar timer
          </button>
        </div>
      )}

      {timer !== null && !isForThisWorkItem && (
        <p>Há um timer ativo em outro work item (#{timer.workItemId}). Pare-o antes de iniciar um novo aqui.</p>
      )}

      {timer !== null && isForThisWorkItem && (
        <>
          <RunningClock startedAtUtc={timer.startedAtUtc} />
          <p className="muted">
            Atividade: {activityTypes.find((type) => type.id === timer.activityTypeId)?.name ?? "Não definido"}
          </p>
          <button type="button" className="btn btn--danger" disabled={busy} onClick={() => void handleStop()}>
            Parar timer
          </button>
        </>
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
    </section>
  );
}

function RunningClock({ startedAtUtc }: { startedAtUtc: string }): JSX.Element {
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    const interval = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(interval);
  }, []);

  return <div className="timer-clock">{formatElapsed((now - new Date(startedAtUtc).getTime()) / 1000)}</div>;
}
