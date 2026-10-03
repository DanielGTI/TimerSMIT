import { useCallback, useEffect, useMemo, useState } from "react";
import { TabList } from "../../components/TabList";
import { createApiClient } from "../../lib/api/client";
import { getApiBaseUrl } from "../../lib/api/config";
import { fetchSettings, type SettingsDto } from "../../lib/api/settings";
import { ActivitiesSection } from "./ActivitiesSection";
import { AdditionalHoursSection } from "./AdditionalHoursSection";
import { ApproversSection } from "./ApproversSection";
import { ClosingSection } from "./ClosingSection";
import { HourBankSection } from "./HourBankSection";
import { PeopleSection } from "./PeopleSection";
import { ProjectHoursSection } from "./ProjectHoursSection";
import { ProjectsSection } from "./ProjectsSection";
import { OvertimeSection } from "./OvertimeSection";
import { RulesSection } from "./RulesSection";
import type { SectionProps } from "./sections";

type Tab = "rules" | "overtime" | "additional" | "bank" | "closing" | "projectHours" | "projects" | "activities" | "people" | "approvers";

const TABS: Array<{ id: Tab; label: string }> = [
  { id: "rules", label: "Regras" },
  { id: "overtime", label: "Horas extras" },
  { id: "additional", label: "Horas adicionais" },
  { id: "bank", label: "Banco de horas" },
  { id: "closing", label: "Fechamento do mês" },
  { id: "projectHours", label: "Horas por projeto" },
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
          <TabList
            label="Seções"
            tabs={TABS}
            value={tab}
            onChange={(id) => {
              setTab(id);
              setError(null);
              setNotice(null);
            }}
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
      </section>

      {tab === "rules" && <RulesSection {...section} />}
      {tab === "overtime" && <OvertimeSection {...section} />}
      {tab === "additional" && <AdditionalHoursSection {...section} />}
      {tab === "bank" && <HourBankSection {...section} />}
      {tab === "closing" && <ClosingSection {...section} />}
      {tab === "projectHours" && <ProjectHoursSection {...section} />}
      {tab === "projects" && <ProjectsSection {...section} />}
      {tab === "activities" && <ActivitiesSection {...section} />}
      {tab === "people" && <PeopleSection {...section} />}
      {tab === "approvers" && <ApproversSection {...section} />}
    </div>
  );
}
