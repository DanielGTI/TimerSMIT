# Research: Controle de horas integrado ao Azure DevOps

## Decisões fundamentadas

| Tema | Decisão preliminar | Motivo | Estado |
| --- | --- | --- | --- |
| Interface | Extensão web com guia de work item e hubs | Azure DevOps expõe contributions para esses locais | Documentado; validar IDs do hub em piloto |
| SDK | `azure-devops-extension-sdk` + `azure-devops-extension-api` | SDK oficial para contexto e APIs REST | Documentado |
| Escopo | `vso.work` leitura no MVP | Não há escrita automática em work items | Validar junto à autenticação |
| Backend | API própria com banco relacional | Timer concorrente, aprovações e relatórios transacionais | Proposta arquitetural |
| Fonte de horas | PostgreSQL no serviço, não campos do work item | Evita dupla contagem e preserva trilha de auditoria | Proposta arquitetural |
| Publicação | Extensões dev privada e prod pública distintas | Recomendada na documentação do Marketplace | Documentado |

Fontes oficiais: https://learn.microsoft.com/en-us/azure/devops/extend/develop/add-workitem-extension?view=azure-devops ; https://learn.microsoft.com/en-us/azure/devops/extend/develop/manifest?view=azure-devops ; https://learn.microsoft.com/en-us/azure/devops/extend/publish/overview?view=azure-devops ; https://learn.microsoft.com/en-us/azure/devops/extend/develop/auth?view=azure-devops

## Pesquisa/provas pendentes (bloqueadoras)

1. **Autenticação extensão → backend**: construir protótipo para obter identidade verificável, validar assinatura/audiência/organização no servidor, renovação, revogação e instalação/desinstalação. Não usar cabeçalhos de identidade fornecidos livremente pelo cliente como prova de autenticação.
2. **Contributions de hub**: testar página de folha, aprovação e configuração no contexto correto, navegação, tema e permissões.
3. **Integridade de work item**: verificar leitura por usuário e comportamento após exclusão, migração e revogação de acesso.
4. **Fuso e repartição diária**: especificar regra para timers atravessando dias; decisão: criar fatias diárias no fechamento, ligadas a uma sessão de origem. Testar transição histórica de horário de verão.
5. **Hospedagem e LGPD**: definir região, contratos, retenção, exclusão/exportação e suporte antes de publicar.
6. **Modelo comercial**: venda pública, cobrança/licenciamento próprios e administração de licenças fora do MVP.

## Alternativas avaliadas

- Apenas campos personalizados do work item: simples para horas agregadas, insuficiente para histórico por pessoa, dia e aprovação.
- Somente armazenamento da extensão: possível para dados pequenos, mas complexo para agregações transacionais e relatórios neste cenário.
- Sincronizar `Completed Work`: adiado, pois diferentes processos do Azure DevOps podem usar o campo de forma distinta.

Referência competitiva: https://marketplace.microsoft.com/en-il/product/7pacegmbh.timetracker . Usar apenas recursos públicos para definir escopo; não copiar elementos protegidos ou implementação.
