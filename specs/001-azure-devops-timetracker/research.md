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

1. **Autenticação extensão → backend** — protótipo implementado (T006), prova ainda parcial:
   - **Mecanismo escolhido**: a extensão obtém o token de app do host via `SDK.getAppToken()` (`extension/src/lib/devops/sdk.ts`) e o envia, junto com o nome da organização (`SDK.getHost().name`, apenas como dica de roteamento), para `POST /api/auth/session`. O backend (`api/app/Services/DevOpsIdentityVerifier.php`) chama `GET https://dev.azure.com/{organização}/_apis/connectionData` com esse token; se o Azure DevOps aceitar a chamada, `instanceId` (GUID da organização) e `authenticatedUser.id` (GUID do usuário) — **nunca** o que o cliente afirmou — são a fonte de verdade para tenant/identidade. A partir daí o backend emite sua própria sessão (JWT HS256, `api/app/Services/SessionTokenService.php`), curta (60 min por padrão), que é o único credencial aceito nas rotas protegidas (`api/app/Http/Middleware/ResolveTenantContext.php`).
   - **Renovação**: a extensão simplesmente pede um novo `getAppToken()` e troca por nova sessão quando a atual expira ou um 401 é recebido (`extension/src/lib/api/client.ts`); não há refresh token próprio.
   - **Revogação/instalação-desinstalação**: ainda não coberta — depende de como o Azure DevOps notifica desinstalação de extensão (webhook/evento), a confirmar na organização de teste.
   - **Testado com**: `api/tests/Feature/AuthSessionTest.php` (fluxo completo com `Http::fake` simulando `connectionData`) e `api/tests/Feature/TenantIsolationTest.php` (sessão de um tenant não enxerga outro). Todos os 9 testes passam via PHP 8.4/Laravel 12 em container Docker.
   - **Pendência real**: os nomes de campo exatos de `connectionData` (`instanceId`, `authenticatedUser.id`, `authenticatedUser.providerDisplayName`) foram assumidos a partir da documentação e não foram confirmados contra uma resposta real — isso só é possível com a organização de teste do passo 3. **Gate do T006 mantém-se: não usar dados reais de usuários antes dessa confirmação.**
2. **Contributions de hub**: manifesto rascunhado em `extension/vss-extension.dev.json`/`vss-extension.json` com `ms.vss-work-web.work-item-form-page` (guia do item) e `ms.vss-web.hub-group`/`ms.vss-web.hub` (folha, aprovações, relatórios, configuração), publisher ainda como placeholder. **Não instalado nem testado em organização real** — IDs de contribution, navegação, tema e permissões continuam pendentes de validação (T012), que só é possível depois que a organização de teste (passo 3) existir.
3. **Integridade de work item**: verificar leitura por usuário e comportamento após exclusão, migração e revogação de acesso.
4. **Fuso e repartição diária**: especificar regra para timers atravessando dias; decisão: criar fatias diárias no fechamento, ligadas a uma sessão de origem. Testar transição histórica de horário de verão.
5. **Hospedagem e LGPD**: infraestrutura escolhida — VPS Hostinger administrada via Coolify 4.3.23, deploy do `api/` por Dockerfile próprio (`api/Dockerfile`, PHP 8.4-fpm + nginx + supervisord, sem migração automática no boot — roda como "Post-deployment command" no Coolify). Imagem construída e testada localmente (Docker): migrações rodam limpas contra Postgres 16, `/up` responde 200, `/api/me` sem sessão responde 401 JSON, `/api/auth/session` valida payload (422) — stack nginx→php-fpm→Laravel funcionando de ponta a ponta fora do `artisan serve` de desenvolvimento. **Ainda pendente**: região/datacenter específico da VPS, contrato de retenção, política de exclusão/exportação de dados e suporte — nada disso está decidido só por escolher a ferramenta de deploy, e segue bloqueando publicação pública (CL-003).
6. **Modelo comercial**: venda pública, cobrança/licenciamento próprios e administração de licenças fora do MVP.

## Alternativas avaliadas

- Apenas campos personalizados do work item: simples para horas agregadas, insuficiente para histórico por pessoa, dia e aprovação.
- Somente armazenamento da extensão: possível para dados pequenos, mas complexo para agregações transacionais e relatórios neste cenário.
- Sincronizar `Completed Work`: adiado, pois diferentes processos do Azure DevOps podem usar o campo de forma distinta.

Referência competitiva: https://marketplace.microsoft.com/en-il/product/7pacegmbh.timetracker . Usar apenas recursos públicos para definir escopo; não copiar elementos protegidos ou implementação.
