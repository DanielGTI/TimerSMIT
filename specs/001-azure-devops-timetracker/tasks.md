# Tasks: Controle de horas integrado ao Azure DevOps

**Input**: `spec.md`, `plan.md`, `research.md`, `data-model.md`, `contracts/openapi.yaml`  
**Format**: `[ID] [P?] [US?] tarefa com caminho de arquivo`. `[P]` indica arquivos independentes; `USn` refere-se à história da especificação. Tarefas obrigatórias de teste verificam riscos concretos: tenant, concorrência, estados e fuso.

## Phase 1: Setup

- [x] T001 Criar `extension/` e `api/` conforme `plan.md`; manter apenas placeholders de ambiente em `.env.example`, sem segredos.
- [x] T002 [P] Inicializar React/TypeScript e SDK em `extension/package.json`, `extension/src/lib/devops/`.
- [x] T003 [P] Inicializar Laravel e PostgreSQL em `api/composer.json`, `api/config/database.php`.
- [x] T004 [P] Configurar lint/build e pipeline privado em `extension/`, `api/` e `.azure-pipelines/`.
- [x] T005 Criar manifests distintos `extension/vss-extension.dev.json` e `extension/vss-extension.json`, somente com permissões justificadas. Publisher `TrackerSMIT` configurado nos dois manifests; empacotamento com `tfx-cli` confirmado.

## Phase 2: Foundational — blocks user stories

- [x] T006 Provar identidade verificável da extensão com backend em `extension/src/lib/auth/`, `api/app/Http/Middleware/`; documentar emissor, audiência, tenant, expiração e revogação em `specs/001-azure-devops-timetracker/research.md`. **Gate fechado**: validado ponta a ponta contra a organização real `smitbr` em 2026-09-29 — token real decodificado, arquitetura corrigida (verificação local do JWT HS256 da extensão, não chamada a `connectionData`), sessão emitida e aceita. Só revogação por desinstalação segue pendente.
- [x] T007 Implementar migrações `tenants`, `projects`, `members`, `role_assignments`, `policies` em `api/database/migrations/`.
- [x] T008 Implementar contexto tenant e políticas de projeto/papel em `api/app/Http/Middleware/` e `api/app/Policies/`.
- [x] T009 Implementar autenticação da API e respostas 401/403/409/422 em `api/routes/api.php` e `api/app/Http/`.
- [x] T010 Implementar eventos de auditoria append-only em `api/app/Services/AuditService.php` e migração correspondente.
- [x] T011 Testar que usuários de dois tenants e dois projetos não se cruzam em `api/tests/Feature/TenantIsolationTest.php`.
- [x] T012 Validar guia e hubs em `extension/vss-extension.dev.json` numa organização privada e documentar IDs confirmados em `research.md`. Guia do work item (`work-item-guide` / `ms.vss-work-web.work-item-form-page`) e os 4 hubs (`timesheet-hub`, `approvals-hub`, `reports-hub`, `settings-hub`, agrupados em `hub-group`) confirmados funcionando em `smitbr` — todos aparecem no menu do projeto sob "Controle de horas", carregam sem erro e estabelecem sessão real com o backend.

**Checkpoint**: autenticação, RBAC, auditoria e contributions aptos ao piloto. ✅ Fase Foundational concluída em 2026-09-30.

## Phase 3: User Story 1 — Registro de horas (P1)

**Goal**: registrar timer e entrada manual em work item.  
**Independent Test**: abrir item, iniciar/parar timer e lançar 90 minutos manuais; conferir histórico e negação fora de escopo.

- [x] T013 [US1] Criar migrações/modelos `timer_sessions`, `time_entries`, `work_item_snapshots`, `activity_types` em `api/database/migrations/` e `api/app/Models/`.
- [x] T014 [US1] Implementar leitura/autorização de work item em `extension/src/lib/devops/workItems.ts` e `api/app/Services/WorkItemAccessService.php`. Projeto é resolvido/conhecido na primeira chamada (por `devops_project_id`), mas só concede acesso com `RoleAssignment` existente — bootstrap: primeira pessoa a conectar uma organização nova vira admin (`IdentityProvisioningService`), senão ninguém teria papel para conceder acesso a mais ninguém antes de US5/configuração existir.
- [x] T015 [US1] Implementar transações, índice de um timer ativo, idempotência e fechamento em `api/app/Services/TimerService.php`. Índice parcial (`WHERE status = 'active'`) garante um timer ativo por membro mesmo sob corrida; idempotência cobre `start` via `Idempotency-Key`.
- [x] T016 [US1] Implementar fatias diárias UTC/fuso local em `api/app/Services/TimeSplitService.php`. Fuso vem de `tenants.default_timezone` (não há campo de fuso em `policies` na migração real, diferente do resumo de `data-model.md`).
- [x] T017 [US1] Implementar validações e mutações manuais em `api/app/Services/TimeEntryService.php` — comentário obrigatório, limite diário e janela retroativa a partir da política vigente (projeto > tenant > padrões da migração se nenhuma política existir ainda).
- [x] T018 [US1] Expor endpoints de timer/entries em `api/routes/api.php`, `api/app/Http/Controllers/TimerController.php` e `TimeEntryController.php`. `contracts/openapi.yaml` atualizado (IDs internos não são UUID; `projectId` no corpo é o GUID do Azure DevOps, resolvido para o `Project` interno).
- [x] T019 [US1] Construir guia do item em `extension/src/pages/work-item/` (`WorkItemGuide`, `TimerPanel`, `ManualEntryForm`): timer com atividade e relógio ao vivo, aviso de timer ativo em outro item, e formulário de lançamento no formato do "Add time" do 7pace (data, duração HH:MM + atalhos, De/Até como calculadora, atividade com cor, comentário, faturável). Estilo em `src/styles/theme.css`, acompanhando o tema claro/escuro do Azure DevOps. Tipos de atividade: `GET /api/activity-types` (conjunto padrão criado na primeira listagem do tenant; `activityTypeId` validado contra o tenant).
- [x] T020 [US1] Testar repetição, corrida de timer, meia-noite e revogação de acesso em `api/tests/Feature/TimerTest.php` (7 testes) e `api/tests/Feature/EntryAuthorizationTest.php` (10 testes); UI coberta em `extension/tests/work-item-guide.test.tsx` (4 testes).

**Checkpoint**: US1 demonstrável sem folha/aprovação. ✅ Concluído em 2026-09-30 (29 testes backend, 7 testes frontend, todos passando).

## Phase 4: User Story 2 — Consultar e enviar folha semanal (P1)

**Goal**: consultar e enviar a própria semana.
**Independent Test**: entradas em dias diferentes, soma e envio com bloqueio.

- [x] T021 [US2] Criar `weekly_submissions` e histórico de revisões em `api/database/migrations/` e `api/app/Models/`. Uma linha por pessoa/semana/tenant (a linha só existe a partir do primeiro envio; sem linha = aberta) e `weekly_submission_revisions` append-only com a versão dos lançamentos (`entries_snapshot`) de cada envio. `approver_id` já existe, nulo até a designação de aprovadores (US3/US5).
- [x] T022 [US2] Implementar soma por data local e transição `open → submitted` em `api/app/Services/TimesheetService.php`, mais `WeekLockGuard` (bloqueio de edição). Somas em segundos inteiros no servidor. Envio recusa semana vazia (422) e timer ativo (409), é idempotente por `Idempotency-Key` e auditado (`week.submitted`). **Decisão**: o bloqueio "sem aprovador" (edge case da spec, 409 no contrato) **não** é aplicado aqui — depende da designação de aprovadores (T026/T027/T035); entra com a US3.
- [x] T023 [US2] Expor consulta/envio de semana em `api/app/Http/Controllers/TimesheetController.php`: `GET /me/weeks/{segunda}`, `POST /me/weeks/{segunda}/submit`, `GET /me/months/{yyyy-mm}`. Contrato atualizado.
- [x] T024 [US2] Construir grade diária/semanal e resumo mensal em `extension/src/pages/timesheet/`: grade por work item e dia com totais, lançamentos com editar/excluir (FR-004), envio com confirmação, navegação de semanas e calendário mensal com o estado de cada semana.
- [x] T025 [US2] Testar totais, semana atravessando mês, concorrência e edição bloqueada em `api/tests/Feature/TimesheetTest.php` (23 testes) e `extension/tests/timesheet-page.test.tsx`. Concorrência: edição e envio travam a mesma linha do membro, então um vence e o outro recebe 409; validado em PostgreSQL (`api/docker-compose.test.yml`). Não há teste com duas conexões simultâneas de verdade.

**Correções feitas junto** (achadas pelos testes da US2): `PATCH/DELETE /entries/{id}` não verificavam o dono — qualquer membro do tenant editava lançamento de outro (agora só o dono, 404 para os demais); sessão inválida/expirada devolvia 500 em vez de 401 (a extensão só renova sessão em 401).

**Checkpoint**: US2 demonstrável com registros da US1. ✅ Validada ao vivo em `smitbr` em 2026-09-30.

## Phase 5: User Story 3 — Aprovação (P1)

**Goal**: aprovador decide semana com histórico e controle de acesso.  
**Independent Test**: rejeitar, corrigir, reenviar e aprovar.

- [ ] T026 [US3] Criar `approval_decisions` e designações em `api/database/migrations/` e `api/app/Models/`.
- [ ] T027 [US3] Implementar regras de atribuição, rejeição com motivo, aprovação atômica e reabertura administrativa em `api/app/Services/ApprovalService.php`.
- [ ] T028 [US3] Expor lista/decisão de semanas em `api/app/Http/Controllers/ApprovalController.php`.
- [ ] T029 [US3] Construir caixa de aprovações em `extension/src/pages/approvals/`.
- [ ] T030 [US3] Testar rejeição, reenvio, concorrência, gestor sem acesso e reabertura auditada em `api/tests/Feature/ApprovalTest.php`.

**Checkpoint**: ciclo registro → folha → aprovação completo.

## Phase 6: User Story 4 — Relatórios (P2)

**Goal**: consulta e CSV com o mesmo escopo de acesso.  
**Independent Test**: aplicar filtro, comparar CSV e tela, tentar acesso cruzado.

- [ ] T031 [US4] Implementar consulta filtrada/agregada em `api/app/Services/TimeReportService.php` e índices necessários.
- [ ] T032 [US4] Implementar CSV UTF-8 e evento de auditoria em `api/app/Http/Controllers/ReportController.php`.
- [ ] T033 [US4] Construir filtros e relatório em `extension/src/pages/reports/`.
- [ ] T034 [US4] Testar igualdade CSV/tela, status aprovado e autorização por projeto em `api/tests/Feature/ReportTest.php`.

## Phase 7: User Story 5 — Configuração (P2)

**Goal**: administrador configura políticas e responsáveis para períodos futuros.  
**Independent Test**: alterar regra e verificar lançamento novo, sem reclassificar entrada histórica.

- [ ] T035 [US5] Implementar configurações, versões e atribuição de aprovadores em `api/app/Services/OrganizationSettingsService.php`.
- [ ] T036 [US5] Expor configurações em `api/app/Http/Controllers/SettingsController.php` com papel administrador.
- [ ] T037 [US5] Construir tela de configuração em `extension/src/pages/settings/`.
- [ ] T038 [US5] Testar comentário obrigatório, projeto desabilitado, limites e imutabilidade da data histórica em `api/tests/Feature/SettingsTest.php`.

## Phase 8: Polish & Release

- [ ] T039 Revisar tradução, teclado, contraste e temas em `extension/src/`.
- [ ] T040 Executar carga para metas SC-004 e índices em `api/tests/Performance/`; registrar ambiente e resultados.
- [ ] T041 Validar restauração de backup, logs sem tokens e métricas/alertas em `api/` e infraestrutura.
- [ ] T042 Preparar descrição, ícone, screenshots, suporte e política de privacidade em `extension/marketplace/`.
- [ ] T043 Empacotar VSIX de desenvolvimento, instalar privadamente e executar `quickstart.md` na organização de teste.
- [ ] T044 Resolver CL-001–CL-004 e decisão de publicação; preparar versão pública apenas após os gates de constituição.

## Dependencies & Execution Order

Setup → Foundational → US1 → US2 → US3 → piloto operacional; US4 e US5 podem ser desenvolvidas após Fundacional, mas ambas precisam respeitar permissões e entidades das histórias anteriores. Fechar cada checkpoint com seus cenários de aceitação antes de avançar. `T006` é bloqueador de autenticação, `T011` é bloqueador de isolamento e `T020/T025/T030/T034` cobrem riscos centrais.

## Implementation Strategy

Primeira demonstração: T001–T020. Primeiro piloto de operação: T001–T030. MVP completo: T001–T044. Não marcar tarefas como concluídas até código e cenários correspondentes serem verificados.
