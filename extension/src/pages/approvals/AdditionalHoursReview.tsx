import { coverageLabel } from "../../components/AdditionalTag";
import { DAY_TYPE_LABELS } from "../../lib/api/additionalHours";
import type { WeekEntryDto } from "../../lib/api/timesheet";
import { formatHours } from "../../lib/time/format";
import { dayMonth, weekdayShort } from "../../lib/time/weeks";

/** Lançamento → motivo; presente = não autorizado. */
export type Denials = Record<string, string>;

interface AdditionalHoursReviewProps {
  entries: WeekEntryDto[];
  denials: Denials;
  onChange: (denials: Denials) => void;
  disabled: boolean;
}

/**
 * Ao aprovar: as horas adicionais da semana, para o aprovador marcar as que
 * não autorizou (com motivo). Elas continuam registradas; o administrador
 * vê o aviso e decide o destino.
 */
export function AdditionalHoursReview({ entries, denials, onChange, disabled }: AdditionalHoursReviewProps): JSX.Element | null {
  const additional = entries.filter((entry) => entry.additional);
  if (additional.length === 0) return null;

  function toggle(entryId: string, checked: boolean) {
    const next = { ...denials };
    if (checked) next[entryId] = "";
    else delete next[entryId];
    onChange(next);
  }

  return (
    <fieldset className="additional-review">
      <legend>Horas adicionais desta semana</legend>
      <p className="muted">
        Aprovar a semana valida estas horas. Desmarque o que você não autorizou: a hora continua registrada, e o
        administrador decide o que fazer.
      </p>
      <ul>
        {additional.map((entry) => {
          const denied = entry.id in denials;
          return (
            <li key={entry.id}>
              <label className="checkbox">
                <input
                  type="checkbox"
                  checked={!denied}
                  disabled={disabled}
                  onChange={(event) => toggle(entry.id, !event.target.checked)}
                />
                <span>
                  <strong>
                    {weekdayShort(entry.localDate)} {dayMonth(entry.localDate)}
                  </strong>{" "}
                  {entry.startTime && entry.endTime ? `${entry.startTime}–${entry.endTime} · ` : ""}#{entry.workItemId}{" "}
                  {entry.workItemTitle ?? ""} · {DAY_TYPE_LABELS[entry.additional!.dayType]} ·{" "}
                  {formatHours(entry.additional!.seconds)} (→ {formatHours(entry.additional!.weightedSeconds)})
                  {entry.additional!.coverage && (
                    <span
                      className={
                        entry.additional!.coverage.uncoveredSeconds > 0 ? "additional-review__coverage is-uncovered" : "additional-review__coverage"
                      }
                    >
                      {coverageLabel(entry.additional!.coverage)}
                    </span>
                  )}
                </span>
              </label>
              {denied && (
                <input
                  className="input"
                  aria-label={`Motivo para não autorizar #${entry.workItemId} em ${dayMonth(entry.localDate)}`}
                  placeholder="Motivo (obrigatório)"
                  maxLength={500}
                  value={denials[entry.id]}
                  disabled={disabled}
                  onChange={(event) => onChange({ ...denials, [entry.id]: event.target.value })}
                />
              )}
            </li>
          );
        })}
      </ul>
    </fieldset>
  );
}
