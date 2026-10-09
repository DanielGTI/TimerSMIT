import { useState } from "react";
import { ActivitySelect } from "../../components/ActivitySelect";
import { AdditionalTag } from "../../components/AdditionalTag";
import { WorkItemLink } from "../../components/WorkItemLink";
import { fetchActivityTypes, type ActivityTypeDto } from "../../lib/api/activityTypes";
import type { ApiClient } from "../../lib/api/client";
import { deleteEntry, updateEntry, type WeekEntryDto } from "../../lib/api/timesheet";
import { formatDuration, formatHours, parseDuration } from "../../lib/time/format";
import { changeRange, rangeError, type RangeField, type RangeText } from "../../lib/time/range";
import { dayMonth, weekdayShort } from "../../lib/time/weeks";

interface EntryListProps {
  client: ApiClient;
  entries: WeekEntryDto[];
  editable: boolean;
  onChanged: () => void;
}

/**
 * Lançamentos da semana, com editar/excluir (FR-004) enquanto a semana
 * aceita edição. O servidor é quem decide: uma semana enviada por outra aba
 * ainda responde 409 aqui.
 */
export function EntryList({ client, entries, editable, onChanged }: EntryListProps): JSX.Element {
  const [editingId, setEditingId] = useState<string | null>(null);
  const [range, setRange] = useState<RangeText>({ start: "", end: "", duration: "" });
  const [activityId, setActivityId] = useState<string | null>(null);
  const [activityTypes, setActivityTypes] = useState<ActivityTypeDto[] | null>(null);
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (entries.length === 0) {
    return <p className="muted">Nenhum lançamento nesta semana.</p>;
  }

  const minutes = parseDuration(range.duration);
  const timeError = rangeError(range);

  const change = (field: RangeField, value: string) => setRange((current) => changeRange(current, field, value));

  function startEditing(entry: WeekEntryDto) {
    setEditingId(entry.id);
    setRange({
      start: entry.startTime ?? "",
      end: entry.endTime ?? "",
      duration: formatDuration(Math.round(entry.durationSeconds / 60)),
    });
    setActivityId(entry.activityTypeId);
    setNote(entry.note ?? "");
    setError(null);

    // As atividades só são lidas na primeira vez que alguém edita.
    if (activityTypes === null) {
      fetchActivityTypes(client)
        .then(setActivityTypes)
        .catch((failure: unknown) => setError(failure instanceof Error ? failure.message : String(failure)));
    }
  }

  /** Atividades para escolher; a atual entra mesmo se já foi desabilitada (só não é reenviada se não mudar). */
  function activityOptions(entry: WeekEntryDto): ActivityTypeDto[] {
    const options = activityTypes ?? [];
    if (entry.activityTypeId === null || options.some((option) => option.id === entry.activityTypeId)) return options;
    return [
      { id: entry.activityTypeId, name: entry.activityTypeName ?? "Atividade desabilitada", color: entry.activityTypeColor, defaultBillable: false },
      ...options,
    ];
  }

  async function run(action: () => Promise<void>) {
    setBusy(true);
    setError(null);
    try {
      await action();
      setEditingId(null);
      onChanged();
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : String(failure));
    } finally {
      setBusy(false);
    }
  }

  const save = (entry: WeekEntryDto) =>
    run(async () => {
      if (minutes === null || minutes <= 0) throw new Error("Use a duração no formato HH:MM (ex.: 01:30).");
      if (timeError) throw new Error(timeError);
      const start = range.start === "" ? null : range.start;
      await updateEntry(client, entry.id, entry.revision, {
        durationSeconds: minutes * 60,
        note,
        // Só envia o início se mudou (null apaga o horário).
        ...(start !== entry.startTime ? { startTime: start } : {}),
        // Só envia a atividade se mudou.
        ...(activityId !== entry.activityTypeId ? { activityTypeId: activityId } : {}),
      });
    });

  const remove = (entry: WeekEntryDto) => run(() => deleteEntry(client, entry.id));

  return (
    <>
      <div className="table-scroll">
        <table className="entry-table entry-table--list">
          <caption className="sr-only">Lançamentos da semana</caption>
          <thead>
            <tr>
              <th scope="col">Dia</th>
              <th scope="col">Work item</th>
              <th scope="col">Atividade</th>
              <th scope="col">Duração</th>
              <th scope="col">Origem</th>
              <th scope="col">Comentário</th>
              {editable && <th scope="col">Ações</th>}
            </tr>
          </thead>
          <tbody>
            {entries.map((entry) => {
              const editing = editingId === entry.id;
              return (
                <tr key={entry.id}>
                  <td>
                    {weekdayShort(entry.localDate)} {dayMonth(entry.localDate)}
                  </td>
                  <td>
                    <WorkItemLink workItemId={entry.workItemId} projectName={entry.projectName}>
                      #{entry.workItemId} {entry.workItemTitle ?? ""}
                    </WorkItemLink>
                    <span className="muted block">{entry.projectName}</span>
                  </td>
                  <td>
                    {editing ? (
                      <ActivitySelect
                        options={activityOptions(entry)}
                        value={activityId}
                        onChange={setActivityId}
                        label="Atividade"
                        disabled={busy}
                      />
                    ) : (
                      <>
                        <span className="activity">
                          <span className="swatch" style={{ background: entry.activityTypeColor ?? "transparent" }} />
                          {entry.activityTypeName ?? "Não definido"}
                        </span>
                        {entry.billable && <span className="muted block">Faturável</span>}
                      </>
                    )}
                  </td>
                  <td>
                    {editing ? (
                      <div className="edit-times">
                        <div className="edit-field">
                          <span className="edit-field__label" aria-hidden="true">
                            Início
                          </span>
                          <input
                            className="input input--compact input--time"
                            type="time"
                            aria-label="Início (opcional)"
                            value={range.start}
                            onChange={(event) => change("start", event.target.value)}
                          />
                        </div>
                        <div className="edit-field">
                          <span className="edit-field__label" aria-hidden="true">
                            Fim
                          </span>
                          <input
                            className="input input--compact input--time"
                            type="time"
                            aria-label="Fim"
                            value={range.end}
                            onChange={(event) => change("end", event.target.value)}
                          />
                        </div>
                        <div className="edit-field">
                          <span className="edit-field__label" aria-hidden="true">
                            Duração
                          </span>
                          <input
                            className={
                              minutes === null ? "input input--compact input--hours input--invalid" : "input input--compact input--hours"
                            }
                            aria-label="Duração (HH:MM)"
                            placeholder="HH:MM"
                            value={range.duration}
                            onChange={(event) => change("duration", event.target.value)}
                          />
                        </div>
                        {timeError && (
                          <p className="field__error edit-times__error" role="alert">
                            {timeError}
                          </p>
                        )}
                      </div>
                    ) : (
                      <>
                        {formatHours(entry.durationSeconds)}
                        {entry.startTime && entry.endTime && (
                          <span className="muted block">
                            {entry.startTime}–{entry.endTime}
                          </span>
                        )}
                        {entry.additional && <AdditionalTag additional={entry.additional} />}
                      </>
                    )}
                  </td>
                  <td>{entry.source === "timer" ? "Timer" : "Manual"}</td>
                  <td>
                    {editing ? (
                      <input
                        className="input input--compact input--note"
                        aria-label="Comentário"
                        maxLength={2000}
                        value={note}
                        onChange={(event) => setNote(event.target.value)}
                      />
                    ) : (
                      (entry.note ?? <span className="muted">–</span>)
                    )}
                  </td>
                  {editable && (
                    <td>
                      <div className="row-actions">
                        {editing ? (
                          <>
                            <button
                              type="button"
                              className="btn btn--primary btn--small"
                              disabled={busy || timeError !== null}
                              onClick={() => void save(entry)}
                            >
                              Salvar
                            </button>
                            <button type="button" className="btn btn--small" disabled={busy} onClick={() => setEditingId(null)}>
                              Cancelar
                            </button>
                          </>
                        ) : (
                          <>
                            <button type="button" className="btn btn--small" disabled={busy} onClick={() => startEditing(entry)}>
                              Editar
                            </button>
                            <button type="button" className="btn btn--small" disabled={busy} onClick={() => void remove(entry)}>
                              Excluir
                            </button>
                          </>
                        )}
                      </div>
                    </td>
                  )}
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      {error && (
        <p className="alert" role="alert">
          {error}
        </p>
      )}
    </>
  );
}
