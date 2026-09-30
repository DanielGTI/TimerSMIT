import { useId, useState, type FormEvent } from "react";
import { grantRole, revokeRole, type Role } from "../../lib/api/settings";
import { ORGANIZATION_SCOPE, ROLE_LABELS, type SectionProps } from "./sections";

const ROLES: Role[] = ["member", "approver", "manager", "admin"];

/**
 * Papéis por pessoa. Sem nenhum papel a pessoa não acessa nada (negação por
 * padrão): é aqui que o administrador libera quem acabou de abrir a extensão.
 */
export function PeopleSection({ settings, client, busy, run }: SectionProps): JSX.Element {
  const ids = useId();
  const [memberId, setMemberId] = useState("");
  const [role, setRole] = useState<Role>("member");
  const [projectId, setProjectId] = useState("");

  function grant(event: FormEvent) {
    event.preventDefault();
    const member = settings.members.find((candidate) => candidate.id === memberId);
    void run(() => grantRole(client, memberId, role, projectId || null), `${ROLE_LABELS[role]} concedido a ${member?.name ?? "pessoa"}.`);
  }

  return (
    <>
      <section className="card">
        <h2>Pessoas e papéis</h2>
        <p className="muted">
          Quem abre a extensão pela primeira vez aparece aqui sem acesso. Membro permite lançar horas; gestor vê as
          horas de todos nos projetos que gerencia; administrador configura a organização.
        </p>

        <ul className="settings-list settings-list--people">
          {settings.members.map((member) => (
            <li key={member.id}>
              <strong>{member.name}</strong>
              <span className="chips">
                {member.roles.length === 0 && <span className="badge badge--rejected">Sem acesso</span>}
                {member.roles.map((assignment) => (
                  <span key={assignment.id} className="chip">
                    {ROLE_LABELS[assignment.role]} · {assignment.projectName ?? ORGANIZATION_SCOPE}
                    <button
                      type="button"
                      className="chip__remove"
                      aria-label={`Remover ${ROLE_LABELS[assignment.role]} de ${member.name} (${assignment.projectName ?? ORGANIZATION_SCOPE})`}
                      disabled={busy}
                      onClick={() => void run(() => revokeRole(client, assignment.id), `Papel removido de ${member.name}.`)}
                    >
                      ×
                    </button>
                  </span>
                ))}
              </span>
            </li>
          ))}
        </ul>
      </section>

      <form className="card" onSubmit={grant} aria-label="Conceder papel">
        <h2>Conceder papel</h2>
        <div className="filters__grid">
          <div className="field">
            <label htmlFor={`${ids}-member`}>Pessoa</label>
            <select id={`${ids}-member`} className="input" value={memberId} onChange={(e) => setMemberId(e.target.value)}>
              <option value="">Escolha…</option>
              {settings.members.map((member) => (
                <option key={member.id} value={member.id}>
                  {member.name}
                </option>
              ))}
            </select>
          </div>
          <div className="field">
            <label htmlFor={`${ids}-role`}>Papel</label>
            <select id={`${ids}-role`} className="input" value={role} onChange={(e) => setRole(e.target.value as Role)}>
              {ROLES.map((value) => (
                <option key={value} value={value}>
                  {ROLE_LABELS[value]}
                </option>
              ))}
            </select>
          </div>
          <div className="field">
            <label htmlFor={`${ids}-project`}>Onde vale</label>
            <select id={`${ids}-project`} className="input" value={projectId} onChange={(e) => setProjectId(e.target.value)}>
              <option value="">{ORGANIZATION_SCOPE}</option>
              {settings.projects.map((project) => (
                <option key={project.id} value={project.id}>
                  {project.name}
                </option>
              ))}
            </select>
          </div>
        </div>
        <button type="submit" className="btn btn--primary" disabled={busy || memberId === ""}>
          Conceder
        </button>
      </form>
    </>
  );
}
