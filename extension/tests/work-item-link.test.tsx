import { vi } from "vitest";

vi.mock("../src/lib/devops/sdk", () => ({
  getHostContext: async () => ({ name: "smitbr" }),
}));

import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import type { ApiClient } from "../src/lib/api/client";
import type { WeekDto, WeekEntryDto } from "../src/lib/api/timesheet";
import { EntryList } from "../src/pages/timesheet/EntryList";
import { WeekGrid } from "../src/pages/timesheet/WeekGrid";

const entry: WeekEntryDto = {
  id: "1",
  workItemId: 15841,
  localDate: "2026-10-01",
  timezone: "America/Sao_Paulo",
  durationSeconds: 900,
  startTime: "09:10",
  endTime: "09:25",
  source: "manual",
  billable: false,
  activityTypeId: null,
  note: null,
  revision: 1,
  projectId: "p1",
  projectName: "Reuniões SMIT",
  workItemTitle: "Daily 01/10/2026",
  workItemType: "Task",
  activityTypeName: null,
  activityTypeColor: null,
};

const week = {
  weekStartDate: "2026-09-28",
  weekEndDate: "2026-10-04",
  totalSeconds: 900,
  days: [{ date: "2026-10-01", totalSeconds: 900 }],
  entries: [entry],
} as unknown as WeekDto;

const EXPECTED = "https://dev.azure.com/smitbr/Reuni%C3%B5es%20SMIT/_workitems/edit/15841";

describe("link do work item na folha semanal", () => {
  afterEach(cleanup);

  it("na grade da semana, o work item abre no Azure DevOps", async () => {
    render(<WeekGrid week={week} today="2026-10-01" />);

    const link = await screen.findByRole("link", { name: /^#15841 Daily/ });
    expect(link).toHaveAttribute("href", EXPECTED);
    expect(link).toHaveAttribute("target", "_blank");
    expect(link).toHaveClass("wi-link");
  });

  it("na lista de lançamentos, o work item abre no Azure DevOps", async () => {
    render(<EntryList client={{} as ApiClient} entries={[entry]} editable={false} onChanged={() => undefined} />);

    const link = await screen.findByRole("link", { name: /^#15841 Daily/ });
    expect(link).toHaveAttribute("href", EXPECTED);
  });
});
