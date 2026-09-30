import { useId, useState, type FormEvent } from "react";
import { ActivitySelect } from "../../components/ActivitySelect";
import { Switch } from "../../components/Switch";
import type { ActivityTypeDto } from "../../lib/api/activityTypes";
import type { ApiClient } from "../../lib/api/client";
import { createManualEntry } from "../../lib/api/entries";
import type { CurrentWorkItem } from "../../lib/devops/workItems";
import {
  formatDuration,
  initials,
  minutesToTime,
  nowAsTime,
  parseDuration,
  timeToMinutes,
  todayLocalIso,
} from "../../lib/time/format";

interface ManualEntryFormProps {
  client: ApiClient;
  project: { id: string; name: string };
  workItem: CurrentWorkItem;
  activityTypes: ActivityTypeDto[];
  displayName: string;
}

type Feedback = { kind: "ok" | "error"; text: string } | null;

const QUICK_ADDS = [
  { label: "+0,5h", minutes: 30 },
  { label: "+1h", minutes: 60 },
  { label: "+2h", minutes: 120 },
  { label: "+4h", minutes: 240 },
];

const END_OF_DAY = 23 * 60 + 59;

/**
 * Lançamento manual no formato do "Add time" do 7pace: data, duração
 * (HH:MM + atalhos), intervalo De/Até, atividade, comentário e faturável.
 * De/Até são só uma calculadora — o que se grava é data + duração.
 */
export function ManualEntryForm({
  client,
  project,
  workItem,
  activityTypes,
  displayName,
}: ManualEntryFormProps): JSX.Element {
  const ids = useId();
  const [localDate, setLocalDate] = useState(() => todayLocalIso());
  const [durationText, setDurationText] = useState("00:00");
  const [fromText, setFromText] = useState(() => nowAsTime());
  const [toText, setToText] = useState(() => nowAsTime());
  const [activityId, setActivityId] = useState<string | null>(null);
  const [note, setNote] = useState("");
  const [billable, setBillable] = useState(false);
  const [busy, setBusy] = useState(false);
  const [feedback, setFeedback] = useState<Feedback>(null);

  const durationMinutes = parseDuration(durationText);
  const durationInvalid = durationText.trim() !== "" && durationMinutes === null;
  const canSave = durationMinutes !== null && durationMinutes > 0 && !busy;

  function applyDuration(minutes: number) {
    setDurationText(formatDuration(minutes));
    const from = timeToMinutes(fromText);
    if (from !== null && from + minutes <= END_OF_DAY) {
      setToText(minutesToTime(from + minutes));
    }
  }

  function handleDurationChange(text: string) {
    setDurationText(text);
    const minutes = parseDuration(text);
    const from = timeToMinutes(fromText);
    if (minutes !== null && from !== null && from + minutes <= END_OF_DAY) {
      setToText(minutesToTime(from + minutes));
    }
  }

  function handleRangeChange(nextFrom: string, nextTo: string) {
    setFromText(nextFrom);
    setToText(nextTo);
    const from = timeToMinutes(nextFrom);
    const to = timeToMinutes(nextTo);
    if (from !== null && to !== null && to >= from) {
      setDurationText(formatDuration(to - from));
    }
  }

  function handleActivityChange(id: string | null) {
    setActivityId(id);
    const chosen = activityTypes.find((type) => type.id === id);
    if (chosen) setBillable(chosen.defaultBillable);
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    if (durationMinutes === null || durationMinutes <= 0) return;

    setBusy(true);
    setFeedback(null);
    try {
      await createManualEntry(client, {
        projectId: project.id,
        projectName: project.name,
        workItemId: workItem.id,
        localDate,
        durationSeconds: durationMinutes * 60,
        activityTypeId: activityId ? Number(activityId) : undefined,
        billable,
        note: note.trim() || undefined,
        title: workItem.title,
        workItemType: workItem.workItemType,
      });
      setFeedback({ kind: "ok", text: `Lançamento de ${formatDuration(durationMinutes)} registrado.` });
      setDurationText("00:00");
      setFromText(nowAsTime());
      setToText(nowAsTime());
      setNote("");
    } catch (error) {
      setFeedback({ kind: "error", text: error instanceof Error ? error.message : String(error) });
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="card" onSubmit={(event) => void handleSubmit(event)}>
      <h2>Adicionar tempo</h2>

      <div className="user-chip">
        <span className="avatar" aria-hidden="true">
          {initials(displayName)}
        </span>
        <span>{displayName}</span>
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

      <div className="field">
        <label htmlFor={`${ids}-duration`}>Duração</label>
        <div className="duration-row">
          <input
            id={`${ids}-duration`}
            className={durationInvalid ? "input input--short input--invalid" : "input input--short"}
            type="text"
            inputMode="numeric"
            placeholder="00:00"
            value={durationText}
            aria-invalid={durationInvalid}
            onChange={(event) => handleDurationChange(event.target.value)}
            onBlur={() => durationMinutes !== null && setDurationText(formatDuration(durationMinutes))}
          />
          <div className="quick">
            {QUICK_ADDS.map((quick) => (
              <button
                key={quick.label}
                type="button"
                className="btn btn--chip"
                onClick={() => applyDuration((durationMinutes ?? 0) + quick.minutes)}
              >
                {quick.label}
              </button>
            ))}
          </div>
        </div>
        {durationInvalid && <p className="field__error">Use o formato HH:MM (ex.: 01:30).</p>}
      </div>

      <div className="field-row">
        <div className="field">
          <label htmlFor={`${ids}-from`}>De</label>
          <input
            id={`${ids}-from`}
            className="input input--short"
            type="time"
            value={fromText}
            onChange={(event) => handleRangeChange(event.target.value, toText)}
          />
        </div>
        <div className="field">
          <label htmlFor={`${ids}-to`}>Até</label>
          <input
            id={`${ids}-to`}
            className="input input--short"
            type="time"
            value={toText}
            onChange={(event) => handleRangeChange(fromText, event.target.value)}
          />
        </div>
      </div>

      <div className="field">
        <span className="field__label">Atividade</span>
        <ActivitySelect
          label="Atividade do lançamento"
          options={activityTypes}
          value={activityId}
          onChange={handleActivityChange}
        />
      </div>

      <div className="field">
        <label htmlFor={`${ids}-note`}>Comentário</label>
        <textarea
          id={`${ids}-note`}
          className="input"
          placeholder="Adicionar comentário"
          maxLength={2000}
          value={note}
          onChange={(event) => setNote(event.target.value)}
        />
      </div>

      <Switch label="Horas faturáveis" checked={billable} onChange={setBillable} />

      <div className="actions">
        <button type="submit" className="btn btn--primary" disabled={!canSave}>
          Salvar
        </button>
      </div>

      {feedback && (
        <p className={feedback.kind === "ok" ? "notice" : "alert"} role={feedback.kind === "ok" ? "status" : "alert"}>
          {feedback.text}
        </p>
      )}
    </form>
  );
}
