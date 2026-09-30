import { useState } from "react";
import type { ApiClient } from "../../lib/api/client";
import { deleteEntry, updateEntry, type WeekEntryDto } from "../../lib/api/timesheet";
import { formatDuration, formatHours, parseDuration } from "../../lib/time/format";
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
  const [durationText, setDurationText] = useState("");
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (entries.length === 0) {
    return <p className="muted">Nenhum lançamento nesta semana.</p>;
  }

  const minutes = parseDuration(durationText);

  function startEditing(entry: WeekEntryDto) {
    setEditingId(entry.id);
    setDurationText(formatDuration(Math.round(entry.durationSeconds / 60)));
    setNote(entry.note ?? "");
    setError(null);
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
      await updateEntry(client, entry.id, entry.revision, { durationSeconds: minutes * 60, note });
    });

  const remove = (entry: WeekEntryDto) => run(() => deleteEntry(client, entry.id));

  return (
    <>
      <div className="table-scroll">
        <table className="entry-table">
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
                    #{entry.workItemId} {entry.workItemTitle ?? ""}
                    <span className="muted block">{entry.projectName}</span>
                  </td>
                  <td>
                    <span className="activity">
                      <span className="swatch" style={{ background: entry.activityTypeColor ?? "transparent" }} />
                      {entry.activityTypeName ?? "Não definido"}
                    </span>
                    {entry.billable && <span className="muted block">Faturável</span>}
                  </td>
                  <td>
                    {editing ? (
                      <input
                        className={minutes === null ? "input input--compact input--invalid" : "input input--compact"}
                        aria-label="Duração (HH:MM)"
                        value={durationText}
                        onChange={(event) => setDurationText(event.target.value)}
                      />
                    ) : (
                      formatHours(entry.durationSeconds)
                    )}
                  </td>
                  <td>{entry.source === "timer" ? "Timer" : "Manual"}</td>
                  <td>
                    {editing ? (
                      <input
                        className="input input--compact"
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
                    <td className="row-actions">
                      {editing ? (
                        <>
                          <button type="button" className="btn btn--primary btn--small" disabled={busy} onClick={() => void save(entry)}>
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
