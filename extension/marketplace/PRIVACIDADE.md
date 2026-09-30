# Política de privacidade — Controle de horas

> **RASCUNHO para revisão jurídica.** Os trechos marcados com «DECIDIR» dependem de decisões ainda não tomadas (CL-003 da especificação) e precisam ser preenchidos antes de qualquer publicação. Não publique este texto como está.

Última atualização: «DATA»

## Quem é responsável

«DECIDIR: razão social, CNPJ e contato do responsável pelo serviço». Para cada organização que instala a extensão, os dados de horas pertencem à própria organização; o serviço os processa em nome dela.

Contato para privacidade e exclusão de dados: «DECIDIR: e-mail».

## O que é coletado

Quando uma pessoa usa a extensão, o serviço guarda:

| Dado | Para quê |
| --- | --- |
| Identificador e nome da organização no Azure DevOps; identificador do diretório (Entra ID) | separar os dados de cada organização |
| Identificador interno e **nome de exibição** da pessoa no Azure DevOps | saber de quem é cada lançamento e mostrar nas aprovações e nos relatórios |
| Identificador e nome dos projetos | agrupar lançamentos e permissões |
| Número, **título** e tipo dos work items em que há horas lançadas | mostrar no relatório o que foi trabalhado |
| Lançamentos: data, fuso, duração, atividade, faturável, origem (timer ou manual) e **comentário** digitado pela pessoa | finalidade principal do produto |
| Envios de semana, decisões de aprovação e as **justificativas** escritas por quem aprova | fluxo de aprovação e histórico |
| Papéis e designações de aprovador | controle de acesso |
| Trilha de auditoria: quem fez o quê e quando (criar, editar e excluir lançamento; enviar, aprovar, rejeitar e reabrir semana; exportar relatório; alterar configuração) | segurança e prestação de contas |

## O que não é coletado

- O **e-mail não é coletado** nem armazenado.
- Não lemos o conteúdo dos work items além do número, do título e do tipo, que a extensão recebe da própria tela aberta.
- A extensão não usa token de acesso à API do Azure DevOps. Usa apenas o token de identidade fornecido pela plataforma para provar quem a pessoa é, e o serviço o valida sem chamar o Azure DevOps.
- Não há cookies, rastreadores, publicidade nem análise de comportamento. A extensão não guarda dados no navegador.

## Com quem compartilhamos

Com ninguém fora da infraestrutura que executa o serviço: «DECIDIR: provedor de hospedagem e região — hoje, um VPS da Hostinger gerenciado pelo Coolify; confirmar a localização». Não vendemos nem cedemos dados a terceiros. Gerentes, aprovadores e administradores da **mesma organização** veem os lançamentos dentro do escopo do papel que têm.

## Por quanto tempo guardamos

«DECIDIR: prazo de retenção dos lançamentos e da auditoria». Hoje, remover a extensão **não apaga** os dados automaticamente: o acesso é bloqueado e os dados ficam até o fim do prazo acima ou até o pedido de exclusão.

Backups: «DECIDIR: retenção dos backups» (proposta: 14 diários e 8 semanais, depois descartados).

## Seus direitos

A pessoa pode pedir acesso, correção, exportação e exclusão dos próprios dados, e a organização pode pedir a exclusão de todos os dados dela, pelo contato acima. Lançamentos de semanas aprovadas e a trilha de auditoria podem ser mantidos pelo prazo necessário ao cumprimento de obrigações da organização; isso será informado no atendimento. «DECIDIR: prazo de resposta, base legal (LGPD) e encarregado pelo tratamento de dados, se houver».

## Segurança

Comunicação por HTTPS; isolamento entre organizações verificado por testes automatizados; cada pessoa só lê os próprios lançamentos, salvo quem tem papel de aprovação, gestão ou administração; sessões de curta duração; tokens e cabeçalhos de autorização não são gravados em log; backups com restauração testada.

## Mudanças nesta política

«DECIDIR: como e com que antecedência os clientes serão avisados».
