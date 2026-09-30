import type { DecisionDto } from "../lib/api/timesheet";

const LABELS: Record<DecisionDto["decision"], string> = {
  approved: "Aprovada",
  rejected: "Rejeitada",
  reopened: "Reaberta",
};

/** Histórico de decisões da semana: nada é apagado quando ela é reenviada. */
export function DecisionHistory({ decisions }: { decisions: DecisionDto[] }): JSX.Element | null {
  if (decisions.length === 0) return null;

  return (
    <ol className="history" aria-label="Histórico de decisões">
      {decisions.map((decision, index) => (
        <li key={`${decision.revision}-${decision.decidedAt}-${index}`}>
          <strong>{LABELS[decision.decision]}</strong> na revisão {decision.revision} por{" "}
          {decision.approverName ?? "—"}
          {decision.selfDecision && " (a própria semana)"} em {new Date(decision.decidedAt).toLocaleString("pt-BR")}
          {decision.reason && <span className="history__reason">“{decision.reason}”</span>}
        </li>
      ))}
    </ol>
  );
}
