# Feature Specification: Controle de horas integrado ao Azure DevOps

**Feature Branch**: `001-azure-devops-timetracker`  
**Created**: 2026-09-28  
**Status**: Draft  
**Input**: Extensão de registro e aprovação de horas semelhante, em escopo, ao 7pace Timetracker; sem reprodução da marca ou interface.

## User Scenarios & Testing (mandatory)

### User Story 1 — Registrar horas no work item (Priority: P1)

Como colaborador, quero iniciar/parar um cronômetro ou registrar manualmente tempo em um work item, para atribuir esforço ao trabalho realizado.

**Why this priority**: Sem lançamentos confiáveis, as demais funções não geram valor.  
**Independent Test**: Instalar a extensão em uma organização de teste, abrir um work item e registrar 90 minutos; consultar o lançamento e seu histórico.

**Acceptance Scenarios**:

1. **Given** item acessível e sem timer ativo, **When** o usuário inicia e para o timer, **Then** um único lançamento com duração calculada no servidor aparece no item e na folha do dia.
2. **Given** timer ativo, **When** a pessoa fecha e reabre o navegador, **Then** o timer permanece ativo e seu estado é recuperado.
3. **Given** duas solicitações simultâneas para iniciar, **When** ambas chegam ao servidor, **Then** somente uma sessão fica ativa.
4. **Given** semana aberta e item elegível, **When** informa data, duração, atividade e comentário válido, **Then** cria lançamento manual auditado.
5. **Given** item sem acesso ou projeto não habilitado, **When** tenta lançar tempo, **Then** a ação é negada sem revelar dados do item.

### User Story 2 — Consultar e enviar folha semanal (Priority: P1)

Como colaborador, quero ver meus lançamentos agrupados por dia e item e enviar minha semana para aprovação.

**Why this priority**: Completa o ciclo operacional e permite identificar omissões.  
**Independent Test**: Criar lançamentos em dois dias e dois itens, conferir totais e enviar semana; verificar bloqueio de edição.

**Acceptance Scenarios**:

1. **Given** lançamentos em dias distintos, **When** abre a semana, **Then** vê entradas e totais diários/semanais consistentes com os detalhes.
2. **Given** semana aberta, **When** envia, **Then** o estado passa a `enviada`, registra instante e versão dos lançamentos e bloqueia edição.
3. **Given** semana atravessando mês, **When** consulta visão mensal, **Then** horas são atribuídas às datas locais corretas sem mudar unidade de aprovação.

### User Story 3 — Aprovar ou rejeitar a semana (Priority: P1)

Como aprovador, quero revisar semanas atribuídas a mim e aprovar ou rejeitar com justificativa, para consolidar horas confiáveis.

**Why this priority**: É necessário para fechamento e relatórios aprovados.  
**Independent Test**: Submeter uma semana, rejeitar com motivo, corrigir, reenviar e aprovar; conferir bloqueio e auditoria.

**Acceptance Scenarios**:

1. **Given** semana enviada e aprovador designado, **When** aprova, **Then** semana e lançamentos ficam bloqueados, com autor e data da decisão.
2. **Given** semana enviada, **When** rejeita sem motivo, **Then** o sistema exige justificativa.
3. **Given** semana rejeitada, **When** colaborador corrige e reenvia, **Then** a decisão anterior permanece no histórico e nova revisão é apresentada ao aprovador.
4. **Given** gestor de outro projeto, **When** solicita dados fora de sua atribuição, **Then** recebe acesso negado.

### User Story 4 — Consultar relatórios e exportar CSV (Priority: P2)

Como gestor ou auditor, quero filtrar horas por período, pessoa, projeto, work item, atividade, faturável e estado para acompanhar trabalho e exportar os dados autorizados.

**Why this priority**: Permite gestão e uso administrativo após o fluxo de registro.  
**Independent Test**: Criar lançamentos com diferentes estados e projetos; aplicar filtros e exportar; comparar linhas e totais permitidos.

**Acceptance Scenarios**:

1. **Given** horas abertas e aprovadas, **When** filtra `aprovadas`, **Then** vê somente lançamentos aprovados e totais correspondentes.
2. **Given** filtro aplicado, **When** exporta CSV, **Then** arquivo contém mesmas linhas acessíveis e a operação é auditada.
3. **Given** gestor limitado a um projeto, **When** combina filtros ou altera parâmetros de URL, **Then** não acessa horas de outros projetos.

### User Story 5 — Configurar organização e regras (Priority: P2)

Como administrador, quero definir projetos elegíveis, aprovadores, atividades, fuso e regras de lançamento, para alinhar o produto à operação da empresa.

**Why this priority**: Configuração básica é necessária para piloto em mais de uma organização.  
**Independent Test**: Alterar uma regra e verificar que novas operações seguem a regra sem reclassificar horas históricas.

**Acceptance Scenarios**:

1. **Given** projeto desabilitado, **When** usuário tenta criar tempo, **Then** ação falha.
2. **Given** comentário obrigatório, **When** lançamento vem sem comentário, **Then** servidor rejeita.
3. **Given** fuso alterado, **When** consulta entrada histórica, **Then** ela mantém sua data local original.

### Edge Cases

- Perda de conexão após envio de `stop`: repetir com mesma chave de idempotência sem duplicar lançamento.
- Timer atravessa meia-noite, mês ou mudança histórica de horário de verão: persistir instantes UTC e distribuir duração por data local para folha/relatório; preservar ligação à sessão original.
- Um work item é movido/excluído ou o acesso é revogado: entradas históricas permanecem auditáveis conforme permissão interna, mas novas entradas exigem acesso atual.
- Projeto removido da configuração: preservar histórico; bloquear novos lançamentos.
- Mudança de aprovador durante semana enviada: a decisão histórica mantém quem decidiu; o administrador deve reatribuir pendências com auditoria.
- Dois aprovadores tentam decidir ao mesmo tempo: somente a primeira transição válida se efetiva.
- Sem aprovador configurado: envio é impedido com instrução para administrador.

## Requirements (mandatory)

### Functional Requirements

- **FR-001**: O sistema DEVE identificar tenant, projeto e usuário do contexto autenticado do Azure DevOps e validar cada solicitação no backend.
- **FR-002**: O sistema DEVE permitir um timer ativo por usuário/tenant e iniciar/parar de forma atômica e idempotente.
- **FR-003**: O sistema DEVE permitir lançamento manual com data local, duração positiva, work item, atividade, status faturável e comentário conforme política.
- **FR-004**: O sistema DEVE permitir editar ou excluir logicamente lançamentos próprios enquanto a semana estiver aberta ou rejeitada, e auditar alterações.
- **FR-005**: O sistema DEVE agrupar e somar entradas por data local, semana e mês, usando segundos persistidos antes de formatar horas.
- **FR-006**: O sistema DEVE suportar estados `aberta`, `enviada`, `rejeitada`, `aprovada`, com transições autorizadas e histórico de decisões.
- **FR-007**: O sistema DEVE bloquear edição de semana enviada/aprovada; reabertura administrativa de aprovada exige justificativa auditada.
- **FR-008**: O sistema DEVE permitir aprovador designado aprovar ou rejeitar a semana inteira; rejeição exige motivo.
- **FR-009**: O sistema DEVE fornecer relatórios filtrados e CSV com escopo de tenant, projeto e papel idêntico na tela e exportação.
- **FR-010**: O administrador DEVE configurar projetos, aprovadores, fuso, atividades, incremento de duração, limite diário, janela retroativa e comentário obrigatório.
- **FR-011**: O sistema DEVE manter trilha de auditoria para lançamentos, decisões, reaberturas, papéis, políticas e exportações.
- **FR-012**: A extensão DEVE apresentar guia no work item e páginas de folha, aprovações e relatórios no contexto permitido.
- **FR-013**: O sistema NÃO DEVE escrever automaticamente campos nativos de esforço do work item no MVP.
- **FR-014**: O produto DEVE oferecer interface em pt-BR, suporte a teclado e tema claro/escuro. (en-US fora do escopo: uso interno da SMIT — decisão de 30/09/2026.)
- **FR-015**: O backend DEVE negar toda leitura/escrita que não passe pela autorização por tenant/projeto/papel, inclusive acesso direto a IDs conhecidos.

### Key Entities

- **Organização**: tenant identificado por ID estável do Azure DevOps; configurações e estado.
- **Projeto**: projeto habilitado, vinculado ao tenant.
- **Membro/Atribuição de papel**: identidade estável e direitos por organização/projeto.
- **Work item snapshot**: ID e metadados mínimos no momento do lançamento.
- **Sessão de timer**: início, fim, dono e item, com estado ativo/fechado.
- **Lançamento**: data local, fuso, segundos, origem, atividade, faturável, comentário, revisão e exclusão lógica.
- **Semana enviada/Decisão**: período, versão, estado, aprovador, motivo e instantes.
- **Política/Atividade/Auditoria**: regras, categorias e eventos rastreáveis.

## Success Criteria (mandatory)

- **SC-001**: Usuário inicia o cronômetro em até três ações desde um work item aberto.
- **SC-002**: 100% das mutações de lançamento e aprovação no piloto geram evento de auditoria consultável.
- **SC-003**: Nenhuma entrada duplicada é criada em teste de repetição e concorrência das operações de timer.
- **SC-004**: Em cenário de referência com 500 lançamentos, p95 de abrir semana é inferior a 3 s e p95 de iniciar/parar é inferior a 2 s.
- **SC-005**: Todos os cenários de teste de isolamento entre dois tenants e dois projetos retornam somente dados autorizados.
- **SC-006**: CSV e visualização apresentam as mesmas linhas e totais para filtros e permissões iguais.

## Assumptions

Azure DevOps Services cloud no MVP; organização equivale a tenant; interface pt-BR/en-US; semana ISO de segunda a domingo por padrão; aprovação da semana inteira; cada lançamento pertence a um usuário; sem banco de horas, ponto eletrônico, faturamento, sincronização de campos de esforço ou Server local. Os limites propostos são 1 minuto de incremento, 24 horas/dia e 30 dias retroativos, configuráveis. Metas de desempenho são hipóteses a medir no piloto.

## Clarifications Required

- **CL-001** *(resolvida em 30/09/2026)*: produto **interno da SMIT**, em pt-BR e com extensão **privada** (sem listagem pública no Marketplace). Não há venda nem gates de publicação pública.
- **CL-002**: Aprovador por projeto, gestor funcional ou configuração híbrida? Proposta inicial: um aprovador designado por usuário/projeto/semana, resolvido no envio.
- **CL-003** *(parcial)*: hospedagem no VPS da SMIT (Coolify), sem residência contratual de terceiros. Falta definir internamente o prazo de retenção de lançamentos, auditoria e backups.
- **CL-004**: Caminho exato de autenticação segura da extensão com API própria: validar em prova técnica antes de implementar dados reais.

## Out of Scope

Banco de horas, folha salarial, ponto eletrônico, monitoramento de tela, geração de fatura, app móvel/desktop, API pública e Azure DevOps Server local. Esses temas entram em especificações futuras.
