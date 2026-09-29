# Setup — Deploy do `api/` na VPS (Hostinger + Coolify)

Guia para publicar o backend Laravel numa VPS administrada pelo Coolify (testado com 4.3.23). Complementa [`Dockerfile`](Dockerfile) e os arquivos em [`docker/`](docker/), que já foram validados localmente (build, migrações contra Postgres real, health check e rotas de autenticação respondendo pela stack completa nginx → php-fpm → Laravel).

## Pré-requisitos

- VPS com Coolify já instalado e acessível.
- Repositório Git deste projeto acessível pelo Coolify (via GitHub App, deploy key ou token, conforme sua configuração do Coolify).
- Um domínio ou subdomínio apontando para a VPS (ex.: `api-dev.timersmit.seudominio.com.br`), se quiser TLS automático via Let's Encrypt.

## 1. Criar o banco de dados

1. No projeto/ambiente do Coolify, **Add Resource → Databases → PostgreSQL**.
2. Deixe o Coolify gerar usuário/senha, ou defina os seus.
3. Anote o **host interno**, **porta**, **nome do banco**, **usuário** e **senha** — vão para as variáveis de ambiente da aplicação (passo 3).

## 2. Criar a aplicação

1. **New Resource → Application** → conecte o repositório Git.
2. **Base Directory**: `api`
3. **Build Pack**: Dockerfile (o Coolify detecta [`api/Dockerfile`](Dockerfile) automaticamente).
4. **Port**: 80 (exposta pela imagem via nginx).

## 3. Variáveis de ambiente

Configure na aba **Environment Variables** da aplicação (referência completa em [`.env.example`](.env.example)):

| Variável | Valor |
| --- | --- |
| `APP_KEY` | Gerar localmente: `php artisan key:generate --show`. Nunca deixar vazio em produção. |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://<seu-domínio>` |
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST` | host interno do Postgres criado no passo 1 |
| `DB_PORT` | `5432` |
| `DB_DATABASE` | nome do banco criado no passo 1 |
| `DB_USERNAME` | usuário do passo 1 |
| `DB_PASSWORD` | senha do passo 1 |
| `TIMERSMIT_SESSION_SECRET` | valor aleatório forte, exclusivo deste ambiente (não reutilizar entre dev/piloto/produção) |
| `TIMERSMIT_SESSION_TTL_MINUTES` | `60` (ou outro valor, opcional) |
| `LOG_CHANNEL` | `stderr` (aparece direto nos logs do Coolify) |

Gerar `TIMERSMIT_SESSION_SECRET` rapidamente:

```bash
openssl rand -base64 32
```

## 4. Comando pós-deploy (migrações)

Na aba **Post-deployment Command**, configure:

```bash
php artisan migrate --force
```

Isso roda **depois** de cada deploy bem-sucedido, nunca automaticamente no boot do container — mantém o princípio de "backup antes de alterações" do plano do projeto. Faça backup do banco antes de deploys com migrações destrutivas.

## 5. Domínio e TLS

Em **Domains**, associe o domínio/subdomínio da aplicação. O Coolify provisiona certificado Let's Encrypt automaticamente.

## 6. Deploy

Clique em **Deploy**. Acompanhe os logs de build; ao final, teste:

```bash
curl -i https://<seu-domínio>/up
curl -i https://<seu-domínio>/api/me
```

- `/up` deve responder `200`.
- `/api/me` sem sessão deve responder `401` com `{"message":"Não autenticado."}`.

## 7. Depois do primeiro deploy

1. Atualize `extension/.env.example` (ou o `.env.local` do seu ambiente de build) com:
   ```
   VITE_API_BASE_URL=https://<seu-domínio>
   ```
2. Reempacote o VSIX de desenvolvimento:
   ```bash
   cd extension
   npm run build
   npm run package:dev
   ```
3. Reenvie o `.vsix` gerado em `extension/dist-vsix/` no [portal do Marketplace](https://marketplace.visualstudio.com/manage/publishers/TrackerSMIT) (publisher `TrackerSMIT`, já compartilhado com a organização `smitbr`).
4. Teste o fluxo completo de autenticação (T006/T012) abrindo um work item em `https://dev.azure.com/smitbr/` — a aba "Controle de horas" deve conseguir estabelecer sessão de verdade com o backend, em vez do erro de conexão esperado ao testar contra `localhost`.

## Atualizações futuras

Cada novo deploy no Coolify reconstrói a imagem a partir do `Dockerfile` e roda o comando pós-deploy. Não há necessidade de passos manuais adicionais, exceto quando uma migração exigir atenção especial (ver `plan.md` → Deployment: "migrações reversíveis; backup antes de alterações").
