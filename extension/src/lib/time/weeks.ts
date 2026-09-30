/*
 * Datas como texto 'YYYY-MM-DD' (dia local, sem fuso) — a mesma forma que a
 * API usa. A aritmética passa por UTC só para evitar saltos de horário de
 * verão do navegador; nada aqui depende do fuso da máquina.
 */

const DAY_MS = 86_400_000;
const WEEKDAYS = ["seg", "ter", "qua", "qui", "sex", "sáb", "dom"];
const MONTHS_SHORT = ["jan", "fev", "mar", "abr", "mai", "jun", "jul", "ago", "set", "out", "nov", "dez"];
const MONTHS_LONG = [
  "janeiro",
  "fevereiro",
  "março",
  "abril",
  "maio",
  "junho",
  "julho",
  "agosto",
  "setembro",
  "outubro",
  "novembro",
  "dezembro",
];

function parts(iso: string): [number, number, number] {
  const [year, month, day] = iso.split("-").map(Number);
  return [year, month, day];
}

function toUtc(iso: string): Date {
  const [year, month, day] = parts(iso);
  return new Date(Date.UTC(year, month - 1, day));
}

function toIso(date: Date): string {
  return date.toISOString().slice(0, 10);
}

export function addDays(iso: string, days: number): string {
  return toIso(new Date(toUtc(iso).getTime() + days * DAY_MS));
}

/** Segunda-feira da semana que contém a data. */
export function mondayOf(iso: string): string {
  const daysSinceMonday = (toUtc(iso).getUTCDay() + 6) % 7;
  return addDays(iso, -daysSinceMonday);
}

export function monthOf(iso: string): string {
  return iso.slice(0, 7);
}

export function addMonths(month: string, delta: number): string {
  const [year, monthNumber] = parts(`${month}-01`);
  return toIso(new Date(Date.UTC(year, monthNumber - 1 + delta, 1))).slice(0, 7);
}

/** Semanas (segunda a domingo) que cobrem o mês, incluindo dias dos meses vizinhos. */
export function monthGrid(month: string): string[][] {
  const [year, monthNumber] = parts(`${month}-01`);
  const last = toIso(new Date(Date.UTC(year, monthNumber, 0)));
  const weeks: string[][] = [];

  for (let start = mondayOf(`${month}-01`); start <= last; start = addDays(start, 7)) {
    weeks.push(Array.from({ length: 7 }, (_, offset) => addDays(start, offset)));
  }

  return weeks;
}

export function weekdayShort(iso: string): string {
  return WEEKDAYS[(toUtc(iso).getUTCDay() + 6) % 7];
}

/** "28/09" */
export function dayMonth(iso: string): string {
  const [, month, day] = parts(iso);
  return `${String(day).padStart(2, "0")}/${String(month).padStart(2, "0")}`;
}

/** "28 set – 04 out 2026" */
export function formatWeekRange(weekStart: string): string {
  const end = addDays(weekStart, 6);
  const [startYear, startMonth, startDay] = parts(weekStart);
  const [endYear, endMonth, endDay] = parts(end);
  const startText = `${String(startDay).padStart(2, "0")} ${MONTHS_SHORT[startMonth - 1]}`;
  const endText = `${String(endDay).padStart(2, "0")} ${MONTHS_SHORT[endMonth - 1]}`;

  return startYear === endYear
    ? `${startText} – ${endText} ${endYear}`
    : `${startText} ${startYear} – ${endText} ${endYear}`;
}

/** "setembro de 2026" */
export function monthLabel(month: string): string {
  const [year, monthNumber] = parts(`${month}-01`);
  return `${MONTHS_LONG[monthNumber - 1]} de ${year}`;
}

export function dayOfMonth(iso: string): number {
  return parts(iso)[2];
}
