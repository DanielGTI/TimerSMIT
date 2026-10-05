import { useEffect, useState } from "react";
import { getHostContext } from "./sdk";

let known: string | null = null;
let pending: Promise<string | null> | null = null;

function loadOrganization(): Promise<string | null> {
  pending ??= getHostContext()
    .then((host) => {
      known = host.name || null;
      return known;
    })
    .catch(() => {
      pending = null;
      return null;
    });
  return pending;
}

/** Nome da organização no Azure DevOps (para montar links de work item); null enquanto não se sabe. */
export function useOrganization(): string | null {
  const [organization, setOrganization] = useState<string | null>(known);

  useEffect(() => {
    if (known) return;
    let live = true;
    void loadOrganization().then((name) => live && name && setOrganization(name));
    return () => {
      live = false;
    };
  }, []);

  return organization;
}

export const workItemUrl = (organization: string, projectName: string, workItemId: number): string =>
  `https://dev.azure.com/${encodeURIComponent(organization)}/${encodeURIComponent(projectName)}/_workitems/edit/${workItemId}`;
