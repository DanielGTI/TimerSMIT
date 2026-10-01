import { useId, useState, type FormEvent } from "react";
import { designateApprover, removeDesignation } from "../../lib/api/settings";
import { ORGANIZATION_SCOPE, type SectionProps } from "./sections";

/**
 * Quem aprova a semana de quem. A designação é resolvida no envio; semanas
 * já enviadas só mudam de mãos se "aplicar às pendentes" estiver marcado.
 * Sem designação, os administradores decidem.
 */
export function ApproversSection({ settings, client, busy, run }: SectionProps): JSX.Element {
  const ids = useId();
  const [memberId, setMemberId] = useState("");
  const [approverId, setApproverId] = useState("");
  const [projectId, setProjectId] = useState("");
  const [applyToPending, setApplyToPending] = useState(false);

  function designate(event: FormEvent) {
    event.preventDefault();
    void run(
      () => designateApprover(client, memberId, approverId, projectId || null, applyToPending),
      "Aprovador designado.",
    ).then((ok) => {
      if (ok) {
        setApproverId("");
      }
    });
  }

  const sameSelection = memberId !== "" && memberId === approverId;

  return (
    <>
      <section className="card">
        <h2>Aprovadores designados</h2>
        <p className="muted">
          Sem designação para uma pessoa, os administradores aprovam a semana dela. Com designação, quem foi indicado
          aprova (e os administradores continuam podendo decidir).
        </p>

        {settings.designations.length === 0 ? (
          <p className="muted">Nenhuma designação: os administradores aprovam todas as semanas.</p>
        ) : (
          <ul className="settings-list">
            {settings.designations.map((designation) => (
              <li key={designation.id}>
                <span>
                  <strong>{designation.approverName}</strong> aprova as semanas de <strong>{designation.memberName}</strong>{" "}
                  <span className="muted">({designation.projectName ?? ORGANIZATION_SCOPE})</span>
                </span>
                <span className="row-actions">
                  <button
                    type="button"
                    className="btn btn--small"
                    disabled={busy}
                    onClick={() => void run(() => removeDesignation(client, designation.id, false), "Designação removida.")}
                  >
                    Remover
                  </button>
                  <button
                    type="button"
                    className="btn btn--small"
                    disabled={busy}
                    onClick={() =>
                      void run(() => removeDesignation(client, designation.id, true), "Designação removida e pendências reatribuídas.")
                    }
                  >
                    Remover e reatribuir pendentes
                  </button>
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>

      <form className="card" onSubmit={designate} aria-label="Designar aprovador">
        <h2>Designar aprovador</h2>
        <div className="filters__grid">
          <div className="field">
            <label htmlFor={`${ids}-member`}>Semanas de</label>
            <select id={`${ids}-member`} className="input" value={memberId} onChange={(e) => setMemberId(e.target.value)}>
              <option value="">Escolha…</option>
              {settings.members.filter((member) => member.directoryActive !== false).map((member) => (
                <option key={member.id} value={member.id}>
                  {member.name}
                </option>
              ))}
            </select>
          </div>
          <div className="field">
            <label htmlFor={`${ids}-approver`}>Aprovador</label>
            <select id={`${ids}-approver`} className="input" value={approverId} onChange={(e) => setApproverId(e.target.value)}>
              <option value="">Escolha…</option>
              {settings.members.filter((member) => member.directoryActive !== false).map((member) => (
                <option key={member.id} value={member.id}>
                  {member.name}
                </option>
              ))}
            </select>
          </div>
          <div className="field">
            <label htmlFor={`${ids}-project`}>Projeto</label>
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

        <label className="checkbox">
          <input type="checkbox" checked={applyToPending} onChange={(e) => setApplyToPending(e.target.checked)} /> Aplicar às
          semanas já enviadas e pendentes
        </label>

        {sameSelection && (
          <p className="field__error">Uma pessoa não pode aprovar a própria semana.</p>
        )}

        <div className="actions actions--start">
          <button type="submit" className="btn btn--primary" disabled={busy || memberId === "" || approverId === "" || sameSelection}>
            Designar
          </button>
        </div>
      </form>
    </>
  );
}
