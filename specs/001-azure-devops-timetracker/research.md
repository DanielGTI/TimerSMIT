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

1. **Autenticação extensão → backend** — **T006/CL-004 resolvidos** contra a organização real `smitbr` (2026-09-29), após uma correção de arquitetura:
   - **Tentativa inicial (errada)**: `DevOpsIdentityVerifier` chamava `_apis/connectionData` da Azure DevOps com o `appToken`, supondo que fosse um bearer token válido para a API. Testado ao vivo contra `smitbr`: a chamada voltou HTTP 203 com uma página HTML de sign-in (usuário "Anonymous"), não JSON — confirmando que a suposição estava errada, não só um problema de configuração.
   - **Causa raiz**: a [documentação oficial](https://learn.microsoft.com/en-us/azure/devops/extend/develop/auth?view=azure-devops) (relida depois da falha) deixa claro que `SDK.getAppToken()` não é um token para chamar APIs da Azure DevOps (isso é `SDK.getAccessToken()`, não usado aqui) — é um **JWT HS256 assinado com um segredo simétrico exclusivo da extensão publicada** (Marketplace → extensão → "Certificate"), feito para ser validado **localmente**, sem nenhuma chamada de rede.
   - **Claims confirmados** decodificando um token real emitido para `smitbr` (payload de JWT é texto plano, não precisa do segredo pra ler): `nameid` (GUID do usuário — bateu exatamente com `webContext.user.id`), `tid` (GUID do tenant do Azure AD/Entra — bateu exatamente com a URL de login da tentativa falha), `iss` = `app.vstoken.visualstudio.com`, `aud` = GUID da extensão, `nbf`/`exp` (janela de ~70 min). **O token não carrega o ID da organização do Azure DevOps.**
   - **Mecanismo final**: `DevOpsIdentityVerifier` valida a assinatura HS256 localmente com `AZURE_DEVOPS_EXTENSION_SECRET` (`firebase/php-jwt`) e extrai `nameid`/`tid` como identidade verificada. Organização (`claimedOrganizationId`/`Name`, de `SDK.getHost()`) continua sendo uma afirmação do cliente — mas `IdentityProvisioningService` a subordina ao `tid` verificado na primeira vez que aparece (`Tenant.aad_tenant_id`); uma tentativa de reusar o mesmo ID de organização vindo de um tenant Entra diferente é rejeitada com 409 (`TenantOwnershipMismatchException`), nunca fundida. Nome de exibição (`displayName`, de `SDK.getUser()`) é só cosmético, nunca usado para autorização.
   - **Renovação**: a extensão pede um novo `getAppToken()` e troca por nova sessão quando a atual expira ou um 401 é recebido (`extension/src/lib/api/client.ts`); não há refresh token próprio.
   - **Revogação/instalação-desinstalação**: ainda não coberta — depende de como o Azure DevOps notifica desinstalação de extensão (webhook/evento).
   - **Testado com**: token real assinado, decodificado e verificado manualmente contra a organização `smitbr` (ponta a ponta: work item → aba "Controle de horas" → sessão emitida). Cobertura automatizada em `api/tests/Feature/AuthSessionTest.php` (token válido, assinatura errada, expirado, reuso de organização entre tenants Entra) e `api/tests/Feature/TenantIsolationTest.php`. 11 testes passam via PHP 8.4/Laravel 12 em container Docker.
   - **Lição registrada**: a documentação de terceiros/memória sobre APIs de plataforma deve ser tratada como hipótese até confirmada ao vivo — a suposição inicial parecia razoável e só a prova técnica real (exigida pelo próprio CL-004) revelou o erro.
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
