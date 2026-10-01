# Compatibilidade com o Azure DevOps

Avaliação de 01/10/2026, com base na documentação oficial da Microsoft ([manifesto](https://learn.microsoft.com/en-us/azure/devops/extend/develop/manifest), [pontos de extensão](https://learn.microsoft.com/en-us/azure/devops/extend/reference/targets/overview), [navegação vertical](https://learn.microsoft.com/en-us/azure/devops/extend/develop/web-navigation)) e no que já foi validado ao vivo na organização `smitbr`.

## Onde roda

| Ambiente | Situação |
| --- | --- |
| Azure DevOps Services (nuvem, `dev.azure.com/{org}`) | **Suportado e validado ao vivo.** |
| Organizações em `{org}.visualstudio.com` | Esperado funcionar (o host é o mesmo serviço); **não testado**. |
| Azure DevOps Server (local) | **Fora do escopo** (a spec o exclui; o manifesto só mira `Microsoft.VisualStudio.Services`). |
| Aplicativo móvel | Não suportado (`supportsMobile` não é declarado). |

## Manifesto

| Item | Avaliação |
| --- | --- |
| Tipos e alvos de contribuição (`ms.vss-web.hub`, `ms.vss-web.hub-group` em `project-hub-groups-collection`, `ms.vss-work-web.work-item-form-page`) | Constam na documentação atual. Validados ao vivo. |
| Categoria `Azure Boards` | Valor válido. |
| Escopos | `vso.work` e `vso.memberentitlementmanagement` são nomes válidos. `vso.memberentitlementmanagement` é o de **leitura** (o de escrita é outro). `vso.work` provavelmente não é necessário (ver "Pontos de atenção"). |
| Ícones dos itens do menu lateral | Antes: nenhum definido (todos herdavam o ícone da extensão). Depois: `iconName` com nomes Fluent funcionou só para `Completed` e `Settings`; `CalendarWeek` e `BarChartVertical` **não existem no conjunto de ícones do Azure DevOps** e o `iconAsset` foi ignorado. Agora cada hub usa `icon: { light, dark }` com `asset://` (formato do exemplo oficial da Microsoft para navegação vertical), PNGs próprios para tema claro e escuro. **A conferir ao vivo.** |
| Extensão privada (`public: false`) | Só aparece para as organizações com quem é compartilhada. |

## Código da extensão

| Item | Avaliação |
| --- | --- |
| SDK `azure-devops-extension-sdk` 4.2.0 e `azure-devops-extension-api` 4.270 | Atuais na série 4. Existem versões 5.x (salto de major) sem ganho para o que usamos. |
| Início e carga | `SDK.init({ loaded: false })` + `notifyLoadSucceeded/Failed`: o host mostra o erro em vez de ficar em branco. |
| Tema claro/escuro | Cores vêm das variáveis CSS que o SDK injeta (não usa `--palette-*`). Validado ao vivo no claro. **Escuro: conferir visualmente** (T039). |
| API do work item | Lê o formulário aberto (`IWorkItemFormService`), sem chamar REST. O módulo é referenciado por id literal porque o pacote UMD quebra o empacotamento. |
| Lista de pessoas | Chamada REST direta com `SDK.getAccessToken()` a `vsaex.dev.azure.com`; validada ao vivo. |
| Armazenamento no navegador | Não usa cookies, `localStorage` nem IndexedDB. |
| Navegadores | Edge, Chrome e Firefox atuais. `crypto.randomUUID()` exige contexto seguro (o Azure DevOps é HTTPS) e Safari 15.4 ou mais novo. |

## Pontos de atenção

1. **Convidados e contas Microsoft pessoais.** O backend só abre sessão para identidades do diretório da SMIT (`TIMERSMIT_ALLOWED_AAD_TENANTS`). Quem entra na organização com e-mail externo (por exemplo, os usuários `@hotmail.com` da `smitbr`) pertence a outro diretório e **recebe 403**, mesmo aparecendo na lista de pessoas. É o comportamento desejado para uso interno; se algum precisar usar, inclua o diretório dele na variável.
2. **Licença Stakeholder.** O Stakeholder tem acesso reduzido ao Azure Boards; **não foi verificado** se a aba do work item e os hubs funcionam bem para essas contas (Fernando, Gabriel e Saulo têm Stakeholder).
3. **Escopo `vso.work`.** A extensão não chama a API de work items. Pode ser removido por mínimo privilégio, o que troca o certificado da extensão (novo `AZURE_DEVOPS_EXTENSION_SECRET`) e pede nova autorização. Deixado por ora para não arriscar a aba do work item.
4. **Mudança de escopo ou de id.** Qualquer alteração em `scopes` gera novo certificado no Marketplace; o `id` da extensão não pode mudar depois de publicado.
5. **Ícones dos hubs.** `iconName` só aceita nomes presentes no conjunto de ícones do próprio Azure DevOps (um nome inexistente fica sem ícone, sem erro); por isso usamos imagens próprias via `icon`. Não misturar `iconName` com `icon` no mesmo hub: o primeiro tem precedência e os itens ficariam com estilos diferentes.
6. **Edição de lançamento e a semana aberta/enviada.** Regras de negócio, não de plataforma; ver `specs/001-azure-devops-timetracker`.

## Se os ícones do menu não aparecerem

1. Confira que o VSIX instalado é o 0.7.4 ou mais novo e recarregue a página com Ctrl+F5: o menu é guardado em cache.
2. No `Network` do navegador, procure `marketplace/nav/*.png`: 404 indica caminho errado do ativo.
3. Se ainda assim não aparecerem, a alternativa é `iconName` com nomes confirmados no conjunto do Azure DevOps (já funcionam `Completed` e `Settings`).
