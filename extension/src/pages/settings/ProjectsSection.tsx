import { Switch } from "../../components/Switch";
import { setProjectEnabled } from "../../lib/api/settings";
import type { SectionProps } from "./sections";

export function ProjectsSection({ settings, client, busy, run }: SectionProps): JSX.Element {
  return (
    <section className="card">
      <h2>Projetos</h2>
      <p className="muted">
        Os projetos aparecem aqui quando alguém abre um work item deles. Desabilitado, o projeto não aceita novos
        lançamentos nem timers; o que já foi lançado continua nos relatórios.
      </p>

      {settings.projects.length === 0 ? (
        <p className="muted">Nenhum projeto ainda.</p>
      ) : (
        <ul className="settings-list">
          {settings.projects.map((project) => (
            <li key={project.id}>
              <span>{project.name}</span>
              <Switch
                label={`${project.name} habilitado`}
                text="Habilitado"
                checked={project.enabled}
                disabled={busy}
                onChange={(enabled) =>
                  void run(
                    () => setProjectEnabled(client, project.id, enabled),
                    enabled ? `Projeto ${project.name} habilitado.` : `Projeto ${project.name} desabilitado.`,
                  )
                }
              />
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
