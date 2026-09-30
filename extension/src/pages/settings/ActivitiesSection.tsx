import { useState, type FormEvent } from "react";
import { Switch } from "../../components/Switch";
import { createActivityType, updateActivityType, type SettingsActivityDto } from "../../lib/api/settings";
import type { SectionProps } from "./sections";

function ActivityRow({ activity, client, busy, run }: SectionProps & { activity: SettingsActivityDto }): JSX.Element {
  const [name, setName] = useState(activity.name);
  const [color, setColor] = useState(activity.color ?? "#cccccc");

  const dirty = name.trim() !== activity.name || (activity.color ?? "#cccccc").toLowerCase() !== color.toLowerCase();

  return (
    <li className="activity-row">
      <input
        type="color"
        aria-label={`Cor de ${activity.name}`}
        className="color-input"
        value={color}
        onChange={(event) => setColor(event.target.value)}
      />
      <input
        className="input"
        aria-label={`Nome de ${activity.name}`}
        maxLength={100}
        value={name}
        onChange={(event) => setName(event.target.value)}
      />
      <button
        type="button"
        className="btn btn--small"
        disabled={busy || !dirty || name.trim() === ""}
        onClick={() =>
          void run(() => updateActivityType(client, activity.id, { name: name.trim(), color }), `Atividade ${name.trim()} salva.`)
        }
      >
        Salvar
      </button>
      <Switch
        label={`${activity.name}: faturável por padrão`}
        text="Faturável"
        checked={activity.defaultBillable}
        disabled={busy}
        onChange={(defaultBillable) =>
          void run(() => updateActivityType(client, activity.id, { defaultBillable }), `Atividade ${activity.name} atualizada.`)
        }
      />
      <Switch
        label={`${activity.name} habilitada`}
        text="Habilitada"
        checked={activity.enabled}
        disabled={busy}
        onChange={(enabled) =>
          void run(
            () => updateActivityType(client, activity.id, { enabled }),
            enabled ? `Atividade ${activity.name} habilitada.` : `Atividade ${activity.name} desabilitada.`,
          )
        }
      />
    </li>
  );
}

export function ActivitiesSection(props: SectionProps): JSX.Element {
  const { settings, client, busy, run } = props;
  const [name, setName] = useState("");
  const [color, setColor] = useState("#6fa58a");
  const [billable, setBillable] = useState(false);

  function create(event: FormEvent) {
    event.preventDefault();
    void run(() => createActivityType(client, { name: name.trim(), color, defaultBillable: billable }), `Atividade ${name.trim()} criada.`).then(
      (ok) => ok && setName(""),
    );
  }

  return (
    <>
      <section className="card">
        <h2>Atividades</h2>
        <p className="muted">
          Base dos relatórios por atividade. Desabilitar esconde a atividade dos próximos lançamentos; os antigos a
          mantêm. Não há exclusão.
        </p>
        <ul className="settings-list settings-list--activities">
          {settings.activityTypes.map((activity) => (
            <ActivityRow key={`${activity.id}-${activity.name}-${activity.color}`} {...props} activity={activity} />
          ))}
        </ul>
      </section>

      <form className="card" onSubmit={create} aria-label="Nova atividade">
        <h2>Nova atividade</h2>
        <div className="activity-row">
          <input type="color" aria-label="Cor da nova atividade" className="color-input" value={color} onChange={(e) => setColor(e.target.value)} />
          <input
            className="input"
            aria-label="Nome da nova atividade"
            placeholder="Nome"
            maxLength={100}
            value={name}
            onChange={(e) => setName(e.target.value)}
          />
          <Switch label="Faturável por padrão" checked={billable} onChange={setBillable} />
          <button type="submit" className="btn btn--primary" disabled={busy || name.trim() === ""}>
            Criar
          </button>
        </div>
      </form>
    </>
  );
}
