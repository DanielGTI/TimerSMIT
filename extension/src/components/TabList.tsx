import { useRef, type KeyboardEvent } from "react";

interface TabListProps<T extends string> {
  label: string;
  tabs: Array<{ id: T; label: string }>;
  value: T;
  onChange: (id: T) => void;
}

/**
 * Abas com o teclado do padrão ARIA: só a aba ativa entra na sequência do Tab
 * (tabindex móvel); setas, Home e End trocam de aba.
 */
export function TabList<T extends string>({ label, tabs, value, onChange }: TabListProps<T>): JSX.Element {
  const refs = useRef<Array<HTMLButtonElement | null>>([]);

  function handleKeyDown(event: KeyboardEvent<HTMLButtonElement>, index: number) {
    const last = tabs.length - 1;
    const target =
      event.key === "ArrowRight" ? (index + 1) % tabs.length
      : event.key === "ArrowLeft" ? (index + last) % tabs.length
      : event.key === "Home" ? 0
      : event.key === "End" ? last
      : null;
    if (target === null) return;
    event.preventDefault();
    onChange(tabs[target].id);
    refs.current[target]?.focus();
  }

  return (
    <div className="tabs" role="tablist" aria-label={label}>
      {tabs.map((tab, index) => (
        <button
          key={tab.id}
          ref={(node) => {
            refs.current[index] = node;
          }}
          type="button"
          role="tab"
          aria-selected={value === tab.id}
          tabIndex={value === tab.id ? 0 : -1}
          className="tabs__tab"
          onClick={() => onChange(tab.id)}
          onKeyDown={(event) => handleKeyDown(event, index)}
        >
          {tab.label}
        </button>
      ))}
    </div>
  );
}
