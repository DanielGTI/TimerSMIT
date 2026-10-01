# Aviso de dados — Controle de horas (uso interno da SMIT)

A extensão é de uso interno e privada. Este texto diz o que o sistema guarda sobre quem o usa.

## O que é guardado

| Dado | Para quê |
| --- | --- |
| Identificador e nome da organização no Azure DevOps; identificador do diretório (Entra ID) | separar os dados de cada organização |
| Identificador interno e **nome de exibição** da pessoa no Azure DevOps | saber de quem é cada lançamento e mostrar nas aprovações e nos relatórios |
| Identificador e nome dos projetos | agrupar lançamentos e permissões |
| Número, **título** e tipo dos work items em que há horas lançadas | mostrar nos relatórios o que foi trabalhado |
| Lançamentos: data, fuso, duração, atividade, faturável, origem (timer ou manual) e **comentário** digitado pela pessoa | finalidade principal do sistema |
| Envios de semana, decisões de aprovação e as **justificativas** de quem aprova | fluxo de aprovação e histórico |
| Papéis e designações de aprovador | controle de acesso |
| Trilha de auditoria: quem fez o quê e quando (criar, editar e excluir lançamento; enviar, aprovar, rejeitar e reabrir semana; exportar relatório; alterar configuração) | segurança e prestação de contas |

## O que não é guardado

- O **e-mail não é coletado** nem armazenado.
- Do work item, só o número, o título e o tipo, recebidos da própria tela aberta.
- A extensão não usa token de acesso à API do Azure DevOps; usa só o token de identidade da plataforma, validado pelo serviço sem chamar o Azure DevOps.
- Não há cookies, rastreadores nem análise de comportamento, e nada fica guardado no navegador.

## Quem vê o quê

Cada pessoa vê os próprios lançamentos. Aprovadores, gerentes e administradores veem os lançamentos dentro do escopo do papel que têm. Os dados não são compartilhados com terceiros e ficam na infraestrutura da SMIT (VPS gerenciado pelo Coolify).

## Retenção

«DECIDIR: por quanto tempo guardar lançamentos, auditoria e backups.» Proposta para os backups: 14 diários e 8 semanais. Remover a extensão não apaga os dados automaticamente.

## Segurança

Comunicação por HTTPS; isolamento entre organizações verificado por testes automatizados; sessões de curta duração; tokens e cabeçalhos de autorização não são gravados em log; backups com restauração testada (ver `api/OPERACAO.md`).
