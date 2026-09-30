# Operação: backup, logs e monitoramento

Complementa o [`SETUP.md`](SETUP.md). O que está aqui foi exercitado localmente (scripts e testes indicados); o que depende do painel do Coolify ou do VPS está marcado como **a configurar**.

## 1. Backup e restauração

### Política sugerida (a confirmar — ver CL-003)

| Item | Proposta |
| --- | --- |
| Frequência | diário, de madrugada (fuso do servidor) |
| Retenção | 14 diários + 8 semanais |
| Destino | armazenamento externo ao VPS (S3 compatível); disco do próprio VPS não conta como backup |
| RPO (perda máxima aceita) | 24 h |
| RTO (tempo para voltar) | 4 h |
| Antes de deploy com migração destrutiva | backup manual (abaixo) |

### Configurar no Coolify (**a configurar**)

1. Em **Storages/S3**, cadastre o destino externo.
2. No recurso do PostgreSQL → **Backups** → agendamento diário, retenção acima e o destino S3.
3. Em **Notifications**, ative aviso de falha de backup (e-mail ou chat).
4. Depois do primeiro agendamento, confirme que o arquivo apareceu no destino e **faça a restauração de teste** (abaixo) com ele.

### Backup manual

No servidor do banco (ou pelo terminal do recurso no Coolify):

```bash
pg_dump -U <usuario> -Fc -f timersmit-$(date +%F-%H%M).dump <banco>
```

`-Fc` (formato custom) é o mesmo que o teste abaixo usa: comprimido e restaurável por tabela.

### Restaurar

Nunca restaure por cima do banco em produção sem antes parar a API. O caminho seguro é restaurar num banco **novo** e só então trocar:

```bash
createdb -U <usuario> timersmit_restaurado
pg_restore -U <usuario> -d timersmit_restaurado --no-owner --exit-on-error timersmit-AAAA-MM-DD.dump
```

Conferências antes de apontar a API para ele:

1. `select count(*) from time_entries;` e `tenants`/`members` batem com o esperado.
2. `php artisan migrate:status` (com `DB_DATABASE=timersmit_restaurado`) não mostra migração pendente.
3. Abra a extensão contra essa API/banco e confira a semana atual de uma pessoa.

Para voltar ao ar: ajuste `DB_DATABASE` no Coolify para o banco restaurado e faça redeploy. Os dados entre o último backup e a falha (até o RPO) são perdidos; lançamentos dessas horas precisam ser refeitos pelas pessoas.

### Teste de restauração automatizado

```bash
cd api
bash scripts/verify-restore.sh
```

Sobe um PostgreSQL descartável, migra, semeia 4.200 lançamentos em 2 empresas, gera o dump no formato acima, restaura num banco novo e compara contagem de todas as tabelas e checksum de `time_entries`, `members`, `weekly_submissions` e `migrations`. Falha se algo diferir ou se a origem estiver vazia. Resultado de 30/09/2026: idêntico; restauração em ~1 s (volume pequeno — o tempo real em produção cresce com o banco, meça no primeiro backup verdadeiro).

Repita a restauração de teste **com um backup real** a cada trimestre e depois de mudanças grandes de schema.

## 2. Logs

- `LOG_CHANNEL=stderr` e `LOG_LEVEL=info` em produção; o Coolify guarda a saída do contêiner (ajuste a retenção de logs do Docker no VPS — **a configurar**, senão crescem sem limite).
- A aplicação não registra cabeçalhos, corpo nem query string. O que vai para o log:
  - erros não tratados do Laravel (mensagem + stack trace);
  - `api.request.failed` (resposta 5xx) e `api.request.slow` (≥ 2 s): método, **padrão da rota** (ex.: `/api/me/weeks/{weekStartDate}`), status e duração;
  - `api.health.database_unreachable` (banco fora).
- Token de sessão e token do Azure DevOps não entram no log: a sessão vai em cabeçalho `Authorization` (nunca gravado), o PHP roda com `zend.exception_ignore_args = On` (traces sem argumentos) e `display_errors = Off` ([`docker/php.ini`](docker/php.ini)). Coberto por `tests/Feature/OperationsTest.php` (inclui o pior caso: exceção dentro da verificação do token).
- Confira também `APP_DEBUG=false` em produção; com `true` o Laravel devolve o stack trace ao cliente.
- O log de acesso do nginx registra a URL pedida (sem cabeçalhos). Os relatórios enviam filtros na query (datas, ids numéricos) — sem segredo, mas com identificadores de pessoas/projetos.

## 3. Monitoramento e alertas

### Saúde

- `GET /up` — o PHP sobe (usar como health check do contêiner no Coolify).
- `GET /api/health` — além do PHP, consulta o banco; `200 {"status":"ok"}` ou `503 {"status":"unavailable"}`, sem detalhes. **Use este no monitor externo.**

### Monitor externo (**a configurar**)

Uptime Kuma (no próprio Coolify) ou UptimeRobot:

| Monitor | Intervalo | Alerta |
| --- | --- | --- |
| `https://<domínio>/api/health` esperando `200` e `"status":"ok"` | 1 min | falhou 2 vezes seguidas |
| Expiração do certificado TLS | diário | 14 dias antes |

### Alertas por log (**a configurar**)

| Padrão na saída do contêiner | Gravidade | Ação |
| --- | --- | --- |
| `api.health.database_unreachable` | crítico | banco fora: verificar o recurso do PostgreSQL |
| `api.request.failed` (> 5 em 5 min) | alto | ver o stack trace anterior a cada ocorrência |
| `api.request.slow` (> 10 em 10 min) | médio | meta SC-004: semana < 3 s, timer < 2 s; ver carga/consultas |

### Recursos do VPS (**a configurar**)

Alertas do Coolify para disco (> 80%), memória e CPU sustentada. O banco e os dumps são o que mais cresce em disco.

### Números de referência (teste de carga, T040)

PostgreSQL 17, 50 mil lançamentos: abrir semana p95 19 ms; iniciar/parar timer p95 < 30 ms; relatório de 90 dias 336 ms. Sinais de problema real são muito acima disso.

## 4. Reverter um deploy

1. No Coolify, reimplante o commit anterior (aba **Deployments**).
2. Se o deploy rodou migração, veja se ela é reversível (`php artisan migrate:rollback --step=1`) **depois de fazer backup**; todas as migrações têm `down()`, exceto que a de concessão inicial de administrador (`..._grant_admin_to_first_member...`) é um ajuste de dados e não é desfeita — nesse caso, só o backup volta atrás.
3. Se o problema for de dados, restaure conforme a seção 1.

## 5. Lista de verificação do piloto

- [ ] Backup diário agendado no Coolify com destino externo e aviso de falha.
- [ ] Uma restauração de teste feita com um backup **real**.
- [ ] Monitor externo em `/api/health` com alerta.
- [ ] Alertas de disco/memória no Coolify.
- [ ] Retenção de logs do Docker limitada.
- [ ] `APP_DEBUG=false`, `LOG_LEVEL=info`, `LOG_CHANNEL=stderr`.
