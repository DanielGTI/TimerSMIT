import { useEffect, useMemo, useState } from "react";
import { createApiClient } from "../../lib/api/client";
import { getApiBaseUrl } from "../../lib/api/config";
import {
  downloadReportCsv,
  fetchReport,
  fetchReportOptions,
  type ReportDto,
  type ReportFilters,
  type ReportOptionsDto,
} from "../../lib/api/reports";
import { saveBlob } from "../../lib/download";
import { todayLocalIso } from "../../lib/time/format";
import { periodPresets } from "./periods";
import { ReportFilterForm } from "./ReportFilterForm";
import { ReportResults } from "./ReportResults";

const errorText = (failure: unknown): string => (failure instanceof Error ? failure.message : String(failure));

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
  const [loading, setLoading] = useState(true);
  const [exporting, setExporting] = useState(false);
  const [error, setError] = useState<string | null>(null);

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
  }, [client, applied, page]);

  function apply() {
    if (draft.from === "" || draft.to === "" || draft.from > draft.to) {
      setError("Informe um período válido: a data inicial não pode ser depois da final.");
      return;
    }
    setError(null);
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

      <section className="card" aria-busy={loading}>
        {report ? (
          <ReportResults report={report} onPage={setPage} />
        ) : (
          !error && <p className="muted">Carregando…</p>
        )}
      </section>
    </div>
  );
}
