import type { ReactElement } from "react";
import { createRoot } from "react-dom/client";
import { ensureSdkReady, notifyLoadFailed, notifyLoadSucceeded } from "./sdk";

/**
 * Sobe o handshake do SDK, renderiza a página e só então avisa o host que
 * o carregamento terminou — evita o "flash" de iframe vazio/erro no Azure
 * DevOps enquanto React ainda está montando.
 *
 * Qualquer falha aqui é reportada ao host via `notifyLoadFailed` — sem isso,
 * o Azure DevOps não tem como saber que algo deu errado e só descobre pelo
 * próprio timeout dele, minutos depois, com uma mensagem genérica.
 */
export async function mountPage(elementId: string, node: ReactElement): Promise<void> {
  try {
    await ensureSdkReady();

    const container = document.getElementById(elementId);
    if (!container) {
      throw new Error(`Elemento #${elementId} não encontrado no documento da página.`);
    }

    createRoot(container).render(node);
    notifyLoadSucceeded();
  } catch (error) {
    // eslint-disable-next-line no-console
    console.error("[timersmit] Falha ao carregar a página da extensão:", error);
    notifyLoadFailed(error);
  }
}
