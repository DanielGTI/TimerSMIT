import { useMemo, useState, type ReactNode } from "react";
import { StatusBadge, WEEK_STATUS_LABELS } from "../../components/StatusBadge";
import type { ReportRowDto } from "../../lib/api/reports";
import { formatHours } from "../../lib/time/format";

type ColumnKey =
  | "hours"
  | "person"
  | "workItem"
  | "date"
  | "start"
  | "end"
  | "project"
  | "activity"
  | "type"
  | "iteration"
  | "billable"
  | "week"
  | "note";

interface Column {
  key: ColumnKey;
  label: string;
  /** Texto exibido; é nele que o filtro da coluna procura e é ele que dá nome ao grupo. */
  text: (row: ReportRowDto) => string;
  /** Valor de ordenação (padrão: o texto). */
  sortValue?: (row: ReportRowDto) => string | number;
  render?: (row: ReportRowDto, organization: string | null) => ReactNode;
  defaultVisible: boolean;
  groupable: boolean;
  numeric?: boolean;
}

const EMPTY = "(vazio)";

const brDate = (iso: string): string => {
  const [year, month, day] = iso.split("-");
  return `${day}/${month}/${year}`;
};

const COLUMNS: Column[] = [
  {
    key: "hours",
    label: "Horas",
    text: (row) => formatHours(row.durationSeconds),
    sortValue: (row) => row.durationSeconds,
    defaultVisible: true,
    groupable: false,
    numeric: true,
  },
  { key: "person", label: "Pessoa", text: (row) => row.memberName, defaultVisible: true, groupable: true },
  {
    key: "workItem",
    label: "Work item",
    text: (row) => `#${row.workItemId} ${row.workItemTitle ?? ""}`.trim(),
    sortValue: (row) => row.workItemId,
    render: (row, organization) => (
      <>
        {organization ? (
          <a
            href={`https://dev.azure.com/${encodeURIComponent(organization)}/${encodeURIComponent(row.projectName)}/_workitems/edit/${row.workItemId}`}
            target="_blank"
            rel="noopener noreferrer"
          >
            {row.workItemId}
          </a>
        ) : (
          row.workItemId
        )}{" "}
        {row.workItemTitle ?? ""}
      </>
    ),
    defaultVisible: true,
    groupable: true,
  },
  { key: "date", label: "Data", text: (row) => brDate(row.localDate), sortValue: (row) => row.localDate, defaultVisible: true, groupable: true },
  { key: "start", label: "Início", text: (row) => row.startTime ?? "", defaultVisible: true, groupable: false },
  { key: "end", label: "Fim", text: (row) => row.endTime ?? "", defaultVisible: true, groupable: false },
  { key: "project", label: "Projeto", text: (row) => row.projectName, defaultVisible: true, groupable: true },
  {
    key: "activity",
    label: "Atividade",
    text: (row) => row.activityTypeName ?? "Não definido",
    render: (row) => (
      <span className="activity">
        <span className="swatch" style={{ background: row.activityTypeColor ?? "transparent" }} />
        {row.activityTypeName ?? "Não definido"}
      </span>
    ),
    defaultVisible: true,
    groupable: true,
  },
  { key: "type", label: "Tipo do work item", text: (row) => row.workItemType ?? "", defaultVisible: true, groupable: true },
  { key: "iteration", label: "Iteração", text: (row) => row.iterationPath ?? "", defaultVisible: true, groupable: true },
  { key: "billable", label: "Faturável", text: (row) => (row.billable ? "Sim" : "Não"), defaultVisible: false, groupable: true },
  {
    key: "week",
    label: "Semana",
    text: (row) => WEEK_STATUS_LABELS[row.weekStatus],
    render: (row) => <StatusBadge status={row.weekStatus} />,
    defaultVisible: false,
    groupable: true,
  },
  { key: "note", label: "Comentário", text: (row) => row.note ?? "", defaultVisible: false, groupable: false },
];

const COLUMN_BY_KEY = new Map(COLUMNS.map((column) => [column.key, column]));
const GROUPABLE = COLUMNS.filter((column) => column.groupable);
const PAGE_OF_ROWS = 500;

interface GroupNode {
  kind: "group";
  path: string;
  depth: number;
  column: Column;
  value: string;
  seconds: number;
  count: number;
  children: Node[];
}
interface RowNode {
  kind: "row";
  row: ReportRowDto;
}
type Node = GroupNode | RowNode;

function build(rows: ReportRowDto[], keys: ColumnKey[], depth: number, parentPath: string): Node[] {
  if (keys.length === 0) return rows.map((row) => ({ kind: "row", row }));

  const column = COLUMN_BY_KEY.get(keys[0])!;
  const buckets = new Map<string, ReportRowDto[]>();
  for (const row of rows) {
    const value = column.text(row) || EMPTY;
    buckets.set(value, [...(buckets.get(value) ?? []), row]);
  }

  return [...buckets.entries()]
    .sort(([a], [b]) => a.localeCompare(b, "pt-BR", { numeric: true }))
    .map(([value, members]) => {
      const path = `${parentPath}/${column.key}=${value}`;
      return {
        kind: "group" as const,
        path,
        depth,
        column,
        value,
        seconds: members.reduce((sum, row) => sum + row.durationSeconds, 0),
        count: members.length,
        children: build(members, keys.slice(1), depth + 1, path),
      };
    });
}

function collectPaths(nodes: Node[]): string[] {
  return nodes.flatMap((node) => (node.kind === "group" ? [node.path, ...collectPaths(node.children)] : []));
}

interface ReportGridProps {
  rows: ReportRowDto[];
  /** Mostrar a coluna Pessoa (quem só enxerga os próprios lançamentos não precisa dela). */
  showPerson: boolean;
  /** Nome da organização no Azure DevOps, para o link do work item. */
  organization: string | null;
}

/**
 * Grade detalhada dos lançamentos: colunas escolhidas, filtro por coluna,
 * ordenação e agrupamento em até dois níveis com subtotal de horas e linhas.
 * Trabalha sobre as linhas já trazidas pelo servidor (mesmo escopo e filtros
 * da tela de resumo e do CSV); os filtros daqui só refinam o que já veio.
 */
export function ReportGrid({ rows, showPerson, organization }: ReportGridProps): JSX.Element {
  const available = useMemo(() => COLUMNS.filter((column) => showPerson || column.key !== "person"), [showPerson]);

  const [visible, setVisible] = useState<Set<ColumnKey>>(() => new Set(COLUMNS.filter((c) => c.defaultVisible).map((c) => c.key)));
  const [groupBy, setGroupBy] = useState<ColumnKey[]>([]);
  const [filters, setFilters] = useState<Partial<Record<ColumnKey, string>>>({});
  const [sort, setSort] = useState<{ key: ColumnKey; direction: 1 | -1 }>({ key: "date", direction: 1 });
  const [expanded, setExpanded] = useState<Set<string>>(new Set());
  const [limit, setLimit] = useState(PAGE_OF_ROWS);

  const columns = available.filter((column) => visible.has(column.key));

  const filtered = useMemo(() => {
    const active = Object.entries(filters).filter(([, text]) => text && text.trim() !== "");
    const matching =
      active.length === 0
        ? rows
        : rows.filter((row) =>
            active.every(([key, text]) =>
              COLUMN_BY_KEY.get(key as ColumnKey)!.text(row).toLocaleLowerCase("pt-BR").includes(text!.trim().toLocaleLowerCase("pt-BR")),
            ),
          );

    const column = COLUMN_BY_KEY.get(sort.key)!;
    const value = column.sortValue ?? column.text;
    return [...matching].sort((a, b) => {
      const left = value(a);
      const right = value(b);
      const order =
        typeof left === "number" && typeof right === "number"
          ? left - right
          : String(left).localeCompare(String(right), "pt-BR", { numeric: true });
      return order * sort.direction;
    });
  }, [rows, filters, sort]);

  const tree = useMemo(() => build(filtered, groupBy, 0, ""), [filtered, groupBy]);
  const totalSeconds = filtered.reduce((sum, row) => sum + row.durationSeconds, 0);

  // Linhas desenhadas: só as de grupos abertos, até o limite (a grade pode ter milhares).
  const display: Array<GroupNode | RowNode> = [];
  let drawnRows = 0;
  let hiddenRows = 0;
  const walk = (nodes: Node[]) => {
    for (const node of nodes) {
      if (node.kind === "row") {
        if (drawnRows < limit) {
          display.push(node);
          drawnRows++;
        } else {
          hiddenRows++;
        }
        continue;
      }
      display.push(node);
      if (expanded.has(node.path)) walk(node.children);
    }
  };
  walk(tree);

  const toggleColumn = (key: ColumnKey) =>
    setVisible((current) => {
      const next = new Set(current);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });

  const setGroup = (level: 0 | 1, key: string) => {
    const next = [...groupBy];
    if (key === "") next.splice(level);
    else next[level] = key as ColumnKey;
    setGroupBy(next.filter((value, index) => next.indexOf(value) === index));
    setExpanded(new Set());
    setLimit(PAGE_OF_ROWS);
  };

  const toggleGroup = (path: string) =>
    setExpanded((current) => {
      const next = new Set(current);
      if (next.has(path)) next.delete(path);
      else next.add(path);
      return next;
    });

  const sortBy = (key: ColumnKey) =>
    setSort((current) => (current.key === key ? { key, direction: current.direction === 1 ? -1 : 1 } : { key, direction: 1 }));

  const hasFilters = Object.values(filters).some((text) => text && text.trim() !== "");

  return (
    <div className="grid-report">
      <div className="grid-report__bar">
        <div className="field">
          <label htmlFor="grid-group-1">Agrupar por</label>
          <select id="grid-group-1" className="input input--compact" value={groupBy[0] ?? ""} onChange={(e) => setGroup(0, e.target.value)}>
            <option value="">Sem agrupamento</option>
            {GROUPABLE.filter((column) => showPerson || column.key !== "person").map((column) => (
              <option key={column.key} value={column.key}>
                {column.label}
              </option>
            ))}
          </select>
        </div>
        <div className="field">
          <label htmlFor="grid-group-2">Depois por</label>
          <select
            id="grid-group-2"
            className="input input--compact"
            value={groupBy[1] ?? ""}
            disabled={groupBy.length === 0}
            onChange={(e) => setGroup(1, e.target.value)}
          >
            <option value="">Nenhum</option>
            {GROUPABLE.filter((column) => column.key !== groupBy[0] && (showPerson || column.key !== "person")).map((column) => (
              <option key={column.key} value={column.key}>
                {column.label}
              </option>
            ))}
          </select>
        </div>

        <details className="columns-menu">
          <summary className="btn btn--small">Colunas</summary>
          <div className="columns-menu__list" role="group" aria-label="Colunas visíveis">
            {available.map((column) => (
              <label key={column.key} className="checkbox">
                <input type="checkbox" checked={visible.has(column.key)} onChange={() => toggleColumn(column.key)} /> {column.label}
              </label>
            ))}
          </div>
        </details>

        {groupBy.length > 0 && (
          <>
            <button type="button" className="btn btn--small" onClick={() => setExpanded(new Set(collectPaths(tree)))}>
              Expandir tudo
            </button>
            <button type="button" className="btn btn--small" onClick={() => setExpanded(new Set())}>
              Recolher tudo
            </button>
          </>
        )}
        {hasFilters && (
          <button type="button" className="btn btn--small" onClick={() => setFilters({})}>
            Limpar filtros
          </button>
        )}

        <span className="grid-report__total" role="status">
          Linhas filtradas: <strong>{filtered.length}</strong> (<strong>{formatHours(totalSeconds)}</strong> h)
        </span>
      </div>

      <div className="table-scroll">
        <table className="entry-table grid-table--report">
          <caption className="sr-only">Lançamentos detalhados</caption>
          <thead>
            <tr>
              {columns.map((column) => (
                <th
                  key={column.key}
                  scope="col"
                  aria-sort={sort.key === column.key ? (sort.direction === 1 ? "ascending" : "descending") : "none"}
                >
                  <button type="button" className="th-sort" onClick={() => sortBy(column.key)}>
                    {column.label}
                    {sort.key === column.key && <span aria-hidden="true">{sort.direction === 1 ? " ↑" : " ↓"}</span>}
                  </button>
                </th>
              ))}
            </tr>
            <tr className="filter-row">
              {columns.map((column) => (
                <td key={column.key}>
                  <input
                    className="input input--compact"
                    aria-label={`Filtrar ${column.label}`}
                    value={filters[column.key] ?? ""}
                    onChange={(e) => {
                      setFilters({ ...filters, [column.key]: e.target.value });
                      setLimit(PAGE_OF_ROWS);
                    }}
                  />
                </td>
              ))}
            </tr>
          </thead>
          <tbody>
            {display.length === 0 && (
              <tr>
                <td colSpan={Math.max(1, columns.length)} className="muted">
                  Nenhum lançamento para esses filtros.
                </td>
              </tr>
            )}
            {display.map((node) =>
              node.kind === "group" ? (
                <tr key={node.path} className="group-row">
                  <th colSpan={Math.max(1, columns.length)} scope="rowgroup" style={{ paddingLeft: 10 + node.depth * 22 }}>
                    <button
                      type="button"
                      className="group-toggle"
                      aria-expanded={expanded.has(node.path)}
                      onClick={() => toggleGroup(node.path)}
                    >
                      <span aria-hidden="true">{expanded.has(node.path) ? "▾" : "▸"}</span> {node.column.label}: <strong>{node.value}</strong>{" "}
                      <span className="muted">
                        ({formatHours(node.seconds)} h em {node.count} {node.count === 1 ? "linha" : "linhas"})
                      </span>
                    </button>
                  </th>
                </tr>
              ) : (
                <tr key={node.row.id}>
                  {columns.map((column) => (
                    <td key={column.key} className={column.numeric ? "num" : undefined}>
                      {column.render ? column.render(node.row, organization) : column.text(node.row) || <span className="muted">–</span>}
                    </td>
                  ))}
                </tr>
              ),
            )}
          </tbody>
        </table>
      </div>

      {hiddenRows > 0 && (
        <p className="grid-report__more">
          <span className="muted">Mostrando {drawnRows} linhas; faltam {hiddenRows}. </span>
          <button type="button" className="btn btn--small" onClick={() => setLimit(limit + PAGE_OF_ROWS)}>
            Mostrar mais {Math.min(PAGE_OF_ROWS, hiddenRows)}
          </button>
        </p>
      )}
    </div>
  );
}
