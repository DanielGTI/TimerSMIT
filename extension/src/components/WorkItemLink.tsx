import type { ReactNode } from "react";
import { useOrganization, workItemUrl } from "../lib/devops/organization";

interface WorkItemLinkProps {
  workItemId: number;
  projectName: string;
  children: ReactNode;
}

/**
 * Abre o work item no Azure DevOps (nova aba). A cor segue a do texto da tela
 * (escura no tema claro, clara no escuro) para manter a leitura; sem saber a
 * organização, mostra só o texto.
 */
export function WorkItemLink({ workItemId, projectName, children }: WorkItemLinkProps): JSX.Element {
  const organization = useOrganization();
  if (!organization) return <>{children}</>;

  return (
    <a
      className="wi-link"
      href={workItemUrl(organization, projectName, workItemId)}
      target="_blank"
      rel="noopener noreferrer"
      title={`Abrir o work item ${workItemId} no Azure DevOps`}
    >
      {children}
    </a>
  );
}
