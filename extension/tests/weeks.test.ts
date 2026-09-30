import { describe, expect, it } from "vitest";
import { formatHours } from "../src/lib/time/format";
import {
  addDays,
  addMonths,
  dayMonth,
  formatWeekRange,
  mondayOf,
  monthGrid,
  monthLabel,
  monthOf,
  weekdayShort,
} from "../src/lib/time/weeks";

describe("mondayOf / addDays", () => {
  it("acha a segunda-feira de qualquer dia da semana", () => {
    expect(mondayOf("2026-09-28")).toBe("2026-09-28"); // segunda
    expect(mondayOf("2026-09-30")).toBe("2026-09-28"); // quarta
    expect(mondayOf("2026-10-04")).toBe("2026-09-28"); // domingo
    expect(mondayOf("2026-10-05")).toBe("2026-10-05");
  });

  it("soma dias atravessando mês e ano", () => {
    expect(addDays("2026-09-28", 6)).toBe("2026-10-04");
    expect(addDays("2026-12-31", 1)).toBe("2027-01-01");
    expect(addDays("2026-03-01", -1)).toBe("2026-02-28");
  });
});

describe("meses", () => {
  it("avança e volta meses", () => {
    expect(addMonths("2026-09", 1)).toBe("2026-10");
    expect(addMonths("2026-01", -1)).toBe("2025-12");
    expect(addMonths("2026-12", 1)).toBe("2027-01");
    expect(monthOf("2026-09-30")).toBe("2026-09");
  });

  it("monta as semanas que cobrem o mês, de segunda a domingo", () => {
    const grid = monthGrid("2026-09"); // 1º de setembro de 2026 é terça
    expect(grid[0][0]).toBe("2026-08-31");
    expect(grid[0][1]).toBe("2026-09-01");
    const lastWeek = grid[grid.length - 1];
    expect(lastWeek[lastWeek.length - 1]).toBe("2026-10-04");
    expect(grid).toHaveLength(5);
    expect(grid.every((week) => week.length === 7)).toBe(true);
  });
});

describe("formatação", () => {
  it("formata a semana, inclusive atravessando mês e ano", () => {
    expect(formatWeekRange("2026-09-28")).toBe("28 set – 04 out 2026");
    expect(formatWeekRange("2026-09-14")).toBe("14 set – 20 set 2026");
    expect(formatWeekRange("2026-12-28")).toBe("28 dez 2026 – 03 jan 2027");
  });

  it("formata rótulos em português", () => {
    expect(monthLabel("2026-09")).toBe("setembro de 2026");
    expect(weekdayShort("2026-09-30")).toBe("qua");
    expect(weekdayShort("2026-10-04")).toBe("dom");
    expect(dayMonth("2026-09-05")).toBe("05/09");
  });
});

describe("formatHours", () => {
  it("arredonda para o minuto só na exibição", () => {
    expect(formatHours(0)).toBe("00:00");
    expect(formatHours(5400)).toBe("01:30");
    expect(formatHours(89)).toBe("00:01");
    expect(formatHours(20)).toBe("00:01"); // curto mas real: nunca 00:00
    expect(formatHours(-5)).toBe("00:00");
  });
});
