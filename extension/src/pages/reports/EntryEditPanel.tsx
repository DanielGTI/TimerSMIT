import { useEffect, useId, useState, type FormEvent } from "react";
import { ActivitySelect } from "../../components/ActivitySelect";
import { Switch } from "../../components/Switch";
import { WorkItemPicker } from "../../components/WorkItemPicker";
import { adminDeleteEntry, adminUpdateEntry, type AdminEntryChanges } from "../../lib/api/adminEntries";
import type { ApiClient } from "../../lib/api/client";
import type { ReportOptionsDto, ReportRowDto } from "../../lib/api/reports";
import type { WorkItemDetails } from "../../lib/devops/workItemSearch";
import { formatDuration, initials, minutesToTime, parseDuration, timeToMinutes } from "../../lib/time/format";

interface EntryEditPanelProps {
  client: ApiClient;
  row: ReportRowDto;
  options: ReportOptionsDto | null;
  onClose: () => void;
  /** Depois de salvar ou excluir: quem abriu recarrega o relatório. */
  onSaved: (message: string) => void;
}

const MINUTES_PER_DAY = 24 * 60;

const errorText = (failure: unknown): string => (failure instanceof Error ? failure.message : String(failure));

const brDate = (iso: string): string => {
  const [year, month, day] = iso.split("-");
  return `${day}/${month}/${year}`;
};

/**
 * Correção de um lançamento pelo administrador, aberta pelo lápis da grade
 * detalhada: data, De/Até ou duração, work item, atividade, comentário e
 * faturável; ou a exclusão. Só vai ao servidor o que mudou, e ele recusa se
 * a semana não estiver aberta ou se o lançamento mudou nesse meio-tempo.
 */
export function EntryEditPanel({ client, row, options, onClose, onSaved }: EntryEditPanelProps): JSX.Element {
  const ids = useId();
  const initialMinutes = Math.round(row.durationSeconds / 60);
  const [localDate, setLocalDate] = useState(row.localDate);
  const [fromText, setFromText] = useState(row.startTime ?? "");
  const [toText, setToText] = useState(row.endTime ?? "");
  const [durationText, setDurationText] = useState(formatDuration(initialMinutes));
  const [activityId, setActivityId] = useState<string | null>(row.activityTypeId);
  const [note, setNote] = useState(row.note ?? "");
  const [billable, setBillable] = useState(row.billable);
  const [changingWorkItem, setChangingWorkItem] = useState(false);
  const [workItem, setWorkItem] = useState<WorkItemDetails | null>(null);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    function closeOnEscape(event: KeyboardEvent) {
      if (event.key === "Escape") onClose();
    }
    document.addEventListener("keydown", closeOnEscape);
    return () => document.removeEventListener("keydown", closeOnEscape);
  }, [onClose]);

  const minutes = parseDuration(durationText);
  const from = timeToMinutes(fromText);
  const to = timeToMinutes(toText);
  const hasTime = fromText !== "" || toText !== "";
  const timeInvalid = hasTime && (from === null || to === null || to <= from);
  const durationInvalid = minutes === null || minutes <= 0;
  const pastMidnight = from !== null && minutes !== null && from + minutes > MINUTES_PER_DAY;

  // Atividades: as habilitadas e, se for o caso, a desabilitada que o lançamento já usa.
  const activities = (options?.activityTypes ?? [])
    .filter((type) => type.enabled || type.id === row.activityTypeId)
    .map((type) => ({ id: type.id, name: type.name, color: type.color, defaultBillable: false }));

  // Faturável só aparece em projeto que usa a opção; trocando de projeto, o servidor decide.
  const usesBillable = !workItem && (options?.projects.find((project) => project.id === row.projectId)?.usesBillable ?? false);

  function handleRangeChange(nextFrom: string, nextTo: string) {
    setFromText(nextFrom);
    setToText(nextTo);
    const start = timeToMinutes(nextFrom);
    const end = timeToMinutes(nextTo);
    if (start !== null && end !== null && end > start) setDurationText(formatDuration(end - start));
  }

  function handleDurationChange(text: string) {
    setDurationText(text);
    const next = parseDuration(text);
    if (next !== null && from !== null && from + next < MINUTES_PER_DAY) setToText(minutesToTime(from + next));
  }

  function changes(): AdminEntryChanges {
    const result: AdminEntryChanges = {};
    if (localDate !== row.localDate) result.localDate = localDate;

    const startTime = hasTime ? fromText : null;
    if (startTime !== row.startTime) result.startTime = startTime;
    if (minutes !== null && minutes !== initialMinutes) result.durationSeconds = minutes * 60;

    if (activityId !== row.activityTypeId) result.activityTypeId = activityId === null ? null : Number(activityId);
    const trimmed = note.trim();
    if (trimmed !== (row.note ?? "").trim()) result.note = trimmed === "" ? null : trimmed;
    if (usesBillable && billable !== row.billable) result.billable = billable;

    if (workItem && workItem.projectId && (workItem.id !== row.workItemId || workItem.projectName !== row.projectName)) {
      result.workItemId = workItem.id;
      result.projectId = workItem.projectId;
      result.projectName = workItem.projectName;
      result.title = workItem.title;
      result.workItemType = workItem.workItemType;
      if (workItem.iterationPath) result.iterationPath = workItem.iterationPath;
    }
    return result;
  }

  async function run(action: () => Promise<string>) {
    setBusy(true);
    setError(null);
    try {
      onSaved(await action());
    } catch (failure) {
      setError(errorText(failure));
      setBusy(false);
    }
  }

  function handleSubmit(event: FormEvent) {
    event.preventDefault();
    const payload = changes();
    if (Object.keys(payload).length === 0) {
      onClose();
      return;
    }
    void run(async () => {
      await adminUpdateEntry(client, row.id, row.revision ?? 1, payload);
      return `Lançamento de ${row.memberName} corrigido.`;
    });
  }

  const remove = () =>
    void run(async () => {
      await adminDeleteEntry(client, row.id);
      return `Lançamento de ${row.memberName} (${brDate(row.localDate)}, ${formatDuration(initialMinutes)}) excluído.`;
    });

  const workItemMissingProject = workItem !== null && !workItem.projectId;
  const canSave = !busy && !durationInvalid && !timeInvalid && !pastMidnight && localDate !== "" && !workItemMissingProject;

  return (
    <div
      className="drawer-backdrop"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose();
      }}
    >
      <aside className="drawer" role="dialog" aria-modal="true" aria-label="Editar lançamento">
        <form className="add-time entry-edit" onSubmit={handleSubmit}>
          <div className="add-time__header">
            <h2>Editar lançamento</h2>
            <button type="button" className="btn btn--icon btn--ghost" aria-label="Fechar" onClick={onClose}>
              ×
            </button>
          </div>

          <div className="user-chip">
            <span className="avatar" aria-hidden="true">
              {initials(row.memberName)}
            </span>
            <span>{row.memberName}</span>
          </div>
          <p className="muted">Correção feita pelo administrador; fica registrada na auditoria.</p>

          <div className="field">
            <span className="field__label">Work item</span>
            {changingWorkItem ? (
              <>
                <WorkItemPicker selected={workItem} onSelect={setWorkItem} autoFocus />
                {workItemMissingProject && <p className="field__error">Não foi possível descobrir o projeto deste work item.</p>}
                <button
                  type="button"
                  className="btn btn--small"
                  onClick={() => {
                    setChangingWorkItem(false);
                    setWorkItem(null);
                  }}
                >
                  Manter o work item atual
                </button>
              </>
            ) : (
              <p className="entry-edit__current">
                <strong>{row.workItemId}</strong> {row.workItemTitle ?? ""}
                <span className="muted block">{row.projectName}</span>
                <button type="button" className="btn btn--small" onClick={() => setChangingWorkItem(true)}>
                  Trocar work item
                </button>
              </p>
            )}
          </div>

          <div className="field">
            <label htmlFor={`${ids}-date`}>Data</label>
            <input
              id={`${ids}-date`}
              className="input input--short"
              type="date"
              value={localDate}
              onChange={(event) => setLocalDate(event.target.value)}
              required
            />
          </div>

          <div className="field-row">
            <div className="field">
              <label htmlFor={`${ids}-from`}>De</label>
              <input
                id={`${ids}-from`}
                className={timeInvalid ? "input input--short input--invalid" : "input input--short"}
                type="time"
                value={fromText}
                onChange={(event) => handleRangeChange(event.target.value, toText)}
              />
            </div>
            <div className="field">
              <label htmlFor={`${ids}-to`}>Até</label>
              <input
                id={`${ids}-to`}
                className={timeInvalid ? "input input--short input--invalid" : "input input--short"}
                type="time"
                value={toText}
                onChange={(event) => handleRangeChange(fromText, event.target.value)}
              />
            </div>
            <div className="field">
              <label htmlFor={`${ids}-duration`}>Duração</label>
              <input
                id={`${ids}-duration`}
                className={durationInvalid ? "input input--short input--invalid" : "input input--short"}
                type="text"
                inputMode="numeric"
                placeholder="00:00"
                value={durationText}
                aria-invalid={durationInvalid}
                onChange={(event) => handleDurationChange(event.target.value)}
              />
            </div>
          </div>
          {timeInvalid && <p className="field__error">Preencha De e Até, com o fim depois do início.</p>}
          {!timeInvalid && durationInvalid && <p className="field__error">Use a duração no formato HH:MM (ex.: 01:30).</p>}
          {pastMidnight && <p className="field__error">O horário passa da meia-noite.</p>}
          {hasTime && (
            <p className="muted">
              <button type="button" className="btn btn--small" onClick={() => handleRangeChange("", "")}>
                Limpar horário
              </button>{" "}
              (fica só a duração)
            </p>
          )}

          <div className="field">
            <span className="field__label">Atividade</span>
            <ActivitySelect label="Atividade do lançamento" options={activities} value={activityId} onChange={setActivityId} />
          </div>

          <div className="field">
            <label htmlFor={`${ids}-note`}>Comentário</label>
            <textarea
              id={`${ids}-note`}
              className="input"
              maxLength={2000}
              value={note}
              onChange={(event) => setNote(event.target.value)}
            />
          </div>

          {usesBillable && <Switch label="Horas faturáveis" checked={billable} onChange={setBillable} />}

          {error && (
            <p className="alert" role="alert">
              {error}
            </p>
          )}

          <div className="actions entry-edit__actions">
            {confirmDelete ? (
              <span className="entry-edit__confirm">
                Excluir este lançamento?{" "}
                <button type="button" className="btn btn--small btn--danger" disabled={busy} onClick={remove}>
                  Sim, excluir
                </button>{" "}
                <button type="button" className="btn btn--small" disabled={busy} onClick={() => setConfirmDelete(false)}>
                  Não
                </button>
              </span>
            ) : (
              <button type="button" className="btn" disabled={busy} onClick={() => setConfirmDelete(true)}>
                Excluir
              </button>
            )}
            <span className="entry-edit__spacer" />
            <button type="button" className="btn" disabled={busy} onClick={onClose}>
              Cancelar
            </button>
            <button type="submit" className="btn btn--primary" disabled={!canSave}>
              {busy ? "Salvando…" : "Salvar"}
            </button>
          </div>
        </form>
      </aside>
    </div>
  );
}
