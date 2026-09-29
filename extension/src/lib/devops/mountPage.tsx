import type { ReactElement } from "react";
import { createRoot } from "react-dom/client";
import { ensureSdkReady, notifyLoadSucceeded } from "./sdk";

/**
 * Sobe o handshake do SDK, renderiza a página e só então avisa o host que
 * o carregamento terminou — evita o "flash" de iframe vazio/erro no Azure
 * DevOps enquanto React ainda está montando.
 */
export async function mountPage(elementId: string, node: ReactElement): Promise<void> {
  await ensureSdkReady();

  const container = document.getElementById(elementId);
  if (!container) {
    throw new Error(`Elemento #${elementId} não encontrado no documento da página.`);
  }

  createRoot(container).render(node);
  notifyLoadSucceeded();
}
