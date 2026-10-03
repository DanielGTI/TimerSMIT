import { useEffect, useId, useState } from "react";
import {
  downloadClosingCsv,
  fetchClosing,
  type ClosingDto,
  type ClosingLineDto,
  type ClosingMemberDto,
} from "../../lib/api/additionalHours";
import { saveBlob } from "../../lib/download";
import { formatHours, todayLocalIso } from "../../lib/time/format";
import { monthLabel, monthOf } from "../../lib/time/weeks";
import { REGIME_LABELS } from "./OvertimeSection";
import type { SectionProps } from "./sections";

const errorText = (failure: unknown): string => (failure instanceof Error ? failure.message : String(failure));

const factorText = (factor: number | null): string => (factor === null ? "" : `${factor.toLocaleString("pt-BR")}×`);

/** Horas de relógio por fator e o total ponderado de uma categoria. */
function Hours({ member, category }: { member: ClosingMemberDto; category: "overtime" | "payable" }): JSX.Element {
  const total = member.totals[category];
  if (total.seconds === 0) return <>—</>;

  const lines = member.lines.filter((line: ClosingLineDto) => line.category === category);
  return (
    <>
      <strong>{formatHours(total.seconds)}</strong>
      {lines.map((line) => (
        <span key={line.factor ?? "-"} className="muted block nowrap">
          {factorText(line.factor)} {formatHours(line.seconds)}
        </span>
      ))}
      <span className="muted block">{formatHours(total.weightedSeconds)} ponderadas</span>
      {total.nightSeconds > 0 && <span className="muted block">{formatHours(total.nightSeconds)} noturnas</span>}
    </>
  );
}

function Alerts({ alerts }: { alerts: ClosingMemberDto["alerts"] }): JSX.Element {
  const items = [
    alerts.daily_extra > 0 && `${alerts.daily_extra} dia(s) acima do limite de horas extras`,
    alerts.weekly_hours > 0 && `${alerts.weekly_hours} semana(s) acima do limite`,
    alerts.rest > 0 && `${alerts.rest} descanso(s) curto(s) entre jornadas`,
  ].filter(Boolean);

  if (items.length === 0) return <>—</>;
  return (
    <>
      {items.map((item) => (
        <span key={item as string} className="block">
          {item}
        </span>
      ))}
    </>
  );
}

/**
 * Fechamento do mês para o DP (administrador): por pessoa, horas extras e a
 * pagar por fator, o banco de horas do mês, o que falta decidir e os avisos
 * de jornada. O CSV leva o mesmo conteúdo, uma linha por categoria e fator.
 */
export function ClosingSection({ client }: SectionProps): JSX.Element {
  const ids = useId();
  const [month, setMonth] = useState(() => monthOf(todayLocalIso()));
  const [closing, setClosing] = useState<ClosingDto | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!/^\d{4}-\d{2}$/.test(month)) return;
    let cancelled = false;
    setClosing(null);
    setError(null);
    fetchClosing(client, month)
      .then((data) => !cancelled && setClosing(data))
      .catch((failure: unknown) => !cancelled && setError(errorText(failure)));
    return () => {
      cancelled = true;
    };
  }, [client, month]);

  async function exportCsv() {
    setBusy(true);
    setError(null);
    try {
      saveBlob(await downloadClosingCsv(client, month), `fechamento_${month}.csv`);
    } catch (failure) {
      setError(errorText(failure));
    } finally {
      setBusy(false);
    }
  }

  const open = (closing?.members ?? []).reduce(
    (sum, member) => sum + member.totals.pending.seconds + member.totals.unapproved.seconds,
    0,
  );

  return (
    <section className="card" aria-label="Fechamento do mês">
      <div className="toolbar">
        <h2>Fechamento do mês</h2>
        <div className="toolbar__group">
          <label htmlFor={`${ids}-month`} className="sr-only">
            Mês
          </label>
          <input id={`${ids}-month`} className="input" type="month" value={month} onChange={(e) => setMonth(e.target.value)} />
          <button type="button" className="btn btn--primary nowrap" disabled={busy || !closing} onClick={() => void exportCsv()}>
            Exportar CSV
          </button>
        </div>
      </div>
      <p className="muted">
        Horas pelo dia trabalhado, para o DP calcular a folha (a ferramenta entrega horas, não valores). Hora extra e a
        pagar vêm separadas por fator; “ponderadas” já aplicam o fator e o adicional noturno. Vencido no banco deve ser
        pago como hora extra.
      </p>

      {error && (
        <p className="alert" role="alert">
          {error}
        </p>
      )}

      {closing && open > 0 && (
        <p className="banner banner--warning" role="note">
          Ainda há {formatHours(open)} de horas adicionais em {monthLabel(closing.month)} sem aprovação da semana ou sem
          classificação. Feche depois de aprovar e classificar tudo.
        </p>
      )}

      {closing === null ? (
        !error && <p className="muted">Carregando…</p>
      ) : closing.members.length === 0 ? (
        <p className="muted">Nada a fechar em {monthLabel(closing.month)}.</p>
      ) : (
        <div className="table-scroll">
          <table className="entry-table">
            <caption className="sr-only">Fechamento de {monthLabel(closing.month)}</caption>
            <thead>
              <tr>
                <th scope="col">Pessoa</th>
                <th scope="col">Hora extra</th>
                <th scope="col">A pagar</th>
                <th scope="col">Banco de horas no mês</th>
                <th scope="col">Pendências</th>
                <th scope="col">Avisos de jornada</th>
              </tr>
            </thead>
            <tbody>
              {closing.members.map((member) => (
                <tr key={member.memberId}>
                  <td>
                    {member.memberName}
                    {member.regime !== "clt" && <span className="muted block">{REGIME_LABELS[member.regime]}</span>}
                  </td>
                  <td>
                    <Hours member={member} category="overtime" />
                  </td>
                  <td>
                    <Hours member={member} category="payable" />
                  </td>
                  <td>
                    {member.bank ? (
                      <>
                        <span className="block">Saldo no fim do mês: {formatHours(Math.max(0, member.bank.balanceSeconds))}</span>
                        {member.bank.creditedSeconds !== 0 && <span className="muted block">Crédito: {formatHours(member.bank.creditedSeconds)}</span>}
                        {member.bank.timeOffSeconds > 0 && <span className="muted block">Folgas: {formatHours(member.bank.timeOffSeconds)}</span>}
                        {member.bank.payoutSeconds > 0 && <span className="muted block">Pago em folha: {formatHours(member.bank.payoutSeconds)}</span>}
                        {member.bank.adjustmentSeconds !== 0 && (
                          <span className="muted block">
                            Ajustes: {member.bank.adjustmentSeconds < 0 ? "−" : "+"}
                            {formatHours(Math.abs(member.bank.adjustmentSeconds))}
                          </span>
                        )}
                        {member.bank.expiredSeconds > 0 && (
                          <strong className="block">Vencido (pagar como hora extra): {formatHours(member.bank.expiredSeconds)}</strong>
                        )}
                      </>
                    ) : (
                      "—"
                    )}
                  </td>
                  <td>
                    {member.totals.unapproved.seconds > 0 && (
                      <span className="field__error block">Semana não aprovada: {formatHours(member.totals.unapproved.seconds)}</span>
                    )}
                    {member.totals.pending.seconds > 0 && (
                      <span className="field__error block">A classificar: {formatHours(member.totals.pending.seconds)}</span>
                    )}
                    {member.deniedCount > 0 && <span className="muted block">{member.deniedCount} não autorizada(s) pelo aprovador</span>}
                    {(member.overtime?.withoutRequestSeconds ?? 0) > 0 && (
                      <span className="muted block">Sem hora extra aprovada antes: {formatHours(member.overtime!.withoutRequestSeconds)}</span>
                    )}
                    {(member.overtime?.refusedSeconds ?? 0) > 0 && (
                      <span className="muted block">Hora a confirmar recusada (não conta): {formatHours(member.overtime!.refusedSeconds)}</span>
                    )}
                    {member.totals.unapproved.seconds === 0 &&
                      member.totals.pending.seconds === 0 &&
                      member.deniedCount === 0 &&
                      !member.overtime?.withoutRequestSeconds &&
                      !member.overtime?.refusedSeconds &&
                      "—"}
                  </td>
                  <td>
                    <Alerts alerts={member.alerts} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}
