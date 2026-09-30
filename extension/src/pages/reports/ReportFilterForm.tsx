import { useId, type FormEvent } from "react";
import type { ReportFilters, ReportOptionsDto } from "../../lib/api/reports";
import type { WeekStatus } from "../../lib/api/timesheet";
import { periodPresets } from "./periods";

interface ReportFilterFormProps {
  draft: ReportFilters;
  options: ReportOptionsDto | null;
  today: string;
  busy: boolean;
  onChange: (next: ReportFilters) => void;
  onApply: () => void;
}

const STATUSES: Array<{ value: WeekStatus; label: string }> = [
  { value: "open", label: "Aberta" },
  { value: "submitted", label: "Enviada" },
  { value: "rejected", label: "Rejeitada" },
  { value: "approved", label: "Aprovada" },
];

/** Campo opcional: valor vazio = sem filtro (a chave sai do objeto). */
function withValue(filters: ReportFilters, key: keyof ReportFilters, value: string): ReportFilters {
  const next = { ...filters, [key]: value } as ReportFilters;
  if (value === "") delete next[key];
  return next;
}

export function ReportFilterForm({ draft, options, today, busy, onChange, onApply }: ReportFilterFormProps): JSX.Element {
  const ids = useId();
  const set = (key: keyof ReportFilters) => (value: string) => onChange(withValue(draft, key, value));

  function submit(event: FormEvent) {
    event.preventDefault();
    onApply();
  }

  return (
    <form className="filters" onSubmit={submit} aria-label="Filtros do relatório">
      <div className="filters__presets" role="group" aria-label="Períodos">
        {periodPresets(today).map((preset) => (
          <button
            key={preset.label}
            type="button"
            className={
              preset.from === draft.from && preset.to === draft.to ? "btn btn--chip btn--chip-active" : "btn btn--chip"
            }
            onClick={() => onChange({ ...draft, from: preset.from, to: preset.to })}
          >
            {preset.label}
          </button>
        ))}
      </div>

      <div className="filters__grid">
        <div className="field">
          <label htmlFor={`${ids}-from`}>De</label>
          <input id={`${ids}-from`} className="input" type="date" value={draft.from} onChange={(e) => onChange({ ...draft, from: e.target.value })} />
        </div>
        <div className="field">
          <label htmlFor={`${ids}-to`}>Até</label>
          <input id={`${ids}-to`} className="input" type="date" value={draft.to} onChange={(e) => onChange({ ...draft, to: e.target.value })} />
        </div>

        {options?.scope.canFilterByMember && (
          <div className="field">
            <label htmlFor={`${ids}-member`}>Pessoa</label>
            <select id={`${ids}-member`} className="input" value={draft.memberId ?? ""} onChange={(e) => set("memberId")(e.target.value)}>
              <option value="">Todas</option>
              {options.members.map((member) => (
                <option key={member.id} value={member.id}>
                  {member.name}
                </option>
              ))}
            </select>
          </div>
        )}

        <div className="field">
          <label htmlFor={`${ids}-project`}>Projeto</label>
          <select id={`${ids}-project`} className="input" value={draft.projectId ?? ""} onChange={(e) => set("projectId")(e.target.value)}>
            <option value="">Todos</option>
            {options?.projects.map((project) => (
              <option key={project.id} value={project.id}>
                {project.name}
              </option>
            ))}
          </select>
        </div>

        <div className="field">
          <label htmlFor={`${ids}-activity`}>Atividade</label>
          <select id={`${ids}-activity`} className="input" value={draft.activityTypeId ?? ""} onChange={(e) => set("activityTypeId")(e.target.value)}>
            <option value="">Todas</option>
            {options?.activityTypes.map((type) => (
              <option key={type.id} value={type.id}>
                {type.name}
                {type.enabled ? "" : " (desabilitada)"}
              </option>
            ))}
          </select>
        </div>

        <div className="field">
          <label htmlFor={`${ids}-billable`}>Faturável</label>
          <select id={`${ids}-billable`} className="input" value={draft.billable ?? ""} onChange={(e) => set("billable")(e.target.value)}>
            <option value="">Todos</option>
            <option value="true">Sim</option>
            <option value="false">Não</option>
          </select>
        </div>

        <div className="field">
          <label htmlFor={`${ids}-status`}>Estado da semana</label>
          <select id={`${ids}-status`} className="input" value={draft.status ?? ""} onChange={(e) => set("status")(e.target.value)}>
            <option value="">Todos</option>
            {STATUSES.map((status) => (
              <option key={status.value} value={status.value}>
                {status.label}
              </option>
            ))}
          </select>
        </div>

        <div className="field">
          <label htmlFor={`${ids}-workitem`}>Work item</label>
          <input
            id={`${ids}-workitem`}
            className="input"
            inputMode="numeric"
            placeholder="ID, ex.: 15835"
            value={draft.workItemId ?? ""}
            onChange={(e) => set("workItemId")(e.target.value.replace(/\D/g, ""))}
          />
        </div>
      </div>

      <div className="actions actions--start">
        <button type="submit" className="btn btn--primary" disabled={busy}>
          Aplicar filtros
        </button>
      </div>
    </form>
  );
}
