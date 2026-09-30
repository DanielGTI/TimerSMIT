# Capturas de tela para a listagem

O Marketplace aceita PNG ou JPG (recomendado 1366×768, sem barras do navegador). **Devem ser capturas reais da extensão instalada**, com dados fictícios: capture na organização de teste (`smitbr`) durante a validação ao vivo (T043). Não use imagens montadas à mão.

Antes de capturar, use nomes e work items fictícios, sem nenhuma informação de cliente, no tema claro (uma versão no tema escuro é opcional).

| # | Tela | O que mostrar | Arquivo |
| --- | --- | --- | --- |
| 1 | Work item → *Controle de horas* | timer em andamento, seletor de atividade aberto, lançamento manual abaixo | `marketplace/screenshots/01-timer.png` |
| 2 | *Folha semanal* | semana com lançamentos, totais por dia, selo "Aberta" e botão de enviar | `02-folha.png` |
| 3 | *Aprovações* | lista de semanas pendentes e o detalhe com histórico de decisões | `03-aprovacoes.png` |
| 4 | *Relatórios* | filtros, totais, quebras com barras e botão de exportar CSV | `04-relatorios.png` |
| 5 | *Configuração → Regras* | incremento, limite diário, janela retroativa e comentário obrigatório | `05-configuracao.png` |

Depois de capturar, liste as imagens no `vss-extension.json`:

```json
"screenshots": [
  { "path": "marketplace/screenshots/01-timer.png" }
]
```

e acrescente `{ "path": "marketplace/screenshots", "addressable": true }` em `files`.
