import { useEffect, useId, useRef, useState, type KeyboardEvent } from "react";
import type { ActivityTypeDto } from "../lib/api/activityTypes";

interface ActivitySelectProps {
  options: ActivityTypeDto[];
  value: string | null;
  onChange: (id: string | null) => void;
  label: string;
  disabled?: boolean;
}

const NOT_SET = "Não definido";

/**
 * Seletor com a cor de cada atividade (um <select> nativo não renderiza as
 * amostras de cor). A primeira opção limpa a escolha.
 */
export function ActivitySelect({ options, value, onChange, label, disabled }: ActivitySelectProps): JSX.Element {
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  const rootRef = useRef<HTMLDivElement>(null);
  const listId = useId();

  const items: Array<ActivityTypeDto | null> = [null, ...options];
  const selected = options.find((option) => option.id === value) ?? null;

  useEffect(() => {
    if (!open) return;
    function closeOnOutsideClick(event: MouseEvent) {
      if (!rootRef.current?.contains(event.target as Node)) setOpen(false);
    }
    document.addEventListener("mousedown", closeOnOutsideClick);
    return () => document.removeEventListener("mousedown", closeOnOutsideClick);
  }, [open]);

  function openList() {
    setActive(Math.max(0, items.findIndex((item) => (item?.id ?? null) === value)));
    setOpen(true);
  }

  function choose(index: number) {
    onChange(items[index]?.id ?? null);
    setOpen(false);
  }

  function handleKeyDown(event: KeyboardEvent<HTMLButtonElement>) {
    if (event.key === "Escape") {
      setOpen(false);
      return;
    }
    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault();
      if (!open) return openList();
      const step = event.key === "ArrowDown" ? 1 : -1;
      setActive((current) => (current + step + items.length) % items.length);
      return;
    }
    if (event.key === "Enter" || event.key === " ") {
      event.preventDefault();
      if (open) choose(active);
      else openList();
    }
  }

  return (
    <div className="select" ref={rootRef}>
      <button
        type="button"
        role="combobox"
        className="select__trigger"
        aria-label={label}
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-controls={listId}
        aria-activedescendant={open ? `${listId}-${active}` : undefined}
        disabled={disabled}
        onClick={() => (open ? setOpen(false) : openList())}
        onKeyDown={handleKeyDown}
      >
        <span className="swatch" style={{ background: selected?.color ?? "transparent" }} />
        <span>{selected?.name ?? NOT_SET}</span>
        <span className="select__chevron" aria-hidden="true" />
      </button>

      {open && (
        <ul id={listId} role="listbox" aria-label={label} className="select__list">
          {items.map((item, index) => (
            <li
              key={item?.id ?? "none"}
              id={`${listId}-${index}`}
              role="option"
              aria-selected={(item?.id ?? null) === value}
              className={index === active ? "select__option select__option--active" : "select__option"}
              onMouseEnter={() => setActive(index)}
              onMouseDown={(event) => event.preventDefault()}
              onClick={() => choose(index)}
            >
              <span className="swatch" style={{ background: item?.color ?? "transparent" }} />
              <span>{item?.name ?? NOT_SET}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
