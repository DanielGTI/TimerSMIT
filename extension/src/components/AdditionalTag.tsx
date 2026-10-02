import { CLASSIFICATION_LABELS } from "../lib/api/additionalHours";
import type { AdditionalHoursDto } from "../lib/api/timesheet";
import { formatHours } from "../lib/time/format";

/**
 * Horas adicionais de um lançamento: quanto foi trabalhado fora do
 * expediente, quanto vale com o fator e a situação. Até o administrador
 * classificar, a situação é sempre "horas adicionais a validar".
 */
export function AdditionalTag({ additional }: { additional: AdditionalHoursDto }): JSX.Element {
  const weighted = additional.weightedSeconds !== additional.seconds;

  return (
    <span className={`additional-tag additional-tag--${additional.status}`}>
      <span>
        {CLASSIFICATION_LABELS[additional.status]}: {formatHours(additional.seconds)}
        {weighted && <> → {formatHours(additional.weightedSeconds)}</>}
      </span>
      {additional.denied && (
        <span className="additional-tag__denied">Não autorizada pelo aprovador: {additional.denied.reason}</span>
      )}
    </span>
  );
}
