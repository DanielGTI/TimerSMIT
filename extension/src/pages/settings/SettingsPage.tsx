import { useCallback, useEffect, useMemo, useState } from "react";
import { createApiClient } from "../../lib/api/client";
import { getApiBaseUrl } from "../../lib/api/config";
import { fetchSettings, type SettingsDto } from "../../lib/api/settings";
import { ActivitiesSection } from "./ActivitiesSection";
import { ApproversSection } from "./ApproversSection";
import { PeopleSection } from "./PeopleSection";
import { ProjectsSection } from "./ProjectsSection";
import { RulesSection } from "./RulesSection";
import type { SectionProps } from "./sections";

type Tab = "rules" | "projects" | "activities" | "people" | "approvers";

const TABS: Array<{ id: Tab; label: string }> = [
  { id: "rules", label: "Regras" },
  { id: "projects", label: "Projetos" },
  { id: "activities", label: "Atividades" },
  { id: "people", label: "Pessoas e papéis" },
  { id: "approvers", label: "Aprovadores" },
];

const errorText = (failure: unknown): string => (failure instanceof Error ? failure.message : String(failure));

/**
 * Configuração da organização (US5, T037) — só para administradores; o
 * servidor recusa os demais (a tela mostra o motivo). Cada alteração devolve
 * a visão completa, então a tela nunca mistura dados antigos e novos.
 */
export function SettingsPage(): JSX.Element {
  const client = useMemo(() => createApiClient({ apiBaseUrl: getApiBaseUrl() }), []);

  const [settings, setSettings] = useState<SettingsDto | null>(null);
  const [tab, setTab] = useState<Tab>("rules");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    fetchSettings(client)
      .then(setSettings)
      .catch((failure: unknown) => setError(errorText(failure)));
  }, [client]);

  const run = useCallback<SectionProps["run"]>(async (action, success) => {
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      setSettings(await action());
      setNotice(success);
      return true;
    } catch (failure) {
      setError(errorText(failure));
      return false;
    } finally {
      setBusy(false);
    }
  }, []);

  if (!settings) {
    return error ? (
      <p className="alert" role="alert">
        {error}
      </p>
    ) : (
      <p className="muted">Carregando…</p>
    );
  }

  const section: SectionProps = { settings, client, busy, run };

  return (
    <div className="page page--wide">
      <section className="card">
        <div className="toolbar">
          <h2>Configuração · {settings.organization.name}</h2>
          <div className="tabs" role="tablist" aria-label="Seções">
            {TABS.map((item) => (
              <button
                key={item.id}
                type="button"
                role="tab"
                aria-selected={tab === item.id}
                className="tabs__tab"
                onClick={() => {
                  setTab(item.id);
                  setError(null);
                  setNotice(null);
                }}
              >
                {item.label}
              </button>
            ))}
          </div>
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
      </section>

      {tab === "rules" && <RulesSection {...section} />}
      {tab === "projects" && <ProjectsSection {...section} />}
      {tab === "activities" && <ActivitiesSection {...section} />}
      {tab === "people" && <PeopleSection {...section} />}
      {tab === "approvers" && <ApproversSection {...section} />}
    </div>
  );
}
