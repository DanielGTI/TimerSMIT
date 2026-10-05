import { useEffect, useMemo, useState } from "react";
import { createApiClient } from "../../lib/api/client";
import { getApiBaseUrl } from "../../lib/api/config";
import { TabList } from "../../components/TabList";
import {
  downloadReportCsv,
  fetchReport,
  fetchReportDetail,
  fetchReportOptions,
  type ReportDetailDto,
  type ReportDto,
  type ReportFilters,
  type ReportOptionsDto,
  type ReportRowDto,
} from "../../lib/api/reports";
import { saveBlob } from "../../lib/download";
import { todayLocalIso } from "../../lib/time/format";
import { EntryEditPanel } from "./EntryEditPanel";
import { periodPresets } from "./periods";
import { ReportFilterForm } from "./ReportFilterForm";
import { ReportGrid } from "./ReportGrid";
import { ReportResults } from "./ReportResults";

const errorText = (failure: unknown): string => (failure instanceof Error ? failure.message : String(failure));

type View = "summary" | "detail";

const VIEWS: Array<{ id: View; label: string }> = [
  { id: "summary", label: "Resumo" },
  { id: "detail", label: "Detalhada" },
];

/**
 * Relatórios (US4, T033). Filtros → consulta no servidor → totais, quebras e
 * linhas. "Exportar CSV" usa exatamente os filtros aplicados na tela, e o
 * servidor gera tela e arquivo pela mesma consulta e escopo (FR-009).
 */
export function ReportsPage(): JSX.Element {
  const client = useMemo(() => createApiClient({ apiBaseUrl: getApiBaseUrl() }), []);
  const today = todayLocalIso();

  const [options, setOptions] = useState<ReportOptionsDto | null>(null);
  const [draft, setDraft] = useState<ReportFilters>(() => {
    const thisMonth = periodPresets(today)[2];
    return { from: thisMonth.from, to: thisMonth.to };
  });
  const [applied, setApplied] = useState<ReportFilters>(draft);
  const [page, setPage] = useState(1);
  const [report, setReport] = useState<ReportDto | null>(null);
  const [view, setView] = useState<View>("summary");
  const [detail, setDetail] = useState<ReportDetailDto | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [loading, setLoading] = useState(true);
  const [exporting, setExporting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // Correção pelo administrador: lançamento aberto no painel e recarga depois de salvar.
  const [editing, setEditing] = useState<ReportRowDto | null>(null);
  const [reloadKey, setReloadKey] = useState(0);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    fetchReportOptions(client)
      .then(setOptions)
      .catch((failure: unknown) => setError(errorText(failure)));
  }, [client]);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);

    fetchReport(client, applied, page)
      .then((data) => {
        if (cancelled) return;
        setReport(data);
        setError(null);
      })
      .catch((failure: unknown) => !cancelled && setError(errorText(failure)))
      .finally(() => !cancelled && setLoading(false));

    return () => {
      cancelled = true;
    };
  }, [client, applied, page, reloadKey]);

  // A grade detalhada só é buscada quando a aba é aberta (e a cada filtro novo).
  useEffect(() => {
    if (view !== "detail") return;
    let cancelled = false;
    setDetailLoading(true);

    fetchReportDetail(client, applied)
      .then((data) => {
        if (cancelled) return;
        setDetail(data);
        setError(null);
      })
      .catch((failure: unknown) => !cancelled && setError(errorText(failure)))
      .finally(() => !cancelled && setDetailLoading(false));

    return () => {
      cancelled = true;
    };
  }, [client, applied, view, reloadKey]);

  function apply() {
    if (draft.from === "" || draft.to === "" || draft.from > draft.to) {
      setError("Informe um período válido: a data inicial não pode ser depois da final.");
      return;
    }
    setError(null);
    setNotice(null);
    setPage(1);
    setApplied(draft);
  }

  async function exportCsv() {
    setExporting(true);
    setError(null);
    try {
      saveBlob(await downloadReportCsv(client, applied), `horas_${applied.from}_a_${applied.to}.csv`);
    } catch (failure) {
      setError(errorText(failure));
    } finally {
      setExporting(false);
    }
  }

  return (
    <div className="page page--wide">
      <section className="card">
        <div className="toolbar">
          <h2>Relatórios</h2>
          <button
            type="button"
            className="btn"
            disabled={exporting || loading || !report || report.totals.entryCount === 0}
            onClick={() => void exportCsv()}
          >
            {exporting ? "Exportando…" : "Exportar CSV"}
          </button>
        </div>

        <ReportFilterForm draft={draft} options={options} today={today} busy={loading} onChange={setDraft} onApply={apply} />

        {error && (
          <p className="alert" role="alert">
            {error}
          </p>
        )}
      </section>

      <section className="card" aria-busy={view === "summary" ? loading : detailLoading}>
        <div className="toolbar">
          <TabList label="Visão do relatório" tabs={VIEWS} value={view} onChange={setView} />
        </div>

        {view === "summary" &&
          (report ? <ReportResults report={report} onPage={setPage} showBillable={options?.billableInUse ?? false} /> : !error && <p className="muted">Carregando…</p>)}

        {view === "detail" &&
          (detail ? (
            <>
              {notice && (
                <p className="notice" role="status">
                  {notice}
                </p>
              )}
              {detail.truncated && (
                <p className="banner" role="status">
                  O período tem {detail.totals.entryCount} lançamentos e a grade mostra só os primeiros {detail.rows.length}. Reduza o
                  período ou use os filtros acima.
                </p>
              )}
              <ReportGrid
                rows={detail.rows}
                showPerson={detail.scope.canFilterByMember}
                showBillable={options?.billableInUse ?? false}
                onEdit={
                  detail.canEditEntries
                    ? (row) => {
                        setNotice(null);
                        setEditing(row);
                      }
                    : undefined
                }
              />
            </>
          ) : (
            !error && <p className="muted">Carregando…</p>
          ))}
      </section>

      {editing && (
        <EntryEditPanel
          client={client}
          row={editing}
          options={options}
          onClose={() => setEditing(null)}
          onSaved={(message) => {
            setEditing(null);
            setNotice(message);
            setReloadKey((key) => key + 1);
          }}
        />
      )}
    </div>
  );
}
