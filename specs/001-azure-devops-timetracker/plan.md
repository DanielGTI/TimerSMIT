# Implementation Plan: Controle de horas integrado ao Azure DevOps

**Branch**: `001-azure-devops-timetracker` | **Date**: 2026-09-28 | **Spec**: [spec.md](spec.md)  
**Input**: Feature specification from `specs/001-azure-devops-timetracker/spec.md`.

## Summary

Extensão web no Azure DevOps Services com guia de work item e hubs para folha, aprovações e relatórios. Backend próprio transacional armazena timers, horas e auditoria com isolamento por organização. A prova técnica de autenticação é um bloqueio explícito para a integração com dados reais.

## Technical Context

- **Language/Version**: TypeScript no frontend; PHP 8.4/Laravel 12 no backend (proposta inicial alinhada à equipe; confirmar suporte e versões no início da implementação).
- **Primary Dependencies**: React, `azure-devops-extension-sdk`, `azure-devops-extension-api`, Laravel; tfx-cli para empacotar.
- **Storage**: PostgreSQL para dados transacionais; Redis para fila/cache opcional; extensão nativa para preferências leves.
- **Testing**: Vitest/Testing Library para UI; PHPUnit/Pest para domínio/API; testes de contrato e integração com organização Azure DevOps de teste.
- **Target Platform**: Azure DevOps Services; API Linux/HTTPS; navegador suportado pelo Azure DevOps.
- **Project Type**: extensão web + serviço API multi-tenant.
- **Performance Goals**: iniciar/parar p95 < 2 s; folha semanal p95 < 3 s para 500 lançamentos.
- **Constraints**: um timer por usuário/tenant; autorização no servidor; UTC + data local imutável; auditabilidade; sem escrita em work items.
- **Scale/Scope**: hipótese de 50 organizações, até 500 usuários/organização e 100 mil lançamentos/organização/ano.

## Constitution Check

| Princípio | Gate antes de pesquisa | Gate após desenho |
| --- | --- | --- |
| Auditabilidade | FR-004/006/011 e eventos definidos | Transações e revisão no modelo; teste de mutações |
| Isolamento | FR-001/015 | Middleware + política por projeto + testes de tenants |
| Histórias verificáveis | US1–US5 independentes | Entrega em fatias e critérios em tasks.md |
| Fonte de verdade | FR-013 | Sem atualização de `Completed Work` |
| Privacidade | CL-003 parcial | Uso interno e extensão privada: não há publicação pública; resta definir a retenção |

**Estado do gate:** desenho preliminar aprovado como proposta; autenticação externa, localização e retenção aguardam decisão/prova técnica antes de produção.

## Project Structure

```text
.specify/memory/constitution.md
specs/001-azure-devops-timetracker/
  spec.md
  plan.md
  research.md
  data-model.md
  quickstart.md
  contracts/openapi.yaml
  tasks.md
extension/
  vss-extension.dev.json
  vss-extension.json
  src/pages/{work-item,timesheet,approvals,reports,settings}/
  src/lib/{devops,api,auth}/
  tests/
api/
  app/{Http/Controllers,Models,Policies,Services,Jobs}/
  database/migrations/
  tests/{Feature,Unit}/
```

## Phase 0: Research

Concluir itens em [research.md](research.md): pontos de extensão e escopos; prova técnica de token/autenticação; comportamento de identidade; armazenamento e custos; questões de retenção/região. Não iniciar implementação de dados reais antes da autenticação ser validada.

## Phase 1: Design & Contracts

- Definir tabelas, índices e estados em [data-model.md](data-model.md).
- Validar rascunho de API em [contracts/openapi.yaml](contracts/openapi.yaml), especialmente erros 401/403/409 e idempotência.
- Prototipar guia do work item e hubs; validar IDs de contribution no ambiente de teste.
- Detalhar matriz RBAC: sujeito × ação × tenant × projeto × estado.
- Criar fluxo inicial de [quickstart.md](quickstart.md) e observabilidade.

## Phase 2: Implementation Strategy

1. Setup + autenticação, tenant, políticas e auditoria.
2. US1 timer e lançamento manual com fatia vertical demonstrável.
3. US2 folha e submissão.
4. US3 aprovação.
5. US4 relatórios e CSV.
6. US5 configuração; integrar regras sem mudar dados históricos.
7. Testes de segurança, concorrência, tempo/fuso e carga; piloto privado; ajustes.

## Deployment

Extensão de desenvolvimento privada e separada da produção; API com ambientes isolados; migrações reversíveis; backup antes de alterações; rollout piloto em organização de teste, depois clientes autorizados. Disponibilizar VSIX por publisher e publicar somente após requisitos de suporte, privacidade e avaliação de segurança.

## Complexity Tracking

Backend separado é justificado por transações de timer, auditoria, consultas agregadas e isolamento multi-tenant. Não introduzir serviços adicionais até testes de carga indicarem necessidade. Não usar microserviços no MVP.
