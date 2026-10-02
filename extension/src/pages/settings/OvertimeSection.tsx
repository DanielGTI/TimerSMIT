import { useId, useState, type FormEvent } from "react";
import { Switch } from "../../components/Switch";
import {
  addHoliday,
  addNationalHolidays,
  removeHoliday,
  setHoursRegime,
  updateOvertimeRules,
  type HoursRegime,
  type OvertimeRulesInput,
} from "../../lib/api/settings";
import type { SectionProps } from "./sections";

export const REGIME_LABELS: Record<HoursRegime, string> = {
  clt: "CLT",
  pj: "PJ (só a pagar)",
  none: "Não controla jornada",
};

const FACTORS: Array<{ key: keyof OvertimeRulesInput; label: string; hint: string }> = [
  { key: "factorWeekday", label: "Dia útil, fora do expediente", hint: "CLT: mínimo +50% (1,5)" },
  { key: "factorSaturday", label: "Sábado", hint: "Em geral 1,5; algumas convenções usam 2" },
  { key: "factorSunday", label: "Domingo", hint: "CLT: em dobro (2) sem folga compensatória" },
  { key: "factorHoliday", label: "Feriado", hint: "CLT: em dobro (2) sem folga compensatória" },
];

const formatDate = (iso: string): string => {
  const [year, month, day] = iso.split("-");
  return `${day}/${month}/${year}`;
};

/**
 * Regras de hora adicional (versionadas), calendário de feriados e o regime
 * de cada pessoa (CLT, PJ ou sem controle de jornada).
 */
export function OvertimeSection({ settings, client, busy, run }: SectionProps): JSX.Element {
  const ids = useId();
  const current = settings.overtime;

  const [rules, setRules] = useState<OvertimeRulesInput>(() => ({
    enabled: current.enabled,
    workdayStart: current.workdayStart,
    workdayEnd: current.workdayEnd,
    factorWeekday: current.factorWeekday,
    factorSaturday: current.factorSaturday,
    factorSunday: current.factorSunday,
    factorHoliday: current.factorHoliday,
    nightStart: current.nightStart,
    nightEnd: current.nightEnd,
    nightPercent: current.nightPercent,
    nightReducedHour: current.nightReducedHour,
    requireTimeOfDay: current.requireTimeOfDay,
  }));
  const [holidayDate, setHolidayDate] = useState("");
  const [holidayName, setHolidayName] = useState("");
  const [year, setYear] = useState(() => new Date().getFullYear());

  const set = <K extends keyof OvertimeRulesInput>(key: K, value: OvertimeRulesInput[K]) =>
    setRules((previous) => ({ ...previous, [key]: value }));

  function saveRules(event: FormEvent) {
    event.preventDefault();
    void run(
      () => updateOvertimeRules(client, rules),
      "Regras de horas adicionais salvas. Valem para os lançamentos feitos daqui para a frente.",
    );
  }

  async function saveHoliday(event: FormEvent) {
    event.preventDefault();
    const ok = await run(() => addHoliday(client, holidayDate, holidayName.trim()), "Feriado adicionado.");
    if (ok) {
      setHolidayDate("");
      setHolidayName("");
    }
  }

  return (
    <>
      <form className="card" onSubmit={saveRules} aria-label="Regras de horas adicionais">
        <h2>Horas adicionais</h2>
        <p className="muted">
          Fora do expediente em dia útil, e o dia todo em sábado, domingo e feriado, as horas viram{" "}
          <strong>horas adicionais</strong>, multiplicadas pelo fator do dia. Depois que a semana é aprovada, o
          administrador decide se vão para hora extra, banco de horas ou a pagar.{" "}
          {current.version === 0 ? "Ainda não configurado." : `Versão ${current.version} em vigor.`} Cada alteração vale
          só para lançamentos feitos daqui para a frente.
        </p>

        <div className="field">
          <Switch label="Controlar horas adicionais" checked={rules.enabled} onChange={(value) => set("enabled", value)} />
        </div>

        <div className="filters__grid">
          <div className="field">
            <label htmlFor={`${ids}-ws`}>Início do expediente</label>
            <input id={`${ids}-ws`} className="input" type="time" value={rules.workdayStart} onChange={(e) => set("workdayStart", e.target.value)} required />
          </div>
          <div className="field">
            <label htmlFor={`${ids}-we`}>Fim do expediente</label>
            <input id={`${ids}-we`} className="input" type="time" value={rules.workdayEnd} onChange={(e) => set("workdayEnd", e.target.value)} required />
          </div>
        </div>

        <h3 className="settings-subtitle">Fatores multiplicadores</h3>
        <p className="muted">Ex.: 10 horas num sábado com fator 1,5 contam como 15 horas.</p>
        <div className="filters__grid">
          {FACTORS.map((factor) => (
            <div className="field" key={factor.key}>
              <label htmlFor={`${ids}-${factor.key}`}>{factor.label}</label>
              <input
                id={`${ids}-${factor.key}`}
                className="input"
                type="number"
                min={1}
                max={5}
                step={0.05}
                value={rules[factor.key] as number}
                onChange={(e) => set(factor.key, Number(e.target.value))}
                required
              />
              <span className="muted settings-hint">{factor.hint}</span>
            </div>
          ))}
        </div>

        <h3 className="settings-subtitle">Adicional noturno</h3>
        <div className="filters__grid">
          <div className="field">
            <label htmlFor={`${ids}-ns`}>Início do período noturno</label>
            <input id={`${ids}-ns`} className="input" type="time" value={rules.nightStart} onChange={(e) => set("nightStart", e.target.value)} required />
          </div>
          <div className="field">
            <label htmlFor={`${ids}-ne`}>Fim do período noturno</label>
            <input id={`${ids}-ne`} className="input" type="time" value={rules.nightEnd} onChange={(e) => set("nightEnd", e.target.value)} required />
          </div>
          <div className="field">
            <label htmlFor={`${ids}-np`}>Adicional noturno (%)</label>
            <input
              id={`${ids}-np`}
              className="input"
              type="number"
              min={0}
              max={100}
              value={rules.nightPercent}
              onChange={(e) => set("nightPercent", Number(e.target.value))}
              required
            />
            <span className="muted settings-hint">CLT: mínimo 20%, das 22h às 5h</span>
          </div>
        </div>
        <div className="field">
          <Switch
            label="Hora noturna reduzida (52min30s): 7 horas de relógio contam como 8"
            checked={rules.nightReducedHour}
            onChange={(value) => set("nightReducedHour", value)}
          />
        </div>
        <div className="field">
          <Switch
            label="Exigir horário (De/Até) nos lançamentos manuais"
            checked={rules.requireTimeOfDay}
            onChange={(value) => set("requireTimeOfDay", value)}
          />
        </div>

        <button type="submit" className="btn btn--primary" disabled={busy}>
          Salvar regras
        </button>
      </form>

      <section className="card" aria-label="Feriados">
        <h2>Feriados</h2>
        <p className="muted">
          Trabalho em feriado conta o dia todo como hora adicional. Use o botão para trazer os nacionais e acrescente os
          estaduais e municipais.
        </p>

        <div className="actions actions--start">
          <label htmlFor={`${ids}-year`} className="sr-only">
            Ano
          </label>
          <input
            id={`${ids}-year`}
            className="input input--short"
            type="number"
            min={2000}
            max={2100}
            value={year}
            onChange={(e) => setYear(Number(e.target.value))}
          />
          <button
            type="button"
            className="btn"
            disabled={busy}
            onClick={() => void run(() => addNationalHolidays(client, year), `Feriados nacionais de ${year} adicionados.`)}
          >
            Adicionar feriados nacionais de {year}
          </button>
        </div>

        {settings.holidays.length === 0 ? (
          <p className="muted">Nenhum feriado cadastrado.</p>
        ) : (
          <ul className="settings-list">
            {settings.holidays.map((holiday) => (
              <li key={holiday.id}>
                <span>
                  <strong>{formatDate(holiday.date)}</strong> · {holiday.name}
                </span>
                <button
                  type="button"
                  className="btn btn--small"
                  disabled={busy}
                  aria-label={`Remover feriado ${holiday.name} (${formatDate(holiday.date)})`}
                  onClick={() => void run(() => removeHoliday(client, holiday.id), `Feriado ${holiday.name} removido.`)}
                >
                  Remover
                </button>
              </li>
            ))}
          </ul>
        )}

        <form className="filters__grid" onSubmit={(event) => void saveHoliday(event)} aria-label="Novo feriado">
          <div className="field">
            <label htmlFor={`${ids}-hd`}>Data</label>
            <input id={`${ids}-hd`} className="input" type="date" value={holidayDate} onChange={(e) => setHolidayDate(e.target.value)} required />
          </div>
          <div className="field">
            <label htmlFor={`${ids}-hn`}>Nome</label>
            <input id={`${ids}-hn`} className="input" maxLength={100} value={holidayName} onChange={(e) => setHolidayName(e.target.value)} required />
          </div>
          <div className="field field--end">
            <button type="submit" className="btn btn--primary" disabled={busy || holidayDate === "" || holidayName.trim() === ""}>
              Adicionar feriado
            </button>
          </div>
        </form>
      </section>

      <section className="card" aria-label="Regime de cada pessoa">
        <h2>Regime de cada pessoa</h2>
        <p className="muted">
          CLT: as horas adicionais podem virar hora extra, banco de horas ou a pagar. PJ: só a pagar. Quem não controla
          jornada (ex.: cargo de confiança) não gera horas adicionais.
        </p>
        <ul className="settings-list">
          {settings.members.map((member) => (
            <li key={member.id}>
              <label htmlFor={`${ids}-regime-${member.id}`}>{member.name}</label>
              <select
                id={`${ids}-regime-${member.id}`}
                className="input input--medium"
                value={member.hoursRegime}
                disabled={busy}
                onChange={(e) =>
                  void run(
                    () => setHoursRegime(client, member.id, e.target.value as HoursRegime),
                    `${member.name}: ${REGIME_LABELS[e.target.value as HoursRegime]}.`,
                  )
                }
              >
                {(Object.keys(REGIME_LABELS) as HoursRegime[]).map((regime) => (
                  <option key={regime} value={regime}>
                    {REGIME_LABELS[regime]}
                  </option>
                ))}
              </select>
            </li>
          ))}
        </ul>
      </section>
    </>
  );
}
