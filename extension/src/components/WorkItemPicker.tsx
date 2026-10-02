import { useEffect, useId, useRef, useState, type KeyboardEvent } from "react";
import {
  fetchWorkItemDetails,
  searchWorkItems,
  workItemTypeColor,
  type WorkItemDetails,
  type WorkItemHit,
} from "../lib/devops/workItemSearch";

interface WorkItemPickerProps {
  selected: WorkItemDetails | null;
  onSelect: (workItem: WorkItemDetails | null) => void;
  autoFocus?: boolean;
}

const SEARCH_DELAY_MS = 250;

const errorText = (failure: unknown): string => (failure instanceof Error ? failure.message : String(failure));

function TypeMark({ workItemType }: { workItemType: string }): JSX.Element {
  return (
    <span
      className="wi-type"
      style={{ background: workItemTypeColor(workItemType) }}
      title={workItemType}
      aria-hidden="true"
    />
  );
}

/**
 * Escolha do work item pela busca, como no "Add time" do 7pace: digite o
 * número (lista todo ID que começa com ele) ou parte do título. Escolhido,
 * o item vira um chip; passar o mouse (ou focar) mostra o projeto, o pai e
 * o título inteiro, que no campo aparece cortado.
 */
export function WorkItemPicker({ selected, onSelect, autoFocus }: WorkItemPickerProps): JSX.Element {
  const [query, setQuery] = useState("");
  const [open, setOpen] = useState(false);
  const [hits, setHits] = useState<WorkItemHit[]>([]);
  const [active, setActive] = useState(0);
  const [searching, setSearching] = useState(false);
  const [pending, setPending] = useState<WorkItemHit | null>(null);
  const [error, setError] = useState<string | null>(null);
  const rootRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const searchSeq = useRef(0);
  const ids = useId();
  const listId = `${ids}-list`;
  const popoverId = `${ids}-detail`;

  useEffect(() => {
    if (!open) return;
    function closeOnOutsideClick(event: MouseEvent) {
      if (!rootRef.current?.contains(event.target as Node)) setOpen(false);
    }
    document.addEventListener("mousedown", closeOnOutsideClick);
    return () => document.removeEventListener("mousedown", closeOnOutsideClick);
  }, [open]);

  // Cada tecla reinicia a espera; só a última busca disparada atualiza a lista.
  useEffect(() => {
    if (!open || selected || pending) return;

    const seq = ++searchSeq.current;
    setSearching(true);
    const timer = window.setTimeout(() => {
      searchWorkItems(query)
        .then((found) => {
          if (seq !== searchSeq.current) return;
          setHits(found);
          setActive(0);
          setError(null);
        })
        .catch((failure: unknown) => seq === searchSeq.current && setError(errorText(failure)))
        .finally(() => seq === searchSeq.current && setSearching(false));
    }, SEARCH_DELAY_MS);

    return () => window.clearTimeout(timer);
  }, [query, open, selected, pending]);

  async function choose(hit: WorkItemHit) {
    setOpen(false);
    setPending(hit);
    setError(null);
    try {
      const details = await fetchWorkItemDetails(hit.id);
      if (!details.projectId) {
        throw new Error(`Não foi possível identificar o projeto do #${hit.id}. Abra a folha semanal dentro do projeto dele.`);
      }
      onSelect(details);
    } catch (failure) {
      setError(errorText(failure));
      onSelect(null);
    } finally {
      setPending(null);
    }
  }

  function clear() {
    onSelect(null);
    setQuery("");
    setHits([]);
    setOpen(true);
    // O input só volta a existir depois do próximo render.
    window.setTimeout(() => inputRef.current?.focus(), 0);
  }

  function handleKeyDown(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key === "Escape") {
      if (open) {
        // Fecha só a lista, não o painel em volta.
        event.stopPropagation();
        setOpen(false);
      }
      return;
    }
    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault();
      if (!open) return setOpen(true);
      if (hits.length === 0) return;
      const step = event.key === "ArrowDown" ? 1 : -1;
      setActive((current) => (current + step + hits.length) % hits.length);
      return;
    }
    if (event.key === "Enter") {
      event.preventDefault();
      if (open && hits[active]) void choose(hits[active]);
    }
  }

  const shown = selected ?? pending;

  if (shown) {
    const details = selected;
    return (
      <div className="wi-picker">
        <div className="wi-chip" tabIndex={0} aria-describedby={details ? popoverId : undefined}>
          <TypeMark workItemType={shown.workItemType} />
          <span className="wi-chip__text">
            {details ? (
              <a href={details.webUrl} target="_blank" rel="noreferrer" className="wi-chip__id">
                #{shown.id}
              </a>
            ) : (
              <span className="wi-chip__id">#{shown.id}</span>
            )}{" "}
            {shown.title}
          </span>
          {pending ? (
            <span className="muted">Carregando…</span>
          ) : (
            <button type="button" className="wi-chip__clear" aria-label="Trocar o work item" onClick={clear}>
              ×
            </button>
          )}

          {details && (
            <div id={popoverId} role="tooltip" className="wi-popover">
              <p className="wi-popover__path">
                {details.projectName}
                {details.parent && (
                  <>
                    {" / … / "}
                    <TypeMark workItemType={details.parent.workItemType} />
                    <span>
                      #{details.parent.id} {details.parent.title}
                    </span>
                  </>
                )}
              </p>
              <p className="wi-popover__title">
                <TypeMark workItemType={details.workItemType} />
                <span>
                  <strong>#{details.id}</strong> {details.title}
                </span>
              </p>
              <p className="muted wi-popover__meta">
                {[details.workItemType, details.state, details.iterationPath].filter(Boolean).join(" · ")}
              </p>
            </div>
          )}
        </div>
      </div>
    );
  }

  return (
    <div className="wi-picker" ref={rootRef}>
      <span className="wi-picker__icon" aria-hidden="true" />
      <input
        ref={inputRef}
        className="input wi-picker__input"
        role="combobox"
        aria-label="Work item"
        aria-autocomplete="list"
        aria-expanded={open}
        aria-controls={listId}
        aria-activedescendant={open && hits[active] ? `${listId}-${hits[active].id}` : undefined}
        placeholder="Buscar work item pelo número ou título"
        autoComplete="off"
        autoFocus={autoFocus}
        value={query}
        onFocus={() => setOpen(true)}
        onChange={(event) => {
          setQuery(event.target.value);
          setOpen(true);
        }}
        onKeyDown={handleKeyDown}
      />

      {open && (
        <ul id={listId} role="listbox" aria-label="Work items encontrados" className="select__list wi-picker__list">
          {hits.map((hit, index) => (
            <li
              key={hit.id}
              id={`${listId}-${hit.id}`}
              role="option"
              aria-selected={index === active}
              title={`#${hit.id} ${hit.title} — ${hit.projectName}`}
              className={index === active ? "select__option select__option--active" : "select__option"}
              onMouseEnter={() => setActive(index)}
              onMouseDown={(event) => event.preventDefault()}
              onClick={() => void choose(hit)}
            >
              <TypeMark workItemType={hit.workItemType} />
              <span className="wi-option__text">
                #{hit.id} {hit.title}
              </span>
              <span className="muted wi-option__project">{hit.projectName}</span>
            </li>
          ))}
          {hits.length === 0 && (
            <li className="select__option muted" role="presentation">
              {searching
                ? "Buscando…"
                : query.trim() === ""
                  ? "Digite o número ou parte do título."
                  : "Nenhum work item encontrado."}
            </li>
          )}
        </ul>
      )}

      {error && <p className="field__error">{error}</p>}
    </div>
  );
}
