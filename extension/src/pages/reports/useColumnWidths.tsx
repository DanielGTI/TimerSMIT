import { useEffect, useRef, useState, type KeyboardEvent, type PointerEvent as ReactPointerEvent } from "react";

export const MIN_COLUMN_WIDTH = 50;
export const MAX_COLUMN_WIDTH = 900;
const KEY_STEP = 20;

const clampWidth = (value: number): number => Math.min(MAX_COLUMN_WIDTH, Math.max(MIN_COLUMN_WIDTH, Math.round(value)));

/** Larguras que o usuário já ajustou (só uma conveniência: sem storage, valem as iniciais). */
function load(storageKey: string, defaults: Record<string, number>): Record<string, number> {
  try {
    const parsed = JSON.parse(localStorage.getItem(storageKey) ?? "{}") as Record<string, unknown>;
    const widths: Record<string, number> = {};
    for (const key of Object.keys(defaults)) {
      const value = parsed[key];
      if (typeof value === "number" && Number.isFinite(value)) widths[key] = clampWidth(value);
    }
    return widths;
  } catch {
    return {};
  }
}

/**
 * Larguras de coluna ajustáveis (arrastando a borda do título ou pelo teclado),
 * guardadas por tabela no navegador. A tabela usa `table-layout: fixed`, então
 * a largura de cada coluna vem de um `<colgroup>`.
 */
export function useColumnWidths<K extends string>(storageKey: string, defaults: Record<K, number>) {
  const [widths, setWidths] = useState<Partial<Record<K, number>>>(() => load(storageKey, defaults) as Partial<Record<K, number>>);
  const widthsRef = useRef(widths);
  widthsRef.current = widths;

  useEffect(() => {
    try {
      localStorage.setItem(storageKey, JSON.stringify(widths));
    } catch {
      // Sem storage (janela privada, bloqueio): as larguras só valem nesta sessão.
    }
  }, [storageKey, widths]);

  const widthOf = (key: K): number => widths[key] ?? defaults[key];
  const setWidth = (key: K, value: number) => setWidths((current) => ({ ...current, [key]: clampWidth(value) }));
  const resetWidth = (key: K) =>
    setWidths((current) => {
      const next = { ...current };
      delete next[key];
      return next;
    });

  const startResize = (key: K, event: ReactPointerEvent<HTMLElement>) => {
    event.preventDefault();
    event.stopPropagation();
    const startX = event.clientX;
    const startWidth = widthsRef.current[key] ?? defaults[key];
    const move = (moveEvent: PointerEvent) => setWidth(key, startWidth + moveEvent.clientX - startX);
    const stop = () => {
      window.removeEventListener("pointermove", move);
      window.removeEventListener("pointerup", stop);
      window.removeEventListener("pointercancel", stop);
      document.body.classList.remove("is-resizing-column");
    };
    document.body.classList.add("is-resizing-column");
    window.addEventListener("pointermove", move);
    window.addEventListener("pointerup", stop);
    window.addEventListener("pointercancel", stop);
  };

  const resizeByKey = (key: K, event: KeyboardEvent<HTMLElement>) => {
    if (event.key === "ArrowRight") setWidth(key, widthOf(key) + KEY_STEP);
    else if (event.key === "ArrowLeft") setWidth(key, widthOf(key) - KEY_STEP);
    else if (event.key === "Home" || event.key === "Enter") resetWidth(key);
    else return;
    event.preventDefault();
  };

  /** Alça na borda direita do título da coluna. */
  const resizer = (key: K, label: string): JSX.Element => (
    <span
      className="col-resizer"
      role="separator"
      aria-orientation="vertical"
      aria-label={`Largura da coluna ${label}`}
      aria-valuemin={MIN_COLUMN_WIDTH}
      aria-valuemax={MAX_COLUMN_WIDTH}
      aria-valuenow={widthOf(key)}
      tabIndex={0}
      title="Arraste para ajustar a largura (duplo clique restaura)"
      onPointerDown={(event) => startResize(key, event)}
      onDoubleClick={() => resetWidth(key)}
      onKeyDown={(event) => resizeByKey(key, event)}
    />
  );

  /** `<colgroup>` e largura total da tabela para as colunas mostradas (mais uma coluna fixa opcional à esquerda). */
  const layout = (keys: K[], leadingWidth = 0) => ({
    tableWidth: keys.reduce((sum, key) => sum + widthOf(key), leadingWidth),
    colgroup: (
      <colgroup>
        {leadingWidth > 0 && <col style={{ width: leadingWidth }} />}
        {keys.map((key) => (
          <col key={key} style={{ width: widthOf(key) }} />
        ))}
      </colgroup>
    ),
  });

  return { widthOf, resizer, layout };
}
