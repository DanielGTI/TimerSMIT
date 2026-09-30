const pad = (value: number): string => String(value).padStart(2, "0");

/** "01:30" -> 90 (minutos). Aceita H:MM ou HH:MM; inválido -> null. */
export function parseDuration(text: string): number | null {
  const match = /^(\d{1,3}):([0-5]\d)$/.exec(text.trim());
  return match ? Number(match[1]) * 60 + Number(match[2]) : null;
}

/** 90 -> "01:30". */
export function formatDuration(totalMinutes: number): string {
  return `${pad(Math.floor(totalMinutes / 60))}:${pad(totalMinutes % 60)}`;
}

/**
 * Segundos -> "HH:MM" para exibição. Arredonda só aqui, no fim (FR-005); um
 * intervalo curto mas real nunca aparece como 00:00.
 */
export function formatHours(totalSeconds: number): string {
  if (totalSeconds <= 0) return "00:00";
  return formatDuration(Math.max(1, Math.round(totalSeconds / 60)));
}

/** 4325 -> "01:12:05" (relógio do timer). */
export function formatElapsed(totalSeconds: number): string {
  const seconds = Math.max(0, Math.floor(totalSeconds));
  return `${pad(Math.floor(seconds / 3600))}:${pad(Math.floor((seconds % 3600) / 60))}:${pad(seconds % 60)}`;
}

/** "09:53" -> 593 (minutos desde 00:00); inválido -> null. */
export function timeToMinutes(text: string): number | null {
  const match = /^([01]\d|2[0-3]):([0-5]\d)$/.exec(text);
  return match ? Number(match[1]) * 60 + Number(match[2]) : null;
}

/** 593 -> "09:53". */
export function minutesToTime(minutes: number): string {
  return `${pad(Math.floor(minutes / 60))}:${pad(minutes % 60)}`;
}

/** Data de hoje no fuso do navegador (YYYY-MM-DD) — não a data em UTC. */
export function todayLocalIso(now: Date = new Date()): string {
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

/** Hora atual do navegador como "HH:MM". */
export function nowAsTime(now: Date = new Date()): string {
  return `${pad(now.getHours())}:${pad(now.getMinutes())}`;
}

export function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return "?";
  const first = parts[0][0];
  const last = parts.length > 1 ? parts[parts.length - 1][0] : "";
  return (first + last).toUpperCase();
}
