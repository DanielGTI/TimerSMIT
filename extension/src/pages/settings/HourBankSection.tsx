import { useCallback, useEffect, useId, useState, type FormEvent } from "react";
import { HourBankStatement } from "../../components/HourBankStatement";
import {
  EVENT_LABELS,
  addHourBankMovement,
  fetchHourBankOverview,
  fetchHourBankStatement,
  removeHourBankMovement,
  type HourBankEventDto,
  type HourBankMemberDto,
  type HourBankMovementInput,
  type HourBankStatementDto,
} from "../../lib/api/hourBank";
import { formatHours, parseDuration, todayLocalIso } from "../../lib/time/format";
import { REGIME_LABELS } from "./OvertimeSection";
import type { SectionProps } from "./sections";

const formatDate = (iso: string): string => {
  const [year, month, day] = iso.split("-");
  return `${day}/${month}/${year}`;
};

const errorText = (failure: unknown): string => (failure instanceof Error ? failure.message : String(failure));

type MovementOption = "time_off" | "payout" | "adjustment_credit" | "adjustment_debit";

const MOVEMENTS: Array<{ value: MovementOption; label: string }> = [
  { value: "time_off", label: "Folga (compensação)" },
  { value: "payout", label: "Pagamento em folha" },
  { value: "adjustment_credit", label: "Ajuste para mais" },
  { value: "adjustment_debit", label: "Ajuste para menos" },
];

function toInput(option: MovementOption): Pick<HourBankMovementInput, "kind" | "direction"> {
  if (option === "adjustment_credit") return { kind: "adjustment", direction: "credit" };
  if (option === "adjustment_debit") return { kind: "adjustment", direction: "debit" };
  return { kind: option };
}

/**
 * Banco de horas de cada pessoa (administrador): saldos, extrato e o
 * lançamento de folgas, pagamentos e ajustes. Os créditos entram pela aba
 * "Horas adicionais" (classificação como banco).
 */
export function HourBankSection({ client }: SectionProps): JSX.Element {
  const ids = useId();
  const today = todayLocalIso();

  const [members, setMembers] = useState<HourBankMemberDto[] | null>(null);
  const [memberId, setMemberId] = useState<string | null>(null);
  const [statement, setStatement] = useState<HourBankStatementDto | null>(null);
  const [option, setOption] = useState<MovementOption>("time_off");
  const [date, setDate] = useState(today);
  const [hours, setHours] = useState("");
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const loadOverview = useCallback(async () => {
    try {
      setMembers(await fetchHourBankOverview(client));
    } catch (failure) {
      setError(errorText(failure));
    }
  }, [client]);

  useEffect(() => {
    void loadOverview();
  }, [loadOverview]);

  useEffect(() => {
    if (memberId === null) {
      setStatement(null);
      return;
    }
    let cancelled = false;
    setStatement(null);
    fetchHourBankStatement(client, memberId)
      .then((data) => !cancelled && setStatement(data))
      .catch((failure: unknown) => !cancelled && setError(errorText(failure)));
    return () => {
      cancelled = true;
    };
  }, [client, memberId]);

  async function act(action: () => Promise<HourBankStatementDto>, success: string): Promise<boolean> {
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      setStatement(await action());
      setNotice(success);
      await loadOverview();
      return true;
    } catch (failure) {
      setError(errorText(failure));
      return false;
    } finally {
      setBusy(false);
    }
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (memberId === null) return;

    // "8" vale como 8 horas; o resto no formato HH:MM.
    const minutes = /^\d{1,3}$/.test(hours.trim()) ? Number(hours.trim()) * 60 : parseDuration(hours);
    if (minutes === null || minutes <= 0) {
      setError("Informe as horas no formato HH:MM.");
      return;
    }

    const label = MOVEMENTS.find((movement) => movement.value === option)!.label;
    const ok = await act(
      () => addHourBankMovement(client, memberId, { ...toInput(option), seconds: minutes * 60, localDate: date, note: note.trim() }),
      `Lançado: ${label}, ${formatHours(minutes * 60)}.`,
    );
    if (ok) {
      setHours("");
      setNote("");
    }
  }

  function remove(event: HourBankEventDto) {
    void act(() => removeHourBankMovement(client, event.movementId!), `Desfeito: ${EVENT_LABELS[event.type]} de ${formatDate(event.date)}.`);
  }

  const selected = members?.find((member) => member.memberId === memberId) ?? null;

  return (
    <>
      <section className="card" aria-label="Saldos do banco de horas">
        <h2>Banco de horas</h2>
        <p className="muted">
          Créditos: horas adicionais classificadas como banco. Cada folga ou pagamento usa primeiro as horas que vencem
          antes. O que vence sem uso sai do saldo e deve ser pago como hora extra. O banco só vale com acordo escrito.
        </p>

        {notice && (
          <p className="notice" role="status">
            {notice}
          </p>
        )}
        {error && (
          <p className="alert" role="alert">
            {error}
          </p>
        )}

        {members === null ? (
          !error && <p className="muted">Carregando…</p>
        ) : members.length === 0 ? (
          <p className="muted">Ninguém com banco de horas. Só pessoas CLT têm banco.</p>
        ) : (
          <div className="table-scroll">
            <table className="entry-table">
              <caption className="sr-only">Saldo de cada pessoa</caption>
              <thead>
                <tr>
                  <th scope="col">Pessoa</th>
                  <th scope="col">Saldo</th>
                  <th scope="col">Vence em até 30 dias</th>
                  <th scope="col">Vencido (a pagar)</th>
                  <th scope="col">
                    <span className="sr-only">Ações</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {members.map((member) => (
                  <tr key={member.memberId} className={member.memberId === memberId ? "is-current" : undefined}>
                    <td>
                      {member.memberName}
                      {member.regime !== "clt" && <span className="muted block">{REGIME_LABELS[member.regime]}</span>}
                    </td>
                    <td>
                      <strong>{formatHours(Math.max(0, member.summary.balanceSeconds))}</strong>
                      {member.summary.uncoveredSeconds > 0 && (
                        <span className="field__error block">{formatHours(member.summary.uncoveredSeconds)} sem cobertura</span>
                      )}
                    </td>
                    <td>{member.summary.expiringSoonSeconds > 0 ? formatHours(member.summary.expiringSoonSeconds) : "—"}</td>
                    <td>{member.summary.expiredSeconds > 0 ? formatHours(member.summary.expiredSeconds) : "—"}</td>
                    <td>
                      <button
                        type="button"
                        className="btn btn--chip"
                        aria-label={`Abrir extrato de ${member.memberName}`}
                        onClick={() => {
                          setMemberId(member.memberId);
                          setError(null);
                          setNotice(null);
                        }}
                      >
                        Extrato
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {selected && (
        <section className="card" aria-label={`Banco de horas de ${selected.memberName}`}>
          <div className="toolbar">
            <h2>{selected.memberName}</h2>
            <button type="button" className="btn btn--chip" onClick={() => setMemberId(null)}>
              Fechar
            </button>
          </div>

          <form className="bank-form" onSubmit={(event) => void submit(event)} aria-label="Lançar no banco de horas">
            <div className="field">
              <label htmlFor={`${ids}-kind`}>Tipo</label>
              <select id={`${ids}-kind`} className="input" value={option} onChange={(e) => setOption(e.target.value as MovementOption)}>
                {MOVEMENTS.map((movement) => (
                  <option key={movement.value} value={movement.value} disabled={movement.value === "adjustment_credit" && selected.regime !== "clt"}>
                    {movement.label}
                  </option>
                ))}
              </select>
            </div>
            <div className="field">
              <label htmlFor={`${ids}-date`}>Data</label>
              <input id={`${ids}-date`} className="input" type="date" value={date} onChange={(e) => setDate(e.target.value)} required />
            </div>
            <div className="field">
              <label htmlFor={`${ids}-hours`}>Horas</label>
              <input
                id={`${ids}-hours`}
                className="input input--short"
                placeholder="08:00"
                inputMode="numeric"
                value={hours}
                onChange={(e) => setHours(e.target.value)}
                required
              />
            </div>
            <div className="field bank-form__note">
              <label htmlFor={`${ids}-note`}>Motivo</label>
              <input
                id={`${ids}-note`}
                className="input"
                maxLength={500}
                placeholder="Ex.: folga na emenda do feriado"
                value={note}
                onChange={(e) => setNote(e.target.value)}
                required
              />
            </div>
            <button type="submit" className="btn btn--primary" disabled={busy || statement === null}>
              Lançar
            </button>
          </form>
          <p className="muted settings-hint">
            Folga pode ser agendada até 90 dias à frente; pagamento e ajuste usam a data de hoje ou anterior. O saldo nunca
            fica negativo.
          </p>

          {statement ? <HourBankStatement statement={statement} onRemove={remove} busy={busy} /> : <p className="muted">Carregando…</p>}
        </section>
      )}
    </>
  );
}
