import { describe, expect, it } from "vitest";
import {
  formatDuration,
  formatElapsed,
  initials,
  minutesToTime,
  parseDuration,
  timeToMinutes,
  todayLocalIso,
} from "../src/lib/time/format";

describe("parseDuration", () => {
  it("lê H:MM e HH:MM em minutos", () => {
    expect(parseDuration("01:30")).toBe(90);
    expect(parseDuration("1:05")).toBe(65);
    expect(parseDuration("100:00")).toBe(6000);
    expect(parseDuration(" 00:00 ")).toBe(0);
  });

  it("rejeita formatos inválidos e minutos fora de 00-59", () => {
    for (const text of ["", "abc", "1", "00:60", "1:5", "-1:00", "01:30:00"]) {
      expect(parseDuration(text)).toBeNull();
    }
  });
});

describe("formatDuration / formatElapsed", () => {
  it("formata minutos como HH:MM", () => {
    expect(formatDuration(0)).toBe("00:00");
    expect(formatDuration(90)).toBe("01:30");
    expect(formatDuration(6000)).toBe("100:00");
  });

  it("formata segundos como HH:MM:SS sem valores negativos", () => {
    expect(formatElapsed(4325)).toBe("01:12:05");
    expect(formatElapsed(-5)).toBe("00:00:00");
  });
});

describe("timeToMinutes / minutesToTime", () => {
  it("converte hora do dia nos dois sentidos", () => {
    expect(timeToMinutes("09:53")).toBe(593);
    expect(minutesToTime(593)).toBe("09:53");
    expect(timeToMinutes("23:59")).toBe(1439);
  });

  it("rejeita horas inexistentes", () => {
    expect(timeToMinutes("24:00")).toBeNull();
    expect(timeToMinutes("9:53")).toBeNull();
    expect(timeToMinutes("")).toBeNull();
  });
});

describe("todayLocalIso", () => {
  it("usa a data local, não a de UTC", () => {
    // 23:30 local: em fusos atrás de UTC, toISOString() já seria o dia seguinte.
    expect(todayLocalIso(new Date(2026, 8, 30, 23, 30))).toBe("2026-09-30");
    expect(todayLocalIso(new Date(2026, 0, 5, 0, 5))).toBe("2026-01-05");
  });
});

describe("initials", () => {
  it("usa primeira e última palavra do nome", () => {
    expect(initials("Daniel Ferreira")).toBe("DF");
    expect(initials("Ana Maria de Souza")).toBe("AS");
    expect(initials("daniel")).toBe("D");
    expect(initials("  ")).toBe("?");
  });
});
