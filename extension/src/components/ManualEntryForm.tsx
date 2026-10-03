import { useId, useState, type FormEvent, type ReactNode } from "react";
import { ActivitySelect } from "./ActivitySelect";
import { Switch } from "./Switch";
import type { ActivityTypeDto } from "../lib/api/activityTypes";
import type { ApiClient } from "../lib/api/client";
import { createManualEntry } from "../lib/api/entries";
import type { CurrentWorkItem } from "../lib/devops/workItems";
import {
  formatDuration,
  initials,
  minutesToTime,
  nowAsTime,
  parseDuration,
  timeToMinutes,
  todayLocalIso,
} from "../lib/time/format";

interface ManualEntryFormProps {
  client: ApiClient;
  /** Projeto e work item do lançamento; enquanto faltarem (busca ainda vazia), não salva. */
  project: { id: string; name: string } | null;
  workItem: CurrentWorkItem | null;
  activityTypes: ActivityTypeDto[];
  displayName: string;
  /** Escolha do work item (folha semanal); na guia do work item ele já vem do formulário aberto. */
  workItemField?: ReactNode;
  initialDate?: string;
  /** Painel lateral: mostra fechar/Cancelar. */
  onCancel?: () => void;
  /** Quem abriu cuida do aviso e de fechar; sem isso, o formulário limpa e mostra o aviso. */
  onSaved?: (saved: { localDate: string; minutes: number }) => void;
  /** De/Até obrigatórios (controle de horas adicionais ligado na organização). */
  requireTime?: boolean;
  /** O projeto cobra o cliente por hora (Configuração → Projetos): mostra "Horas faturáveis". */
  billableEnabled?: boolean;
  /** Duração mínima definida pelo administrador nas regras (1 = sem mínimo). */
  minDurationMinutes?: number;
}

type Feedback = { kind: "ok" | "error"; text: string } | null;

const QUICK_ADDS = [
  { label: "+0,5h", minutes: 30 },
  { label: "+1h", minutes: 60 },
  { label: "+2h", minutes: 120 },
  { label: "+4h", minutes: 240 },
];

const END_OF_DAY = 23 * 60 + 59;
const MINUTES_PER_DAY = 24 * 60;

/**
 * Lançamento manual no formato do "Add time" do 7pace: data, duração
 * (HH:MM + atalhos), intervalo De/Até, atividade, comentário e faturável.
 * De/Até são opcionais: só quando a pessoa os preenche o início é gravado
 * (e aparece nos relatórios); sem isso, vale data + duração. Com o controle
 * de horas adicionais ligado (`requireTime`), De/Até passam a ser obrigatórios:
 * é o horário que separa o expediente das horas adicionais.
 *
 * Serve à guia do work item (item fixo) e ao painel da folha semanal
 * (data pronta, item escolhido pela busca em `workItemField`).
 */
export function ManualEntryForm({
  client,
  project,
  workItem,
  activityTypes,
  displayName,
  workItemField,
  initialDate,
  onCancel,
  onSaved,
  requireTime = false,
  billableEnabled = false,
  minDurationMinutes = 1,
}: ManualEntryFormProps): JSX.Element {
  const ids = useId();
  const [localDate, setLocalDate] = useState(() => initialDate ?? todayLocalIso());
  const [durationText, setDurationText] = useState("00:00");
  const [fromText, setFromText] = useState(() => nowAsTime());
  const [toText, setToText] = useState(() => nowAsTime());
  const [activityId, setActivityId] = useState<string | null>(null);
  const [note, setNote] = useState("");
  const [billable, setBillable] = useState(false);
  // O horário só é enviado se a pessoa mexeu em De/Até — os valores iniciais são só "agora".
  const [timeInformed, setTimeInformed] = useState(false);
  // Lançando à noite: o intervalo sugerido passa a terminar neste horário (ver placeRange).
  const [endAnchor, setEndAnchor] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  const [feedback, setFeedback] = useState<Feedback>(null);

  const durationMinutes = parseDuration(durationText);
  const durationInvalid = durationText.trim() !== "" && durationMinutes === null;
  const startMinutes = timeInformed || requireTime ? timeToMinutes(fromText) : null;
  const sendsStart = startMinutes !== null;
  const pastMidnight = sendsStart && durationMinutes !== null && startMinutes + durationMinutes > MINUTES_PER_DAY;
  const belowMinimum = durationMinutes !== null && durationMinutes > 0 && durationMinutes < minDurationMinutes;
  const canSave =
    project !== null &&
    workItem !== null &&
    durationMinutes !== null &&
    durationMinutes > 0 &&
    !belowMinimum &&
    !pastMidnight &&
    (!requireTime || sendsStart) &&
    !busy;

  /**
   * Ajusta De/Até à duração: o fim anda a partir do início. Se o horário
   * ainda é o "agora" sugerido e o fim passaria da meia-noite (lançando à
   * noite), o intervalo passa a terminar no "Até" e começa antes dele.
   */
  function placeRange(minutes: number) {
    const from = timeToMinutes(fromText);
    if (from === null) return;
    const anchored = !timeInformed && endAnchor !== null;
    if (!anchored && from + minutes <= END_OF_DAY) {
      setToText(minutesToTime(from + minutes));
      return;
    }
    if (timeInformed || minutes > END_OF_DAY) return;

    const anchor = endAnchor ?? timeToMinutes(toText) ?? from;
    const end = Math.max(anchor, minutes);
    setEndAnchor(anchor);
    setFromText(minutesToTime(end - minutes));
    setToText(minutesToTime(end));
  }

  function applyDuration(minutes: number) {
    setDurationText(formatDuration(minutes));
    placeRange(minutes);
  }

  function handleDurationChange(text: string) {
    setDurationText(text);
    const minutes = parseDuration(text);
    if (minutes !== null) placeRange(minutes);
  }

  function handleRangeChange(nextFrom: string, nextTo: string) {
    setFromText(nextFrom);
    setToText(nextTo);
    setTimeInformed(true);
    setEndAnchor(null);
    const from = timeToMinutes(nextFrom);
    const to = timeToMinutes(nextTo);
    if (from !== null && to !== null && to >= from) {
      setDurationText(formatDuration(to - from));
    }
  }

  function handleActivityChange(id: string | null) {
    setActivityId(id);
    const chosen = activityTypes.find((type) => type.id === id);
    if (chosen && billableEnabled) setBillable(chosen.defaultBillable);
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    if (!project || !workItem || durationMinutes === null || durationMinutes <= 0) return;

    setBusy(true);
    setFeedback(null);
    try {
      await createManualEntry(client, {
        projectId: project.id,
        projectName: project.name,
        workItemId: workItem.id,
        localDate,
        durationSeconds: durationMinutes * 60,
        ...(sendsStart ? { startTime: fromText } : {}),
        activityTypeId: activityId ? Number(activityId) : undefined,
        billable: billableEnabled && billable,
        note: note.trim() || undefined,
        title: workItem.title,
        workItemType: workItem.workItemType,
        ...(workItem.iterationPath ? { iterationPath: workItem.iterationPath } : {}),
      });
      if (onSaved) {
        onSaved({ localDate, minutes: durationMinutes });
        return;
      }
      setFeedback({ kind: "ok", text: `Lançamento de ${formatDuration(durationMinutes)} registrado.` });
      setDurationText("00:00");
      setFromText(nowAsTime());
      setToText(nowAsTime());
      setTimeInformed(false);
      setEndAnchor(null);
      setNote("");
    } catch (error) {
      setFeedback({ kind: "error", text: error instanceof Error ? error.message : String(error) });
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className={onCancel ? "add-time" : "card"} onSubmit={(event) => void handleSubmit(event)}>
      <div className="add-time__header">
        <h2>Adicionar tempo</h2>
        {onCancel && (
          <button type="button" className="btn btn--icon btn--ghost" aria-label="Fechar" onClick={onCancel}>
            ×
          </button>
        )}
      </div>

      <div className="user-chip">
        <span className="avatar" aria-hidden="true">
          {initials(displayName)}
        </span>
        <span>{displayName}</span>
      </div>

      {workItemField && <div className="field">{workItemField}</div>}

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
        {belowMinimum && (
          <p className="field__error">A duração mínima de um lançamento é de {minDurationMinutes} minutos.</p>
        )}
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

      <p className="muted">
        {requireTime
          ? "Obrigatório: informe De/Até. É o horário que separa o expediente das horas adicionais."
          : "Opcional: preencha De/Até para registrar o horário (ele aparece nos relatórios)."}
        {timeInformed && !requireTime && (
          <>
            {" "}
            <button
              type="button"
              className="btn btn--small"
              onClick={() => {
                setTimeInformed(false);
                setEndAnchor(null);
                setFromText(nowAsTime());
                setToText(nowAsTime());
              }}
            >
              Limpar horário
            </button>
          </>
        )}
      </p>
      {pastMidnight && (
        <p className="field__error" role="alert">
          O horário passa da meia-noite. Registre o que ficou para o dia seguinte em outro lançamento.
        </p>
      )}

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

      {billableEnabled && <Switch label="Horas faturáveis" checked={billable} onChange={setBillable} />}

      <div className="actions">
        {onCancel && (
          <button type="button" className="btn" onClick={onCancel}>
            Cancelar
          </button>
        )}
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
