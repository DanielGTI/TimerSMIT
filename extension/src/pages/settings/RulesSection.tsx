import { useId, useState, type FormEvent } from "react";
import { Switch } from "../../components/Switch";
import { updatePolicy, updateTimezone } from "../../lib/api/settings";
import { COMMON_TIMEZONES, type SectionProps } from "./sections";

const INCREMENTS = [1, 5, 10, 15, 30, 60];

/** Fuso e regras de lançamento. Cada salvamento de regras vira uma nova versão, válida daqui para a frente. */
export function RulesSection({ settings, client, busy, run }: SectionProps): JSX.Element {
  const ids = useId();
  const { policy, organization } = settings;

  const [timezone, setTimezone] = useState(organization.timezone);
  const [increment, setIncrement] = useState(policy.durationIncrementMinutes);
  const [minDuration, setMinDuration] = useState(policy.minDurationMinutes ?? 1);
  const [dailyLimit, setDailyLimit] = useState(policy.dailyLimitHours);
  const [retroactive, setRetroactive] = useState(policy.retroactiveWindowDays);
  const [commentRequired, setCommentRequired] = useState(policy.commentRequired);

  const timezones = COMMON_TIMEZONES.includes(organization.timezone)
    ? COMMON_TIMEZONES
    : [organization.timezone, ...COMMON_TIMEZONES];

  function saveTimezone(event: FormEvent) {
    event.preventDefault();
    void run(() => updateTimezone(client, timezone), "Fuso salvo. Vale para os próximos lançamentos.");
  }

  function savePolicy(event: FormEvent) {
    event.preventDefault();
    void run(
      () =>
        updatePolicy(client, {
          durationIncrementMinutes: increment,
          minDurationMinutes: minDuration,
          dailyLimitHours: dailyLimit,
          retroactiveWindowDays: retroactive,
          commentRequired,
        }),
      "Regras salvas. Valem para os próximos lançamentos; o histórico não muda.",
    );
  }

  return (
    <>
      <form className="card" onSubmit={saveTimezone} aria-label="Fuso da organização">
        <h2>Fuso da organização</h2>
        <p className="muted">
          Define a data local dos novos lançamentos. Lançamentos antigos mantêm a data e o fuso de quando foram feitos.
        </p>
        <div className="field">
          <label htmlFor={`${ids}-tz`}>Fuso</label>
          <select id={`${ids}-tz`} className="input input--medium" value={timezone} onChange={(e) => setTimezone(e.target.value)}>
            {timezones.map((zone) => (
              <option key={zone} value={zone}>
                {zone}
              </option>
            ))}
          </select>
        </div>
        <button type="submit" className="btn btn--primary" disabled={busy || timezone === organization.timezone}>
          Salvar fuso
        </button>
      </form>

      <form className="card" onSubmit={savePolicy} aria-label="Regras de lançamento">
        <h2>Regras de lançamento</h2>
        <p className="muted">
          {policy.version === 0
            ? "Usando os padrões. "
            : `Versão ${policy.version} em vigor. `}
          Cada alteração cria uma nova versão e vale só para lançamentos feitos daqui para a frente.
        </p>

        <div className="filters__grid">
          <div className="field">
            <label htmlFor={`${ids}-inc`}>Incremento de duração</label>
            <select id={`${ids}-inc`} className="input" value={increment} onChange={(e) => setIncrement(Number(e.target.value))}>
              {INCREMENTS.map((minutes) => (
                <option key={minutes} value={minutes}>
                  {minutes} {minutes === 1 ? "minuto" : "minutos"}
                </option>
              ))}
            </select>
          </div>
          <div className="field">
            <label htmlFor={`${ids}-min`}>Duração mínima (minutos)</label>
            <input
              id={`${ids}-min`}
              className="input"
              type="number"
              min={1}
              max={480}
              value={minDuration}
              aria-describedby={`${ids}-min-hint`}
              onChange={(e) => setMinDuration(Number(e.target.value))}
            />
            <span id={`${ids}-min-hint`} className="muted">
              1 = sem mínimo. Vale para lançamentos manuais; o timer grava o tempo exato.
            </span>
          </div>
          <div className="field">
            <label htmlFor={`${ids}-daily`}>Limite diário (horas)</label>
            <input
              id={`${ids}-daily`}
              className="input"
              type="number"
              min={1}
              max={24}
              value={dailyLimit}
              onChange={(e) => setDailyLimit(Number(e.target.value))}
            />
          </div>
          <div className="field">
            <label htmlFor={`${ids}-retro`}>Janela retroativa (dias)</label>
            <input
              id={`${ids}-retro`}
              className="input"
              type="number"
              min={0}
              max={365}
              value={retroactive}
              onChange={(e) => setRetroactive(Number(e.target.value))}
            />
          </div>
        </div>

        <div className="field">
          <Switch label="Comentário obrigatório nos lançamentos manuais" checked={commentRequired} onChange={setCommentRequired} />
        </div>

        <button
          type="submit"
          className="btn btn--primary"
          disabled={busy || !Number.isInteger(minDuration) || minDuration < 1 || minDuration > 480}
        >
          Salvar regras
        </button>
      </form>
    </>
  );
}
