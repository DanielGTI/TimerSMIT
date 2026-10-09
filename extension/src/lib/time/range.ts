import { formatDuration, minutesToTime, parseDuration, timeToMinutes } from "./format";

/** Os três campos de um lançamento com horário, como o usuário os digita. */
export interface RangeText {
  start: string;
  end: string;
  duration: string;
}

export type RangeField = keyof RangeText;

const DAY = 24 * 60;

/**
 * Muda um dos campos e recalcula o que dá para saber:
 * - início + duração → fim;
 * - início + fim → duração;
 * - só fim + duração → início.
 * O que o usuário acabou de digitar nunca é sobrescrito.
 */
export function changeRange(current: RangeText, field: RangeField, value: string): RangeText {
  const next = { ...current, [field]: value };
  const start = timeToMinutes(next.start);
  const end = timeToMinutes(next.end);
  const duration = parseDuration(next.duration);
  const hasDuration = duration !== null && duration > 0;

  if (field === "end") {
    if (end === null) return next;
    if (start !== null && end > start) next.duration = formatDuration(end - start);
    else if (start === null && hasDuration && end >= duration) next.start = minutesToTime(end - duration);
    return next;
  }

  // Início ou duração: com os dois, o fim acompanha.
  if (start !== null && hasDuration) {
    next.end = start + duration < DAY ? minutesToTime(start + duration) : "";
  } else if (field === "duration" && start === null && end !== null && hasDuration && end >= duration) {
    next.start = minutesToTime(end - duration);
  }
  return next;
}

/** Mensagem de por que o horário não serve, ou null. */
export function rangeError(range: RangeText): string | null {
  const start = timeToMinutes(range.start);
  const end = timeToMinutes(range.end);
  const duration = parseDuration(range.duration);

  if (start !== null && end !== null && end <= start) return "O fim precisa ser depois do início.";
  if (start !== null && duration !== null && start + duration > DAY) return "O lançamento não pode passar da meia-noite.";
  return null;
}
