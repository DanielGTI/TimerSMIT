# SMIT TimeTrack Constitution

## Core Principles

### I. Dados de tempo são auditáveis
Cada lançamento e decisão de aprovação deve manter identidade estável, autoria, data, revisão e histórico. Exclusão é lógica. A soma deve usar duração persistida, sem arredondamentos acumulados.

### II. Isolamento por organização e menor privilégio
Toda operação de leitura e escrita é autorizada no servidor com organização, projeto, usuário e papel. Escopos do Azure DevOps devem ser os mínimos necessários. Testes de isolamento entre tenants e projetos são obrigatórios.

### III. Histórias entregáveis e verificáveis
Cada história de usuário deve ter teste independente e critérios observáveis. Alterações devem preservar fluxo de timer, folha e aprovação já entregue.

### IV. Fonte única para horas
O serviço de lançamentos é a fonte de verdade. Campos nativos de esforço do Azure DevOps não são sincronizados no MVP. Uma futura sincronização requer regra explícita de reconciliação e dupla contagem.

### V. Privacidade desde o desenho
Armazenar só dados necessários. Nunca registrar tokens em logs. Configurar retenção e exportação; verificar requisitos de LGPD e localidade antes da oferta pública.

## Quality Gates

Antes de implementação: revisão de ambiguidades, riscos de autenticação e matriz de permissões. Antes do piloto: testes de concorrência, autorização, datas/fusos, recuperação e exportação. Antes de publicação: revisão de segurança, documentação, suporte e privacidade.

## Scope Governance

Este pacote especifica apenas o MVP para Azure DevOps Services. Banco de horas, controle de ponto, faturamento e suporte ao Server local exigem especificações próprias. Decisões assumidas são registradas em `research.md`; mudanças em escopo ou regra de negócio exigem atualização de `spec.md`, `plan.md` e `tasks.md`.

## Governance

A constituição prevalece sobre convenções de implementação. Revisar a cada mudança estrutural do produto. Versão: 1.0.0 | Ratificada: 2026-09-28 | Última alteração: 2026-09-28.
