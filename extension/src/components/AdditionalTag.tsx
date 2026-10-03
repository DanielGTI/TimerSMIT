import { CLASSIFICATION_LABELS } from "../lib/api/additionalHours";
import type { AdditionalHoursDto, CoverageDto } from "../lib/api/timesheet";
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
      {additional.coverage && <span className="additional-tag__coverage">{coverageLabel(additional.coverage)}</span>}
      {additional.denied && (
        <span className="additional-tag__denied">Não autorizada pelo aprovador: {additional.denied.reason}</span>
      )}
    </span>
  );
}

/** Situação da hora extra em relação ao que foi informado e aprovado antes. */
export function coverageLabel(coverage: CoverageDto): string {
  const late = coverage.afterTheFact ? " (informada depois)" : "";
  switch (coverage.kind) {
    case "preapproved":
      return "Pré-aprovada";
    case "confirmed":
      return "Confirmada pelo aprovador";
    case "request":
      return `Coberta por hora extra informada e aprovada${late}`;
    case "partial":
      return `${formatHours(coverage.coveredSeconds)} coberta por hora extra aprovada${late}; ${formatHours(coverage.uncoveredSeconds)} sujeita à aprovação`;
    default:
      return coverage.requestPending
        ? "Horas extras, sujeitas à aprovação (hora extra informada aguardando decisão)"
        : "Horas extras, sujeitas à aprovação";
  }
}
