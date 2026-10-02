import { useEffect, useState } from "react";
import { ManualEntryForm } from "../../components/ManualEntryForm";
import { WorkItemPicker } from "../../components/WorkItemPicker";
import type { ActivityTypeDto } from "../../lib/api/activityTypes";
import type { ApiClient } from "../../lib/api/client";
import type { WorkItemDetails } from "../../lib/devops/workItemSearch";

export interface AddTimeResources {
  displayName: string;
  activityTypes: ActivityTypeDto[];
}

interface AddTimePanelProps {
  client: ApiClient;
  date: string;
  /** Nome e atividades; null enquanto carregam. */
  resources: AddTimeResources | null;
  resourcesError: string | null;
  onClose: () => void;
  onSaved: (saved: { localDate: string; minutes: number }) => void;
}

/**
 * Lançamento a partir da data (o caminho inverso da guia do work item): o
 * "+" do dia abre este painel com a data pronta, e a pessoa busca o work
 * item pelo número ou título. O resto é o mesmo formulário da guia.
 */
export function AddTimePanel({ client, date, resources, resourcesError, onClose, onSaved }: AddTimePanelProps): JSX.Element {
  const [selected, setSelected] = useState<WorkItemDetails | null>(null);

  useEffect(() => {
    function closeOnEscape(event: KeyboardEvent) {
      if (event.key === "Escape") onClose();
    }
    document.addEventListener("keydown", closeOnEscape);
    return () => document.removeEventListener("keydown", closeOnEscape);
  }, [onClose]);

  return (
    <div
      className="drawer-backdrop"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose();
      }}
    >
      <aside className="drawer" role="dialog" aria-modal="true" aria-label="Adicionar tempo">
        {resources ? (
          <ManualEntryForm
            client={client}
            project={selected?.projectId ? { id: selected.projectId, name: selected.projectName } : null}
            workItem={
              selected
                ? {
                    id: selected.id,
                    title: selected.title,
                    workItemType: selected.workItemType,
                    iterationPath: selected.iterationPath,
                  }
                : null
            }
            activityTypes={resources.activityTypes}
            displayName={resources.displayName}
            initialDate={date}
            workItemField={<WorkItemPicker selected={selected} onSelect={setSelected} autoFocus />}
            onCancel={onClose}
            onSaved={onSaved}
          />
        ) : (
          <div className="add-time">
            <div className="add-time__header">
              <h2>Adicionar tempo</h2>
              <button type="button" className="btn btn--icon btn--ghost" aria-label="Fechar" onClick={onClose}>
                ×
              </button>
            </div>
            {resourcesError ? (
              <p className="alert" role="alert">
                {resourcesError}
              </p>
            ) : (
              <p className="muted">Carregando…</p>
            )}
          </div>
        )}
      </aside>
    </div>
  );
}
