# Data Model: Controle de horas

Todas as tabelas de negócio usam UUID e `tenant_id`. Identidade de usuário e projeto referencia IDs estáveis recebidos do Azure DevOps. Banco sugerido: PostgreSQL. Campos de auditoria (`created_at`, `updated_at`) em todas as tabelas mutáveis.

| Entidade | Campos essenciais | Integridade |
| --- | --- | --- |
| `tenants` | id, devops_organization_id único, region, timezone, status | organização única; estado ativo/suspenso |
| `projects` | id, tenant_id, devops_project_id, enabled | único por tenant/projeto |
| `members` | id, tenant_id, devops_identity_id, display_name_snapshot, active | único por tenant/identidade |
| `role_assignments` | tenant_id, member_id, project_id opcional, role, valid_from, valid_to | papéis efetivos com projeto opcional |
| `activity_types` | tenant_id, name, enabled, default_billable | histórico preservado após desabilitar |
| `policies` | tenant_id, week_start, timezone, minute_increment, max_daily_seconds, backdate_days, comment_required | versão de política para histórico |
| `work_item_snapshots` | tenant_id, project_id, devops_work_item_id, title, type, captured_at | snapshot sem reescrever histórico |
| `timer_sessions` | tenant_id, member_id, work_item_id, started_at_utc, ended_at_utc, status, idempotency_key | índice único parcial para um ativo por tenant/membro |
| `time_entries` | tenant_id, project_id, member_id, work_item_id, timer_session_id opcional, local_date, timezone, duration_seconds, source, activity_type_id, billable, note, revision, deleted_at | duração > 0; índice tenant/membro/data e tenant/projeto/data |
| `weekly_submissions` | tenant_id, member_id, week_start_date, status, revision, submitted_at, approved_at, approver_id | única submissão corrente por pessoa/semana/tenant; histórico de revisões |
| `approval_decisions` | tenant_id, submission_id, revision, approver_id, decision, reason, decided_at | rejeição exige motivo |
| `audit_events` | tenant_id, actor_id, entity_type, entity_id, action, before_json, after_json, occurred_at, request_id | append-only em nível de aplicação |
| `export_jobs` | tenant_id, requested_by, filters_json, status, row_count, created_at, expires_at | arquivo temporário com acesso autorizado |

## State Transitions

- Timer: `active → stopped`; a operação `stop` cria uma ou mais fatias diárias e nunca reabre a sessão.
- Semana: `open → submitted → approved`; ou `submitted → rejected → submitted`. Reabertura de aprovada exige `approved → open` somente pelo administrador, com justificativa e nova revisão.
- Lançamento: edição só em semana `open`/`rejected`. Remoção é lógica. Histórico de revisões é preservado em `audit_events`.

## Temporal Semantics

Instantes persistidos em UTC; dia local e fuso IANA congelados no lançamento. Timer que atravessa meia-noite é particionado por fronteiras de dia local, sem perder segundos: soma das fatias = fim menos início. Semana começa segunda-feira por padrão, configurável apenas para períodos futuros. Somatórios usam segundos inteiros, apresentação arredondada só no final.

## Authorization and Deletion

Toda FK lógica inclui tenant; consultas são filtradas e autorizadas no servidor. Não depender apenas de row level filtering no cliente. Exclusão da instalação não apaga dados automaticamente até a política contratual de retenção ou solicitação de exclusão; bloqueia novos acessos e preserva exportação administrativa conforme política a definir.
