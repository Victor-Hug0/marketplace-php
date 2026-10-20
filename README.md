# Marketplace PHP

API de marketplace em **Laravel 12** + **PHP 8.4**, com autenticação via **Laravel Sanctum**, **PostgreSQL**, **Redis** (filas) e Docker Compose.

Roadmap de negócio: [`docs/roadmap-implementacao-api.md`](docs/roadmap-implementacao-api.md).  
Backlog técnico (itens quebrados): [`docs/roadmap-backlog-tecnico.md`](docs/roadmap-backlog-tecnico.md).

## Stack

| Peça | Tecnologia |
|------|------------|
| Runtime | PHP 8.4 / Laravel 12 |
| Auth | Sanctum (access + refresh token) |
| Banco | PostgreSQL 17 |
| Filas / cache async | Redis 7 + `queue:work` |
| API | REST versionada (`/api/v1`) |

## O que já está implementado

### Autenticação e usuários
- Cadastro (`register`) com validação forte de senha (`Password::defaults`)
- Login com rate limit (manual + `throttle`) e par de tokens
- Logout revogando access + refresh
- Refresh token com rotação
- Abilities Sanctum: `access` / `refresh`
- `GET /users/me` e listagem paginada por cursor (`GET /users`)
- Usuário com UUID, soft deletes, CPF, telefone, status e `last_login_at`

### Infra e qualidade
- Docker Compose: `app`, `postgres`, `redis`, `worker`, `scheduler`
- Compose de testes: Postgres (`5433`) + Redis (`6380`) + runner
- Envelope de erro JSON padronizado (`ApiErrorResponse`)
- Audit log de domínio (`audit_logs` com morphs actor/auditable)
- Trait de paginação cursor + `CursorPaginatedResource`
- Feature tests de auth (register, login, logout, refresh, me, throttle)

### Endpoints atuais

| Método | Rota | Auth |
|--------|------|------|
| `POST` | `/api/v1/auth/register` | Público (throttle) |
| `POST` | `/api/v1/auth/login` | Público (throttle) |
| `POST` | `/api/v1/auth/logout` | Bearer `access` |
| `POST` | `/api/v1/auth/refresh` | Bearer `refresh` |
| `GET` | `/api/v1/users/me` | Bearer `access` |
| `GET` | `/api/v1/users` | Bearer `access` (cursor) |
| `GET` | `/up` | Healthcheck |

## Pré-requisitos

- Docker + Docker Compose
- (Opcional) PHP 8.4 + Composer no host, para rodar Artisan/testes fora do container

## Subindo a aplicação

```bash
# 1. Ambiente
cp .env.example .env
# Gere a key (no host ou no container depois do build):
# php artisan key:generate
# ou: docker compose run --rm app php artisan key:generate

# 2. Build e start (app + postgres + redis + worker + scheduler)
docker compose up -d --build

# 3. Migrations
docker compose exec app php artisan migrate

# 4. API
# http://127.0.0.1:8080
```

### Serviços

| Serviço | Container | Porta host |
|---------|-----------|------------|
| API (Apache) | `marketplace-app` | `8080` |
| PostgreSQL | `marketplace-postgres` | `5432` |
| Redis | `marketplace-redis` | `6379` |
| Queue worker | `marketplace-worker` | — |
| Scheduler (`schedule:work`) | `marketplace-scheduler` | — |

No Docker, `DB_HOST` e `REDIS_HOST` são sobrescritos para `postgres` e `redis`. No `.env` do host continue com `127.0.0.1` para ferramentas locais.

### Comandos úteis

```bash
docker compose ps
docker compose logs -f app
docker compose logs -f worker
docker compose logs -f scheduler
docker compose exec app php artisan migrate
docker compose exec app php artisan tinker
docker compose down
```

## Testes

A suíte usa Postgres/Redis isolados (`compose.test.yml`) e `phpunit.xml` configurado para:

- DB: `127.0.0.1:5433` / `marketplace_test`
- Redis: `127.0.0.1:6380`
- `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`

### Rodar com Docker (recomendado)

```bash
# Sobe Postgres + Redis de teste e executa a suíte
docker compose -f compose.test.yml up -d postgres redis
docker compose -f compose.test.yml run --rm test

# Somente os testes de autenticação
docker compose -f compose.test.yml run --rm test php artisan test --filter=AuthenticationTest
```

### Rodar no host

Com a infra de teste no ar (`5433` / `6380`):

```bash
docker compose -f compose.test.yml up -d postgres redis
php artisan test
# ou
./vendor/bin/phpunit
```

### Cobertura atual de auth

Arquivo: `tests/Feature/Auth/AuthenticationTest.php`

- Register válido, inválido e e-mail duplicado
- Login com tokens, credenciais inválidas e rate limit
- `me` autenticado / sem token
- Refresh com rotação e rejeição de access token
- Logout revoga o par; refresh após logout → 401

## Estrutura relevante

```text
app/
  Http/Controllers/     AuthController, UserController
  Http/Requests/        LoginRequest, RegisterRequest
  Http/Resources/       UserResource, CursorPaginatedResource
  Http/Exceptions/      ApiErrorResponse
  Models/               User, AuditLog
  Services/             AuthService
  Support/Pagination/   CursorPaginates
compose.yml             app + postgres + redis + worker + scheduler
compose.test.yml        infra e runner de testes
docs/roadmap-implementacao-api.md
docs/roadmap-backlog-tecnico.md
tests/Feature/Auth/
```

## Próximos passos (roadmap)

O roadmap prioriza **regra de negócio** antes de higiene de conta. Fluxo Git: `feature/*` → `develop` → `main`.

| Fase | Status |
|------|--------|
| **B1** — Papéis + onboarding seller | Feito |
| **B2** — Catálogo (categorias, produtos, vitrine, busca) | Feito |
| **B3** — Ofertas, estoque e preços (Buy Box) | Em andamento |
| **B4** — Carrinho, checkout e pedidos multi-seller | Próximo |
| **B5** — Pagamento mock + comissão + ledger | Depois |
| **B6** — Frete simulado + tracking | Depois |
| **B7** — Review + disputa/RMA mínimas | Depois |
| **L\*** — Conta, marketing, OpenAPI | Later |

Detalhamento por ticket: [`docs/roadmap-backlog-tecnico.md`](docs/roadmap-backlog-tecnico.md).  
Narrativa de negócio: [`docs/roadmap-implementacao-api.md`](docs/roadmap-implementacao-api.md).

## Licença

Projeto sob [MIT license](https://opensource.org/licenses/MIT).
