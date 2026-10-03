import { useEffect, useMemo, useState } from "react";
import { fetchActivityTypes, type ActivityTypeDto } from "../../lib/api/activityTypes";
import { createApiClient } from "../../lib/api/client";
import { getApiBaseUrl } from "../../lib/api/config";
import { fetchCurrentSession, type CurrentSession } from "../../lib/api/me";
import { fetchActiveTimer, type TimerDto } from "../../lib/api/timer";
import { getWebContext } from "../../lib/devops/sdk";
import { getCurrentWorkItem, type CurrentWorkItem } from "../../lib/devops/workItems";
import { ManualEntryForm } from "../../components/ManualEntryForm";
import { TimerPanel } from "./TimerPanel";

interface Loaded {
  session: CurrentSession;
  workItem: CurrentWorkItem;
  project: { id: string; name: string };
  activityTypes: ActivityTypeDto[];
}

type Status = { kind: "loading" } | { kind: "error"; message: string } | ({ kind: "ready" } & Loaded);

/**
 * Guia do work item (US1, T019): timer e lançamento manual deste item.
 * Título/tipo do work item vêm do form já aberto (lib/devops/workItems.ts) e
 * seguem ao backend só como cosmético (ver WorkItemAccessService).
 */
export function WorkItemGuide(): JSX.Element {
  const [status, setStatus] = useState<Status>({ kind: "loading" });
  const [timer, setTimer] = useState<TimerDto | null>(null);

  const client = useMemo(() => createApiClient({ apiBaseUrl: getApiBaseUrl() }), []);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      try {
        const [session, workItem, webContext, activeTimer, activityTypes] = await Promise.all([
          fetchCurrentSession(client),
          getCurrentWorkItem(),
          getWebContext(),
          fetchActiveTimer(client),
          fetchActivityTypes(client),
        ]);

        if (cancelled) return;

        setTimer(activeTimer);
        setStatus({
          kind: "ready",
          session,
          workItem,
          project: { id: webContext.project.id, name: webContext.project.name },
          activityTypes,
        });
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

  if (status.kind === "loading") {
    return <p className="muted">Conectando ao backend…</p>;
  }

  if (status.kind === "error") {
    return (
      <p className="alert" role="alert">
        Não foi possível carregar o controle de horas: {status.message}
      </p>
    );
  }

  const { session, workItem, project, activityTypes } = status;

  return (
    <div className="page">
      <TimerPanel
        client={client}
        project={project}
        workItem={workItem}
        activityTypes={activityTypes}
        timer={timer}
        onTimerChange={setTimer}
      />

      <ManualEntryForm
        client={client}
        project={project}
        workItem={workItem}
        activityTypes={activityTypes}
        displayName={session.displayName}
        requireTime={session.overtime?.requireTimeOfDay ?? false}
        minDurationMinutes={session.policy?.minDurationMinutes ?? 1}
        billableEnabled={session.billableProjectIds?.includes(project.id) ?? false}
      />

      <p className="muted">
        Conectado como <strong>{session.displayName}</strong> em <strong>{session.organizationName}</strong>.
      </p>
    </div>
  );
}
