import { addDays, addMonths, mondayOf, monthOf } from "../../lib/time/weeks";

export interface Period {
  label: string;
  from: string;
  to: string;
}

function monthBounds(month: string): { from: string; to: string } {
  // Último dia do mês = dia anterior ao primeiro do mês seguinte.
  return { from: `${month}-01`, to: addDays(`${addMonths(month, 1)}-01`, -1) };
}

/** Períodos prontos, a partir do dia local de hoje. */
export function periodPresets(today: string): Period[] {
  const monday = mondayOf(today);
  const month = monthOf(today);

  return [
    { label: "Esta semana", from: monday, to: addDays(monday, 6) },
    { label: "Semana passada", from: addDays(monday, -7), to: addDays(monday, -1) },
    { label: "Este mês", ...monthBounds(month) },
    { label: "Mês passado", ...monthBounds(addMonths(month, -1)) },
    { label: "Últimos 30 dias", from: addDays(today, -29), to: today },
  ];
}
