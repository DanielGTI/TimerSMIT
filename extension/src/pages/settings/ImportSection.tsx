import { useId, useState } from "react";
import { fileToBase64, importSevenPace, type ImportPreviewDto, type ImportRequest } from "../../lib/api/sevenPaceImport";
import { resolveProjectIds } from "../../lib/devops/workItemSearch";
import { formatHours } from "../../lib/time/format";
import type { SectionProps } from "./sections";

const errorText = (failure: unknown): string => (failure instanceof Error ? failure.message : String(failure));

const formatDate = (iso: string | null): string => {
  if (!iso) return "—";
  const [year, month, day] = iso.split("-");
  return `${day}/${month}/${year}`;
};

const MATCH_LABELS: Record<string, string> = {
  exact: "mesmo nome",
  prefix: "pelo começo do nome",
  manual: "escolhida",
  ignored: "deixada de fora",
};

const PROJECT_STATUS: Record<string, string> = {
  existing: "Já existe",
  create: "Será criado",
  missing: "Não encontrado no Azure DevOps",
};

const SKIP_LABELS: Array<[keyof ImportPreviewDto["skipped"], string]> = [
  ["alreadyImported", "já importados antes"],
  ["alreadyLogged", "já lançados no TimerSMIT (mesmo horário ou mesmo item e duração)"],
  ["lockedWeek", "em semana enviada ou aprovada"],
  ["unknownPerson", "de pessoa sem correspondência"],
  ["unknownProject", "de projeto não encontrado"],
];

/**
 * Importa o relatório "Times Explorer" do 7pace (.xlsx). Primeiro simula:
 * mostra quem é quem, quais projetos serão criados e o que fica de fora; só
 * grava ao confirmar. Repetir a importação não duplica nada.
 */
export function ImportSection({ settings, client }: SectionProps): JSX.Element {
  const ids = useId();
  const [file, setFile] = useState<{ name: string; content: string } | null>(null);
  const [personMap, setPersonMap] = useState<Record<string, string>>({});
  const [projectIds, setProjectIds] = useState<Record<string, string>>({});
  const [preview, setPreview] = useState<ImportPreviewDto | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  async function simulate(request: Omit<ImportRequest, "dryRun">): Promise<ImportPreviewDto | null> {
    setBusy(true);
    setError(null);
    try {
      let result = await importSevenPace(client, { ...request, dryRun: true });

      // Projetos que ainda não estão no TimerSMIT: o GUID vem do Azure DevOps.
      const missing = result.projects.filter((project) => project.status === "missing");
      if (missing.length > 0) {
        const found = await resolveProjectIds(missing.map((project) => ({ name: project.name, workItemId: project.sampleWorkItemId })));
        if (Object.keys(found).length > 0) {
          const merged = { ...request.projectIds, ...found };
          setProjectIds(merged);
          result = await importSevenPace(client, { ...request, projectIds: merged, dryRun: true });
        }
      }

      setPreview(result);
      return result;
    } catch (failure) {
      setError(errorText(failure));
      return null;
    } finally {
      setBusy(false);
    }
  }

  async function choose(selected: File | undefined) {
    setPreview(null);
    setNotice(null);
    setPersonMap({});
    setProjectIds({});
    if (!selected) {
      setFile(null);
      return;
    }

    const content = await fileToBase64(selected);
    setFile({ name: selected.name, content });
    await simulate({ file: content, personMap: {}, projectIds: {} });
  }

  async function mapPerson(name: string, memberId: string) {
    if (!file) return;
    const next = { ...personMap };
    if (memberId === "") delete next[name];
    else next[name] = memberId;
    setPersonMap(next);
    await simulate({ file: file.content, personMap: next, projectIds });
  }

  async function runImport() {
    if (!file) return;
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const result = await importSevenPace(client, { file: file.content, personMap, projectIds, dryRun: false });
      setNotice(`${result.imported} lançamento(s) importado(s), ${formatHours(result.toImportSeconds)}.`);
      setBusy(false);
      await simulate({ file: file.content, personMap, projectIds });
    } catch (failure) {
      setError(errorText(failure));
      setBusy(false);
    }
  }

  const skipped = preview ? SKIP_LABELS.filter(([key]) => preview.skipped[key] > 0) : [];

  return (
    <section className="card" aria-label="Importar do 7pace">
      <h2>Importar do 7pace</h2>
      <p className="muted">
        No 7pace, exporte o relatório <strong>Times Explorer</strong> em Excel (.xlsx) e escolha o arquivo abaixo. Nada é
        gravado antes de você confirmar. As horas entram como lançamentos manuais, com data, horário, work item,
        atividade e comentário. Importar o mesmo arquivo de novo não duplica nada.
      </p>

      <div className="field">
        <label htmlFor={`${ids}-file`}>Planilha do 7pace</label>
        <input
          id={`${ids}-file`}
          className="input"
          type="file"
          accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
          disabled={busy}
          onChange={(event) => void choose(event.target.files?.[0])}
        />
      </div>

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
      {busy && <p className="muted">Lendo a planilha…</p>}

      {preview && (
        <>
          <p>
            <strong>{preview.rows}</strong> linha(s), {formatHours(preview.totalSeconds)}, de {formatDate(preview.from)} a{" "}
            {formatDate(preview.to)}.{" "}
            {preview.toImport > 0 ? (
              <>
                Vão entrar <strong>{preview.toImport}</strong> lançamento(s), {formatHours(preview.toImportSeconds)}.
              </>
            ) : (
              "Nada novo para importar."
            )}
          </p>
          {skipped.length > 0 && (
            <ul className="muted" aria-label="Linhas que ficam de fora">
              {skipped.map(([key, label]) => (
                <li key={key}>
                  {preview.skipped[key]} {label}
                </li>
              ))}
            </ul>
          )}
          {preview.warnings.map((warning) => (
            <p key={warning} className="banner banner--warning" role="note">
              {warning}
            </p>
          ))}

          <h3 className="settings-subtitle">Pessoas</h3>
          <div className="table-scroll">
            <table className="entry-table">
              <caption className="sr-only">Pessoas da planilha</caption>
              <thead>
                <tr>
                  <th scope="col">Na planilha</th>
                  <th scope="col">Linhas</th>
                  <th scope="col">Horas</th>
                  <th scope="col">No TimerSMIT</th>
                </tr>
              </thead>
              <tbody>
                {preview.people.map((person) => (
                  <tr key={person.name}>
                    <td>{person.name}</td>
                    <td>{person.rows}</td>
                    <td>{formatHours(person.seconds)}</td>
                    <td>
                      <label htmlFor={`${ids}-person-${person.name}`} className="sr-only">
                        Pessoa no TimerSMIT para {person.name}
                      </label>
                      <select
                        id={`${ids}-person-${person.name}`}
                        className={person.memberId || person.match === "ignored" ? "input" : "input input--invalid"}
                        disabled={busy}
                        value={personMap[person.name] ?? person.memberId ?? ""}
                        onChange={(event) => void mapPerson(person.name, event.target.value)}
                      >
                        <option value="">{person.memberId ? "Automático" : "— escolha a pessoa —"}</option>
                        {settings.members.map((member) => (
                          <option key={member.id} value={member.id}>
                            {member.name}
                          </option>
                        ))}
                        <option value="none">Deixar de fora</option>
                      </select>
                      {person.match && <span className="muted block">{MATCH_LABELS[person.match]}</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="muted settings-hint">
            Não achou a pessoa? Ela aparece aqui depois de abrir a extensão uma vez ou de “Sincronizar com o Azure DevOps”
            em Pessoas e papéis.
          </p>

          <h3 className="settings-subtitle">Projetos</h3>
          <div className="table-scroll">
            <table className="entry-table">
              <caption className="sr-only">Projetos da planilha</caption>
              <thead>
                <tr>
                  <th scope="col">Projeto</th>
                  <th scope="col">Linhas</th>
                  <th scope="col">Horas</th>
                  <th scope="col">Situação</th>
                </tr>
              </thead>
              <tbody>
                {preview.projects.map((project) => (
                  <tr key={project.name}>
                    <td>{project.name}</td>
                    <td>{project.rows}</td>
                    <td>{formatHours(project.seconds)}</td>
                    <td className={project.status === "missing" ? "field__error" : undefined}>{PROJECT_STATUS[project.status]}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {preview.activities.some((activity) => activity.status === "create") && (
            <p className="muted settings-hint">
              Atividades novas que serão criadas:{" "}
              {preview.activities
                .filter((activity) => activity.status === "create")
                .map((activity) => activity.name)
                .join(", ")}
              .
            </p>
          )}

          <div className="actions actions--start">
            <button type="button" className="btn btn--primary" disabled={busy || preview.toImport === 0} onClick={() => void runImport()}>
              {preview.toImport > 0 ? `Importar ${preview.toImport} lançamento(s)` : "Nada a importar"}
            </button>
          </div>
        </>
      )}
    </section>
  );
}
