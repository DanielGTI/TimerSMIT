interface ColumnsMenuProps {
  columns: Array<{ key: string; label: string }>;
  visible: Set<string>;
  onToggle: (key: never) => void;
}

/** "Colunas": marca ou desmarca o que aparece na tabela (útil em telas menores). */
export function ColumnsMenu({ columns, visible, onToggle }: ColumnsMenuProps): JSX.Element {
  return (
    <details className="columns-menu">
      <summary className="btn btn--small">Colunas</summary>
      <div className="columns-menu__list" role="group" aria-label="Colunas visíveis">
        {columns.map((column) => (
          <label key={column.key} className="checkbox">
            <input type="checkbox" checked={visible.has(column.key)} onChange={() => onToggle(column.key as never)} /> {column.label}
          </label>
        ))}
      </div>
    </details>
  );
}
