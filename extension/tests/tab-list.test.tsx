import { useState } from "react";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import { TabList } from "../src/components/TabList";

const TABS = [
  { id: "a", label: "Primeira" },
  { id: "b", label: "Segunda" },
  { id: "c", label: "Terceira" },
];

function Harness() {
  const [value, setValue] = useState("a");
  return <TabList label="Seções" tabs={TABS} value={value} onChange={setValue} />;
}

describe("TabList", () => {
  afterEach(cleanup);

  it("só a aba ativa entra na sequência do Tab", () => {
    render(<Harness />);
    expect(screen.getByRole("tab", { name: "Primeira" })).toHaveAttribute("tabindex", "0");
    expect(screen.getByRole("tab", { name: "Segunda" })).toHaveAttribute("tabindex", "-1");
  });

  it("setas, Home e End trocam de aba e movem o foco", () => {
    render(<Harness />);
    const first = screen.getByRole("tab", { name: "Primeira" });

    fireEvent.keyDown(first, { key: "ArrowRight" });
    const second = screen.getByRole("tab", { name: "Segunda" });
    expect(second).toHaveAttribute("aria-selected", "true");
    expect(second).toHaveFocus();

    fireEvent.keyDown(second, { key: "End" });
    expect(screen.getByRole("tab", { name: "Terceira" })).toHaveFocus();

    fireEvent.keyDown(screen.getByRole("tab", { name: "Terceira" }), { key: "ArrowRight" });
    expect(screen.getByRole("tab", { name: "Primeira" })).toHaveAttribute("aria-selected", "true");

    fireEvent.keyDown(screen.getByRole("tab", { name: "Primeira" }), { key: "ArrowLeft" });
    expect(screen.getByRole("tab", { name: "Terceira" })).toHaveAttribute("aria-selected", "true");
  });
});
