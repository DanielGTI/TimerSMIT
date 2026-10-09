import { describe, expect, it } from "vitest";
import { changeRange, rangeError, type RangeText } from "../src/lib/time/range";

const empty: RangeText = { start: "", end: "", duration: "" };

describe("changeRange", () => {
  it("início + duração calculam o fim", () => {
    const withStart = changeRange(empty, "start", "10:00");
    expect(withStart).toEqual({ start: "10:00", end: "", duration: "" });
    expect(changeRange(withStart, "duration", "00:30")).toEqual({ start: "10:00", end: "10:30", duration: "00:30" });
  });

  it("início + fim calculam a duração, inclusive fracionada", () => {
    const withStart = changeRange(empty, "start", "10:00");
    expect(changeRange(withStart, "end", "10:07")).toEqual({ start: "10:00", end: "10:07", duration: "00:07" });
    expect(changeRange(withStart, "end", "11:45").duration).toBe("01:45");
  });

  it("mudar o início mantém a duração e move o fim", () => {
    const range = { start: "09:00", end: "10:30", duration: "01:30" };
    expect(changeRange(range, "start", "14:00")).toEqual({ start: "14:00", end: "15:30", duration: "01:30" });
  });

  it("mudar a duração com início move o fim", () => {
    const range = { start: "09:00", end: "10:30", duration: "01:30" };
    expect(changeRange(range, "duration", "02:00")).toEqual({ start: "09:00", end: "11:00", duration: "02:00" });
  });

  it("só com o fim, a duração define o início (e o fim define o início ao digitar a duração)", () => {
    const onlyEnd = changeRange(empty, "end", "10:30");
    expect(changeRange(onlyEnd, "duration", "00:30")).toEqual({ start: "10:00", end: "10:30", duration: "00:30" });

    const onlyDuration = changeRange(empty, "duration", "00:45");
    expect(changeRange(onlyDuration, "end", "18:00")).toEqual({ start: "17:15", end: "18:00", duration: "00:45" });
  });

  it("fim antes do início não recalcula e é apontado como erro", () => {
    const range = changeRange({ start: "10:00", end: "10:30", duration: "00:30" }, "end", "09:00");
    expect(range.duration).toBe("00:30");
    expect(rangeError(range)).toBe("O fim precisa ser depois do início.");
  });

  it("não passa da meia-noite", () => {
    const range = changeRange({ start: "23:00", end: "", duration: "" }, "duration", "02:00");
    expect(range.end).toBe("");
    expect(rangeError(range)).toBe("O lançamento não pode passar da meia-noite.");
  });

  it("valores incompletos ou inválidos não mudam o resto", () => {
    expect(changeRange({ start: "10:00", end: "", duration: "" }, "duration", "abc")).toEqual({ start: "10:00", end: "", duration: "abc" });
    expect(rangeError({ start: "", end: "", duration: "abc" })).toBeNull();
  });
});
