import { Switch } from "../../components/Switch";
import { setProjectCountsAsIdle, setProjectEnabled, setProjectUsesBillable } from "../../lib/api/settings";
import type { SectionProps } from "./sections";

export function ProjectsSection({ settings, client, busy, run }: SectionProps): JSX.Element {
  return (
    <section className="card">
      <h2>Projetos</h2>
      <p className="muted">
        Os projetos aparecem aqui quando alguém abre um work item deles. Desabilitado, o projeto não aceita novos
        lançamentos nem timers; o que já foi lançado continua nos relatórios. “Usa faturável”: só para projetos que
        cobram o cliente por hora; desligado, o lançamento não pergunta se a hora é faturável. “Conta como hora ociosa”: no relatório
        Horas por projeto, as horas do projeto (ex.: estudo interno) não contam como produtivas e entram em Horas
        Ociosas.
      </p>

      {settings.projects.length === 0 ? (
        <p className="muted">Nenhum projeto ainda.</p>
      ) : (
        <ul className="settings-list">
          {settings.projects.map((project) => (
            <li key={project.id}>
              <span className="settings-list__grow">{project.name}</span>
              <Switch
                label={`${project.name} usa faturável`}
                text="Usa faturável"
                checked={project.usesBillable ?? false}
                disabled={busy}
                onChange={(usesBillable) =>
                  void run(
                    () => setProjectUsesBillable(client, project.id, usesBillable),
                    usesBillable
                      ? `${project.name} passa a marcar horas faturáveis.`
                      : `${project.name} não marca mais horas faturáveis.`,
                  )
                }
              />
              <Switch
                label={`${project.name} conta como hora ociosa`}
                text="Conta como hora ociosa"
                checked={project.countsAsIdle ?? false}
                disabled={busy}
                onChange={(countsAsIdle) =>
                  void run(
                    () => setProjectCountsAsIdle(client, project.id, countsAsIdle),
                    countsAsIdle
                      ? `As horas de ${project.name} passam a contar como ociosas.`
                      : `As horas de ${project.name} voltam a contar como produtivas.`,
                  )
                }
              />
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
