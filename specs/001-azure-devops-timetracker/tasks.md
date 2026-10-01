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

**Decisões de produto (CL-002)**: aprovador por **designação explícita** (por pessoa, opcionalmente por projeto), resolvida no envio, com **administradores como reserva** (sempre podem decidir). **Autoaprovação: só administrador**, marcada na decisão e na auditoria.

- [x] T026 [US3] Criar `approval_decisions` e designações em `api/database/migrations/` e `api/app/Models/`: `approver_assignments` (designação), `weekly_submission_approvers` (aprovadores resolvidos em cada envio) e `approval_decisions` (append-only: aprovada/rejeitada/reaberta, com revisão, motivo e `self_decision`).
- [x] T027 [US3] Implementar regras de atribuição, rejeição com motivo, aprovação atômica e reabertura administrativa em `api/app/Services/ApprovalService.php` e `ApproverResolver.php`. A linha da submissão é travada: a primeira transição válida vence (as demais recebem 409); decisão idempotente por `Idempotency-Key`, com revisão esperada; rejeição e reabertura exigem motivo; reabrir só aprovada e só admin. O envio passa a exigir alguém que possa decidir (409 com instrução ao administrador) — o bloqueio "sem aprovador" adiado da US2.
- [x] T028 [US3] Expor lista/decisão de semanas em `api/app/Http/Controllers/ApprovalController.php`: `GET /approvals` (pendentes | decididas), `GET /approvals/{id}`, `POST /approvals/{id}/decision`, `POST /approvals/{id}/reopen`. Contrato atualizado; a folha do colaborador passa a trazer o histórico de decisões.
- [x] T029 [US3] Construir caixa de aprovações em `extension/src/pages/approvals/`: lista de pendentes e decididas, detalhe com grade/lançamentos/histórico (somente leitura), aprovar e rejeitar (motivo obrigatório) e reabrir (admin), sempre com confirmação. A folha do colaborador mostra quem rejeitou/aprovou/reabriu, o motivo e o histórico.
- [x] T030 [US3] Testar rejeição, reenvio, concorrência, gestor sem acesso e reabertura auditada em `api/tests/Feature/ApprovalTest.php` (24 testes) e `extension/tests/approvals-page.test.tsx`. Concorrência: duas decisões sobre a mesma semana — a segunda recebe 409 (sem teste com duas conexões simultâneas de verdade).

**Pendente para a US5 (configuração)**: não existe tela nem endpoint para **designar aprovadores** (`approver_assignments` só é preenchida por banco/teste). Enquanto isso, os administradores decidem todas as semanas. Também falta a **reatribuição de semanas pendentes** quando um aprovador muda (edge case da spec).

**Checkpoint**: ciclo registro → folha → aprovação completo. Validação ao vivo em `smitbr` pendente.

## Phase 6: User Story 4 — Relatórios (P2)

**Goal**: consulta e CSV com o mesmo escopo de acesso.
**Independent Test**: aplicar filtro, comparar CSV e tela, tentar acesso cruzado.

**Escopo de acesso (FR-009)**: cada pessoa vê as próprias horas; **gestor** (papel `manager`) vê todas as pessoas nos projetos que gerencia; **administrador** (ou gestor da organização inteira) vê tudo do tenant. Papel `approver` e designação de aprovador **não** dão acesso a relatórios. Filtro de pessoa/projeto fora do escopo responde 403.

- [x] T031 [US4] Implementar consulta filtrada/agregada em `api/app/Services/TimeReportService.php` e índices necessários. Filtros: período, projeto, pessoa, work item, atividade, faturável e estado da semana. `time_entries.week_start_date` (preenchida pelo model; migração com backfill) liga o lançamento ao envio por JOIN; índices `(tenant_id, local_date)` e `(tenant_id, member_id, week_start_date)`.
- [x] T032 [US4] Implementar CSV UTF-8 e evento de auditoria em `api/app/Http/Controllers/ReportController.php`: `GET /reports/time`, `/reports/time.csv`, `/reports/options`. CSV em streaming, UTF-8 com BOM e `;`, textos livres protegidos contra injeção de fórmula; exportação auditada (`report.exported`). **Decisão**: exportação síncrona, sem a tabela `export_jobs` do modelo de dados (só se justifica para arquivos muito grandes); período limitado a 366 dias.
- [x] T033 [US4] Construir filtros e relatório em `extension/src/pages/reports/`: períodos prontos, filtros, cartões de totais, quebras por pessoa/projeto/atividade, tabela paginada e "Exportar CSV" (usa os filtros aplicados na tela).
- [x] T034 [US4] Testar igualdade CSV/tela, status aprovado e autorização por projeto em `api/tests/Feature/ReportTest.php` (22 testes) e `extension/tests/reports-page.test.tsx`: mesmas linhas, ordem e totais em tela e CSV para vários filtros; gestor limitado a um projeto não alcança outros mudando a URL; 403 fora do escopo sem auditar; isolamento entre organizações.

**Checkpoint**: relatórios e CSV com o mesmo escopo. Validação ao vivo em `smitbr` pendente (inclui o download do CSV dentro do iframe do Azure DevOps).

## Phase 7: User Story 5 — Configuração (P2)

**Goal**: administrador configura políticas e responsáveis para períodos futuros.
**Independent Test**: alterar regra e verificar lançamento novo, sem reclassificar entrada histórica.

- [x] T035 [US5] Implementar configurações, versões e atribuição de aprovadores em `api/app/Services/OrganizationSettingsService.php`: fuso; regras de lançamento **versionadas** (cada alteração cria uma versão nova em `policies`, válida daqui para a frente); habilitar/desabilitar projeto; atividades (criar, renomear, cor, faturável padrão, habilitar/desabilitar — sem exclusão); **papéis por pessoa e projeto** (necessários para liberar quem abre a extensão; não remove o último administrador); designação de aprovadores com opção de reatribuir semanas já enviadas e pendentes (fecha o edge case "mudança de aprovador durante semana enviada"). Tudo auditado.
- [x] T036 [US5] Expor configurações em `api/app/Http/Controllers/SettingsController.php` com papel administrador (middleware `admin`; papel `admin` restrito a um projeto não basta). `GET /settings` devolve a visão completa e cada alteração a devolve atualizada. Contrato atualizado.
- [x] T037 [US5] Construir tela de configuração em `extension/src/pages/settings/`: abas Regras, Projetos, Atividades, Pessoas e papéis e Aprovadores; quem não é administrador vê só o motivo da recusa.
- [x] T038 [US5] Testar comentário obrigatório, projeto desabilitado, limites e imutabilidade da data histórica em `api/tests/Feature/SettingsTest.php` (22 testes) e `extension/tests/settings-page.test.tsx`.

**Fechado junto (lacunas da US1)**: o incremento de duração passou a ser aplicado aos lançamentos manuais, e editar um lançamento não contorna mais o limite diário nem o comentário obrigatório. O comentário obrigatório vale para lançamento **manual**; o parar-timer continua aceitando comentário opcional (a tela de timer não pede comentário).

**Fora do escopo desta etapa**: política por projeto (o modelo e a leitura já suportam, mas não há como desfazer uma sobreposição, então não é exposta); criar projetos manualmente (eles aparecem quando alguém abre um work item).

**Checkpoint**: configuração aplicada a novas operações sem reclassificar horas históricas. Validação ao vivo em `smitbr` pendente.

## Phase 8: Polish & Release

- [ ] T039 Revisar tradução, teclado, contraste e temas em `extension/src/`. **Parcial**: feito teclado/foco (abas com setas/Home/End e tabindex móvel em `TabList`; seletor de atividade fecha com Tab e ganha Home/End; contorno de foco em abas, itens, dias do calendário, chips, cor e caixas de seleção), `prefers-reduced-motion` e alvo de 24 px no botão de remover chip. Idioma: **pt-BR fixo por decisão** (uso interno da SMIT; en-US fora do escopo, FR-014 ajustado). Pendente: conferir contraste dos botões primários e badges no tema escuro real do Azure DevOps (só dá para ver com a extensão instalada).
- [x] T040 Executar carga para metas SC-004 e índices em `api/tests/Performance/`; registrar ambiente e resultados.
      `ReferenceScenarioTest` (só com `PERF_RUN=1`): PostgreSQL 17 em contêiner, 50.540 lançamentos (500 da pessoa medida + 100 pessoas × 500), 40 rodadas. Resultado (p50/p95): abrir semana 16/19 ms; abrir mês 12/16 ms; iniciar timer 15/29 ms; parar timer 20/30 ms; relatório de 90 dias do admin 202/336 ms — SC-004 (3 s e 2 s) atendido com folga; os índices existentes bastam. Primeira chamada a frio: até ~1,1 s.
      Achado: a exportação CSV usava `lazy()` (OFFSET) e levou ~22 s para 50 mil linhas; trocada por leitura em blocos por chave (keyset) → ~4,7 s, com teste de fronteira de bloco em `ReportTest`. Medida em contêiner local, não no VPS: repetir em produção antes de tratar como garantia.
- [ ] T041 Validar restauração de backup, logs sem tokens e métricas/alertas em `api/` e infraestrutura. **Parcial**: feito no código e provado localmente; falta a parte no VPS/Coolify.
      Feito: `zend.exception_ignore_args`/`display_errors` no `php.ini`; `GET /api/health` (confere o banco, 503 sem detalhes); log `api.request.failed`/`api.request.slow` só com rota-padrão, status e duração (`LogSlowOrFailedRequests`); `OperationsTest` (5 testes, inclui token fora do log mesmo com exceção dentro da verificação). `scripts/verify-restore.sh`: dump `-Fc` → restauração em banco novo → contagens e checksums idênticos (4.200 lançamentos, 2 empresas) e sem migração pendente. Runbook em `api/OPERACAO.md`.
      Pendente (a configurar por quem administra o VPS): backup agendado no Coolify com destino externo, restauração de teste com um backup real, monitor externo em `/api/health`, alertas por log/disco/memória e limite de retenção dos logs do Docker. Política de RPO/RTO/retenção é proposta, depende de CL-003.
- [x] T042 Preparar descrição, ícone, suporte e aviso de dados em `extension/marketplace/`. **Escopo reduzido** pela decisão de uso interno e extensão privada (sem listagem pública, screenshots, política pública nem links de suporte no manifesto).
      Feito: ícone novo (relógio, 256×256, fonte em `icon.svg`); `overview.md` (página de detalhes da extensão privada, ligada ao `vss-extension.json` de produção junto de tags e cor da marca; empacotamento de produção validado); `PRIVACIDADE.md` como aviso interno de dados (conferido no código: e-mail não é coletado, sem token de acesso ao Azure DevOps, sem armazenamento no navegador); `SUPORTE.md`.
      Retenção definida (30 diários + 12 mensais) e sem canal formal de suporte (decisão de 30/09/2026).
      Achado: o manifesto pede o escopo `vso.work`, mas a extensão não usa token de acesso nem a API REST (só o token de identidade e o form do work item) — provavelmente dá para remover por mínimo privilégio, o que exige novo segredo da extensão e nova autorização na organização. Decidir antes do uso em produção.
- [ ] T043 Empacotar VSIX de desenvolvimento, instalar privadamente e executar `quickstart.md` na organização de teste.
- [x] T044 Resolver CL-001–CL-004 e decisão de publicação. **Resolvido (30/09/2026)**: produto interno da SMIT, pt-BR, extensão privada — sem versão pública e sem gates de publicação pública. CL-002 e CL-004 já decididos antes; CL-003 também resolvida (VPS da SMIT; backups de 30 diários + 12 mensais na Cloudflare).
      Acesso restrito à SMIT: além de a extensão ser privada (compartilhada só com `smitbr`), o backend só abre sessão para identidades do diretório Entra ID da SMIT (`TIMERSMIT_ALLOWED_AAD_TENANTS`, padrão `5517d73c-0aed-49c1-9d7e-0a38889a4fc5`, claim `tid` do token assinado); outro diretório recebe 403 e nenhuma organização/pessoa é criada. Coberto em `AuthSessionTest`.

## Dependencies & Execution Order

Setup → Foundational → US1 → US2 → US3 → piloto operacional; US4 e US5 podem ser desenvolvidas após Fundacional, mas ambas precisam respeitar permissões e entidades das histórias anteriores. Fechar cada checkpoint com seus cenários de aceitação antes de avançar. `T006` é bloqueador de autenticação, `T011` é bloqueador de isolamento e `T020/T025/T030/T034` cobrem riscos centrais.

## Implementation Strategy

Primeira demonstração: T001–T020. Primeiro piloto de operação: T001–T030. MVP completo: T001–T044. Não marcar tarefas como concluídas até código e cenários correspondentes serem verificados.
