# Controle de horas

Registre, envie e aprove horas de trabalho sem sair do Azure DevOps. O lançamento nasce no work item, a semana vira uma folha que vai para aprovação, e os relatórios saem com os mesmos números que foram aprovados.

## O que você faz com ele

**No work item** — aba *Controle de horas*
- Inicie e pare um cronômetro no work item em que você está trabalhando; o tempo vira um lançamento com a data e o fuso do momento.
- Ou lance horas manualmente (duração, data, atividade, faturável e comentário).

**Folha semanal**
- Veja a semana (segunda a domingo) e o mês, com totais por dia e por work item.
- Edite ou exclua lançamentos enquanto a semana estiver aberta.
- Envie a semana para aprovação quando terminar.

**Aprovações**
- Quem aprova vê as semanas pendentes, os lançamentos e o histórico de decisões.
- Aprove ou rejeite com justificativa; a pessoa corrige e reenvia sem perder o histórico.
- Semanas aprovadas ficam travadas; só um administrador reabre, com motivo registrado.

**Relatórios**
- Filtre por período, pessoa, projeto, atividade, faturável e estado da semana.
- Totais, quebras por pessoa, projeto e atividade, e exportação em CSV com exatamente as mesmas linhas da tela.

**Configuração** (administradores)
- Regras de lançamento: incremento de duração, limite diário, janela retroativa e comentário obrigatório. Cada mudança vale daqui para a frente e não altera horas já lançadas.
- Habilitar ou desabilitar projetos e atividades (com cor e faturável padrão).
- Papéis (membro, aprovador, gerente, administrador) e quem aprova as horas de quem.

## Como começar

1. Um administrador da organização instala a extensão.
2. A primeira pessoa a abrir a extensão na organização vira administradora.
3. Em *Configuração → Pessoas e papéis*, o administrador libera quem vai usar e define os aprovadores.
4. Abra qualquer work item, vá na aba *Controle de horas* e inicie o cronômetro.

## Acesso, permissões e dados

- Cada pessoa vê **apenas os próprios lançamentos**. Gerentes e administradores veem os de quem está sob o escopo deles. Organizações diferentes nunca enxergam os dados umas das outras.
- Os lançamentos ficam no serviço de backend da extensão, não no Azure DevOps. A política de privacidade descreve o que é guardado, por quanto tempo e como pedir exclusão.
- O tema claro e o escuro do Azure DevOps são acompanhados, e os controles funcionam pelo teclado.

## Suporte

Dúvidas, problemas e pedidos: veja a página de suporte da extensão.
