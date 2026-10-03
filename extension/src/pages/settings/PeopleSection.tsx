import { useEffect, useId, useRef, useState, type FormEvent } from "react";
import { OVERTIME_PROFILE_HINTS, OVERTIME_PROFILE_LABELS, type OvertimeProfile } from "../../lib/api/overtime";
import { grantRole, revokeRole, setOvertimeProfile, syncPeople, type Role, type SettingsMemberDto } from "../../lib/api/settings";
import { fetchActiveDirectoryPeople } from "../../lib/devops/directory";
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

  const active = settings.members.filter((member) => member.directoryActive !== false);
  const inactive = settings.members.filter((member) => member.directoryActive === false);

  const refreshFromDirectory = () =>
    run(
      async () => syncPeople(client, await fetchActiveDirectoryPeople()),
      "Lista de pessoas atualizada com o Azure DevOps.",
    );

  // Ao abrir a aba, já traz quem está ativo no Azure DevOps (uma vez por abertura).
  const autoRefreshed = useRef(false);
  useEffect(() => {
    if (autoRefreshed.current) return;
    autoRefreshed.current = true;
    void refreshFromDirectory();
    // eslint-disable-next-line react-hooks/exhaustive-deps -- só na abertura da aba
  }, []);

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
        <p className="muted">
          <strong>Hora extra</strong> (só CLT): <strong>Pré-aprovada</strong>: {OVERTIME_PROFILE_HINTS.preapproved.toLowerCase()}{" "}
          <strong>Padrão</strong>: {OVERTIME_PROFILE_HINTS.standard.toLowerCase()} <strong>Restrita</strong>:{" "}
          {OVERTIME_PROFILE_HINTS.restricted.toLowerCase()}
        </p>

        <div className="toolbar">
          <span className="muted">
            {settings.peopleSyncedAt
              ? `Lista do Azure DevOps atualizada em ${formatSyncedAt(settings.peopleSyncedAt)}.`
              : "Lista ainda não atualizada com o Azure DevOps."}
          </span>
          <button type="button" className="btn btn--small" disabled={busy} onClick={() => void refreshFromDirectory()}>
            Atualizar do Azure DevOps
          </button>
        </div>

        <ul className="settings-list settings-list--people">
          {active.map((member) => (
            <PersonRow
              key={member.id}
              member={member}
              busy={busy}
              onRevoke={(id, name) => void run(() => revokeRole(client, id), `Papel removido de ${name}.`)}
              onProfile={(profile) =>
                void run(() => setOvertimeProfile(client, member.id, profile), `${member.name}: hora extra ${OVERTIME_PROFILE_LABELS[profile].toLowerCase()}.`)
              }
            />
          ))}
        </ul>

        {inactive.length > 0 && (
          <details className="people-inactive">
            <summary>Inativos no Azure DevOps ({inactive.length})</summary>
            <p className="muted">
              Sem licença ativa na última atualização. Os lançamentos e papéis continuam; só deixam de aparecer nas
              escolhas abaixo.
            </p>
            <ul className="settings-list settings-list--people">
              {inactive.map((member) => (
                <PersonRow key={member.id} member={member} busy={busy} onRevoke={(id, name) => void run(() => revokeRole(client, id), `Papel removido de ${name}.`)} />
              ))}
            </ul>
          </details>
        )}
      </section>

      <form className="card" onSubmit={grant} aria-label="Conceder papel">
        <h2>Conceder papel</h2>
        <div className="filters__grid">
          <div className="field">
            <label htmlFor={`${ids}-member`}>Pessoa</label>
            <select id={`${ids}-member`} className="input" value={memberId} onChange={(e) => setMemberId(e.target.value)}>
              <option value="">Escolha…</option>
              {active.map((member) => (
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

const formatSyncedAt = (iso: string): string =>
  new Date(iso).toLocaleString("pt-BR", { dateStyle: "short", timeStyle: "short" });

function PersonRow({
  member,
  busy,
  onRevoke,
  onProfile,
}: {
  member: SettingsMemberDto;
  busy: boolean;
  onRevoke: (assignmentId: string, memberName: string) => void;
  /** Perfil de hora extra; só para CLT ativo. */
  onProfile?: (profile: OvertimeProfile) => void;
}): JSX.Element {
  return (
    <li>
      <strong>{member.name}</strong>
      {onProfile && member.hoursRegime === "clt" && (
        <select
          className="input input--compact"
          aria-label={`Hora extra de ${member.name}`}
          value={member.overtimeProfile ?? "standard"}
          disabled={busy}
          onChange={(event) => onProfile(event.target.value as OvertimeProfile)}
        >
          {(Object.keys(OVERTIME_PROFILE_LABELS) as OvertimeProfile[]).map((profile) => (
            <option key={profile} value={profile}>
              Hora extra: {OVERTIME_PROFILE_LABELS[profile]}
            </option>
          ))}
        </select>
      )}
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
              onClick={() => onRevoke(assignment.id, member.name)}
            >
              ×
            </button>
          </span>
        ))}
      </span>
    </li>
  );
}
