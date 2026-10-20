# Marketplace PHP

[![CI](https://github.com/Victor-Hug0/marketplace-php/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/Victor-Hug0/marketplace-php/actions/workflows/ci.yml)

API REST de um marketplace multi-seller em **Laravel 12** e **PHP 8.4**: lojas aprovadas por um admin vendem produtos de um catálogo compartilhado, o comprador fecha um pedido com itens de várias lojas num único checkout, paga (gateway simulado), recebe com frete e rastreio por loja, e pode avaliar, abrir disputa ou pedir devolução. A plataforma cobra comissão e repassa o restante ao seller por um ledger contábil.

Projeto de portfólio com foco em **regra de negócio**: transações, concorrência de estoque, idempotência, máquinas de estado explícitas e dinheiro em centavos de ponta a ponta (do preço da oferta ao saque) com trilha de auditoria.

## Destaques técnicos

- **Checkout atômico e idempotente:** um pedido com N subpedidos (um por loja), preços e frete congelados, estoque reservado; tudo numa transação, protegido por `Idempotency-Key` e por unicidade no banco.
- **Estoque sem oversell:** reservas com `lockForUpdate()`, teste com duas reservas concorrentes do último item, e expiração agendada de pedidos não pagos.
- **Máquinas de estado** para loja, produto, oferta, pedido, subpedido, pagamento, saque, envio, disputa e devolução. Transição inválida responde 409; cada mudança de pedido fica no histórico.
- **Ledger append-only:** comissão, venda, reembolso proporcional e saque como lançamentos imutáveis (bloqueados no model e por trigger no Postgres). Saldo é sempre soma.
- **Webhook de pagamento idempotente,** processado em fila com retentativas.
- **Autorização em três camadas:** ability do token, papel (`buyer`, `seller`, `admin`) e Policy por recurso; recurso de outro usuário responde 404.
- **259 testes** cobrindo caminho feliz, 401/403/404/409/422 e conciliação financeira; lazy loading é proibido fora de produção, então um N+1 falha na suíte.
- **Pronto para operar:** `/up` verifica Postgres e Redis (usado pelo healthcheck da imagem), tokens vencidos são limpos todo dia e o CI valida também a instalação sem dependências de desenvolvimento.

## Stack

| Peça | Tecnologia |
|------|------------|
| Runtime | PHP 8.4, Laravel 12 |
| Autenticação | Laravel Sanctum (par access 1 h + refresh 30 dias, com rotação), login com Google (Socialite) |
| E-mail | Resend (transporte nativo do Laravel), enviado pela fila |
| Banco | PostgreSQL 17 |
| Fila e agendamento | Redis 7, `queue:work` e `schedule:work` |
| API | REST versionada em `/api/v1`, paginação por cursor |
| Ambiente | Docker Compose (imagem `php:8.4-apache` no container `app`) |
| Qualidade | PHPUnit (feature tests), Laravel Pint |

## O que a API faz

| Contexto | Resumo |
|----------|--------|
| Conta | Cadastro com senha forte (sempre como `buyer`), confirmação de e-mail por link assinado (checkout bloqueado até confirmar), recuperação de senha com token de uso único, login com Google, login com rate limit, logout, refresh com rotação, `GET /users/me` com papéis e loja |
| Lojas | Comprador solicita loja; admin aprova (concede `seller`), rejeita ou suspende (remove `seller`); vitrine pública por slug com nota média |
| Catálogo | Categorias em árvore (3 níveis) com atributos; produtos com moderação (`draft → pending_moderation → active`), até 8 imagens, peso e medidas; busca pública por texto, categoria e loja |
| Ofertas | Vários sellers vendem o mesmo produto, cada um com preço, promoção com janela e estoque; Buy Box (menor preço, depois mais estoque, depois mais antiga) |
| Endereços e carrinho | Endereços com CEP e UF validados e um padrão por usuário; carrinho multi-seller com preço vigente |
| Frete | Cotação simulada por loja (zona por CEP, peso volumétrico, tabela de faixas), modalidades padrão e expressa |
| Checkout e pedidos | Checkout idempotente; pedido com subpedidos; cancelamento pelo comprador (antes de pagar) ou pelo seller (antes de enviar, com reembolso); expiração automática de pedidos não pagos |
| Pagamento | Pix, cartão e boleto simulados; webhook com segredo, idempotente, em fila |
| Financeiro | Comissão configurável sobre os itens; ledger; reembolso total ou parcial; saldo, saque e trava de saldo durante pós-venda |
| Envio | Separação, envio com código de rastreio e confirmação de entrega; o pedido acompanha o seller mais atrasado |
| Pós-venda | Avaliação de item entregue; disputa mediada pelo admin; devolução decidida pelo seller ou admin; janela de 7 dias |

## Documentação

| Documento | Conteúdo |
|-----------|----------|
| [`public/docs/index.html`](public/docs/index.html) (em `http://127.0.0.1:8080/docs/`) | Documentação interativa gerada pelo Scribe: endpoints agrupados por contexto, parâmetros extraídos dos Form Requests, exemplos de resposta e botão "Try it out" |
| [`public/docs/openapi.yaml`](public/docs/openapi.yaml) e [`collection.json`](public/docs/collection.json) | Especificação OpenAPI 3.0.3 e coleção do Postman, para importar em Insomnia, Postman ou geradores de cliente |
| [`docs/api-referencia.md`](docs/api-referencia.md) | Os 102 endpoints: acesso, corpo, sucesso e erros; convenções de envelope, paginação, idempotência e rate limit |
| [`docs/fluxos-e-regras.md`](docs/fluxos-e-regras.md) | Máquinas de estado, regras de cada contexto, fluxo do dinheiro e do estoque, autorização, configuração e auditoria |
| [`docs/roadmap-implementacao-api.md`](docs/roadmap-implementacao-api.md) | Roadmap de negócio por fase, com o teste que comprova cada critério de pronto |
| [`docs/roadmap-backlog-tecnico.md`](docs/roadmap-backlog-tecnico.md) | Backlog técnico item a item, decisões de cada fase e pendências |

## Pré-requisitos

- Docker e Docker Compose
- Opcional: PHP 8.4 e Composer no host, para rodar Artisan e testes fora do container

## Subindo a aplicação

```bash
# 1. Ambiente (o .env.example já aponta para o Postgres do compose)
cp .env.example .env
# Para testar o webhook de pagamento, defina um segredo:
#   PAYMENTS_MOCK_WEBHOOK_SECRET=algum-segredo-local

# 2. Build e start: app, postgres, redis, worker e scheduler
docker compose up -d --build

# 3. Chave da aplicação, migrations e dados de demonstração
docker compose exec -u www-data app php artisan key:generate
docker compose exec -u www-data app php artisan migrate --seed

# 4. API em http://127.0.0.1:8080/api/v1
```

O entrypoint do container instala as dependências, cria as pastas de `storage/` e executa `storage:link` (necessário para as URLs das imagens de produto).

#### Permissões entre host e container (Linux)

O projeto é montado dentro dos containers, então quem grava em `storage/`, `vendor/` ou `public/docs` precisa ser o mesmo usuário do host. Não há nada a configurar: ao subir, o entrypoint lê o dono da pasta do projeto e dá esse UID/GID ao `www-data` do container. Composer, Artisan, worker, scheduler e testes rodam como esse usuário (o Apache sobe como root e troca sozinho). Resultado: nenhum arquivo do repositório fica com dono root ou `www-data`, e dá para alternar entre `artisan serve` no host e os containers sem `chown`.

- `docker compose exec` não passa pelo entrypoint e entra como root; para comandos que gravam arquivos, use `docker compose exec -u www-data app php artisan ...`.
- Na imagem de produção o projeto já pertence ao `www-data` (UID 33), então nada muda; em Docker Desktop (macOS/Windows), onde a pasta montada aparece como root, o entrypoint mantém o comportamento padrão.

### Dados de demonstração

`php artisan db:seed` (ou `migrate --seed`) cria:

| Seeder | O que cria |
|--------|-----------|
| `DatabaseSeeder` | papéis `buyer`, `seller`, `admin`; chama os demais e, só com `APP_ENV=local`, o `DemoSeeder` |
| `PlatformFeeRuleSeeder` | regra global de comissão de 10% |
| `ShippingRateRuleSeeder` | 18 regras de frete (2 modalidades × 3 zonas × 3 faixas) |
| `CategorySeeder` | 5 categorias (Eletrônicos > Celulares, Notebooks; Casa > Móveis) com atributos |
| `ProductSeeder` | seller `seller.demo@marketplace.test`, "Loja Demo", 3 produtos (`smartphone-demo-128gb` e `notebook-demo-16gb` ativos, `fone-bluetooth-demo` em rascunho) e uma oferta do smartphone a R$ 1.999,90 com promoção de R$ 1.799,90 nos 30 dias seguintes ao seed |
| `OfferSeeder` | seller `seller.b.demo@marketplace.test`, "Loja Rival Demo" e uma oferta concorrente do mesmo smartphone a R$ 1.899,00. Enquanto a promoção da Loja Demo vale, ela vence o Buy Box; depois, vence a Rival |
| `DemoSeeder` | a jornada completa, descrita abaixo |

Todos os seeders podem rodar mais de uma vez sem duplicar dados.

### Jornada de demonstração (`DemoSeeder`)

Roda sozinho em `migrate --seed` com `APP_ENV=local`, ou explicitamente com `php artisan db:seed --class=DemoSeeder`. **Recusa rodar em produção.** Os pedidos são montados pelos próprios services (carrinho, checkout, pagamento, webhook, envio, entrega), então estoque, ledger e histórico ficam coerentes, como num uso real.

Contas (senha `Password1!` em todas):

| E-mail | Papel | Para testar |
|--------|-------|-------------|
| `admin@marketplace.test` | admin | aprovar a loja candidata e o produto em moderação, resolver a disputa, processar o saque, confirmar entregas, reembolsar |
| `comprador@marketplace.test` | buyer | pedidos em todos os estados, avaliação, disputa e devolução |
| `seller.demo@marketplace.test` | seller (Loja Demo) | enviar o pedido pago, decidir a devolução, ver saldo |
| `seller.b.demo@marketplace.test` | seller (Loja Rival Demo) | responder a disputa, ver o saque pendente |
| `candidato@marketplace.test` | buyer | tem a "Loja Candidata Demo" aguardando aprovação |

Pedidos do comprador (a `Idempotency-Key` de cada um é o nome do cenário):

| Cenário | Estado | Detalhe |
|---------|--------|---------|
| `demo-aguardando-pagamento` | `pending_payment` | boleto gerado; expira em 3 dias se não for pago |
| `demo-pago` | `paid` | dois subpedidos (Loja Rival e Loja Demo), prontos para envio |
| `demo-enviado` | `shipped` | com código de rastreio |
| `demo-entregue-avaliado` | `delivered` | avaliação de 5 estrelas no smartphone |
| `demo-em-disputa` | `delivered` | disputa aberta; o saldo do subpedido está travado no saque da Loja Rival |
| `demo-devolucao-pendente` | `delivered` | devolução pedida, aguardando decisão da Loja Demo |
| `demo-cancelado` | `cancelled` | cancelado pelo comprador antes de pagar |

Também deixa o produto `fone-bluetooth-demo` aguardando moderação e um saque de R$ 500,00 pendente da Loja Rival.

### Criando um admin

Fora do ambiente de demonstração não existe seeder nem endpoint para criar admin, de propósito. Cadastre um usuário normalmente e promova pelo Tinker:

```bash
docker compose exec -u www-data app php artisan tinker --execute="\$u = App\Models\User::where('email', 'voce@exemplo.com')->firstOrFail(); \$u->roles()->syncWithoutDetaching([App\Models\Role::firstOrCreate(['name' => App\Enums\RoleName::ADMIN])->id]);"
```

### Serviços

| Serviço | Container | Porta no host | Papel |
|---------|-----------|---------------|-------|
| `app` | `marketplace-app` | `8080` | API |
| `postgres` | `marketplace-postgres` | `5432` | banco |
| `redis` | `marketplace-redis` | `6379` | fila e cache |
| `worker` | `marketplace-worker` | — | `queue:work redis` (webhooks de pagamento) |
| `scheduler` | `marketplace-scheduler` | — | `schedule:work` (expiração de pedidos e reservas a cada minuto; `sanctum:prune-expired` diário) |

Dentro dos containers, `DB_HOST` e `REDIS_HOST` são sobrescritos para `postgres` e `redis`. No `.env` do host mantenha `127.0.0.1` para usar ferramentas locais.

O health check `GET /up` responde 200 só quando o banco e o Redis respondem; caso contrário, 500. O `HEALTHCHECK` da imagem usa essa rota, então `docker compose ps` mostra o `app` como `unhealthy` quando uma dependência cai.

### Comandos úteis

```bash
docker compose ps
docker compose logs -f app
docker compose logs -f worker
docker compose logs -f scheduler
docker compose exec -u www-data app php artisan migrate
docker compose exec -u www-data app php artisan tinker
docker compose exec -u www-data app php artisan schedule:list
docker compose down

# Comandos do domínio (também rodam sozinhos pelo scheduler)
docker compose exec -u www-data app php artisan orders:expire-unpaid          # cancela pedidos não pagos vencidos
docker compose exec -u www-data app php artisan reservations:release-expired  # libera reservas avulsas vencidas
```

## Configuração de domínio

As variáveis abaixo estão no `.env.example`; a explicação completa está na seção 16 de [`docs/fluxos-e-regras.md`](docs/fluxos-e-regras.md).

| Variável | Padrão | Para quê |
|----------|--------|----------|
| `PAYMENTS_MOCK_WEBHOOK_SECRET` | vazio | segredo do webhook (vazio recusa todos) |
| `PAYMENTS_MOCK_PIX_TTL_MINUTES` | 30 | validade do Pix |
| `PAYMENTS_MOCK_BOLETO_DUE_DAYS` | 3 | vencimento do boleto |
| `PLATFORM_COMMISSION_BPS` | 1000 | comissão padrão (10%) |
| `ORDERS_UNPAID_TTL_MINUTES` | 60 | prazo de pagamento do pedido |
| `SHIPPING_DEFAULT_SERVICE` | `simulated_standard` | modalidade de frete padrão |
| `SHIPPING_DEFAULT_ORIGIN_CEP` | `01310100` | origem do frete para loja sem CEP |
| `POST_SALE_DISPUTE_WINDOW_DAYS` / `POST_SALE_RETURN_WINDOW_DAYS` | 7 | janela de pós-venda |
| `MAIL_MAILER` / `RESEND_API_KEY` / `MAIL_FROM_ADDRESS` | `log` / vazio / `onboarding@resend.dev` | envio de e-mail (veja [E-mail e login com Google](#e-mail-e-login-com-google)) |
| `FRONTEND_URL` | `http://localhost:3000` | destino do link de redefinição de senha |
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` / `GOOGLE_REDIRECT_URI` | vazio / vazio / `${APP_URL}/api/v1/auth/google/callback` | login com Google; sem client, os endpoints respondem 503 |

## E-mail e login com Google

Os e-mails (confirmação de cadastro e redefinição de senha) saem pela fila, então o `worker` precisa estar no ar. Sem configuração nenhuma, `MAIL_MAILER=log` grava as mensagens em `storage/logs/laravel.log`, com o link incluído.

### Resend

1. Crie uma conta em [resend.com](https://resend.com) e gere uma API key em **API Keys**.
2. No `.env`: `MAIL_MAILER=resend` e `RESEND_API_KEY=<sua chave>`.
3. Remetente: `MAIL_FROM_ADDRESS="onboarding@resend.dev"` funciona na hora, mas só entrega no e-mail da sua conta Resend. Para enviar a qualquer pessoa, verifique um domínio em **Domains** e use um endereço dele.
4. Reinicie o worker para ler o `.env` novo: `docker compose restart worker`.

### Google

1. No [Google Cloud Console](https://console.cloud.google.com/), crie (ou escolha) um projeto e configure a **tela de consentimento OAuth** (tipo externo; em modo de teste, adicione seu e-mail como usuário de teste).
2. Em **APIs e serviços > Credenciais > Criar credenciais > ID do cliente OAuth**, escolha **Aplicativo da Web**.
3. Em **URIs de redirecionamento autorizados**, cadastre exatamente o valor de `GOOGLE_REDIRECT_URI` (padrão: `http://127.0.0.1:8080/api/v1/auth/google/callback`).
4. Copie o ID e a chave secreta para `GOOGLE_CLIENT_ID` e `GOOGLE_CLIENT_SECRET` no `.env`.

Para testar sem frontend: chame `GET /api/v1/auth/google/redirect`, abra a `url` devolvida no navegador e, depois do consentimento, o Google volta para o callback, que responde com o par de tokens em JSON. Num frontend, aponte `GOOGLE_REDIRECT_URI` para uma página do app e repasse o `code` recebido para `GET /api/v1/auth/google/callback?code=...`.

Quem entra pelo Google ganha conta `buyer` com e-mail já confirmado e uma senha aleatória; para entrar também com senha, basta usar "esqueci minha senha". Uma conta existente com o mesmo e-mail é vinculada, desde que o Google informe o e-mail como verificado.

## Um passeio pela API

Fluxo mínimo de compra, depois do seed (`$TOKEN` é o `access_token` do login):

```bash
API=http://127.0.0.1:8080/api/v1

# Cadastro e login do comprador
curl -s -X POST $API/auth/register -H 'Content-Type: application/json' -d '{
  "name": "Maria Compradora", "email": "maria@exemplo.com", "cpf": "12345678901",
  "phone_number": "11999999999", "password": "Senha@Forte1", "password_confirmation": "Senha@Forte1"}'
# Confirme o e-mail antes do checkout: abra o link recebido (com MAIL_MAILER=log ele está em
# storage/logs/laravel.log). Ou pule o cadastro e use comprador@marketplace.test do DemoSeeder.
curl -s -X POST $API/auth/login -H 'Content-Type: application/json' \
  -d '{"email": "maria@exemplo.com", "password": "Senha@Forte1"}'

# Catálogo: produto com duas ofertas e a vencedora em buy_box
curl -s $API/products/smartphone-demo-128gb

# Endereço, carrinho, cotação e checkout
curl -s -X POST $API/addresses -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{
  "recipient_name": "Maria", "phone": "11999999999", "cep": "01311-000", "street": "Av. Paulista",
  "number": "1000", "district": "Bela Vista", "city": "São Paulo", "state": "SP"}'
curl -s -X POST $API/cart/items -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"offer_id": "<id da oferta>", "quantity": 1}'
curl -s -X POST $API/shipping/quotes -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"address_id": "<id do endereço>"}'
curl -s -X POST $API/checkout -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: checkout-0001' -d '{"address_id": "<id do endereço>"}'

# Pagamento simulado e confirmação via webhook
curl -s -X POST $API/orders/<id do pedido>/payments -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'Idempotency-Key: payment-0001' -d '{"method": "pix"}'
curl -s -X POST $API/webhooks/payments/mock -H 'Content-Type: application/json' \
  -H "X-Mock-Webhook-Secret: $PAYMENTS_MOCK_WEBHOOK_SECRET" \
  -d '{"event_id": "evt_0001", "type": "payment.paid", "data": {"external_id": "<external_id do pagamento>"}}'
```

Daí em diante, o seller faz `POST /seller/order-sellers/{id}/shipments` e `/deliver`, e o comprador pode avaliar o item, abrir disputa ou pedir devolução. Todos os endpoints estão em [`docs/api-referencia.md`](docs/api-referencia.md).

## Documentação interativa (Scribe)

A página em `public/docs` é gerada a partir do código e fica versionada no repositório, então `/docs/` funciona sem passo extra. As anotações ficam nos controllers como atributos PHP (`#[Doc\Group]`, `#[Doc\Endpoint]`, `#[Doc\ResponseFromApiResource]` e afins); os parâmetros vêm das `rules()` dos Form Requests, e as descrições e exemplos, do `bodyParameters()` (ou `queryParameters()`, na busca) de cada um.

O "Try it out" envia as requisições para o mesmo endereço que serve a página (override de uma linha em `resources/views/vendor/scribe/themes/default/index.blade.php`), então funciona em qualquer host ou porta. Ao atualizar o Scribe, compare esse arquivo com o original do pacote.

Depois de criar ou alterar endpoints, regenere com o banco de teste no ar (os exemplos de resposta são montados com factories dentro de uma transação desfeita no final):

```bash
docker compose -f compose.test.yml up -d postgres redis
docker compose -f compose.test.yml run --rm test sh -c "php artisan migrate:fresh --force && php artisan scribe:generate"
```

O CI gera a documentação e valida o `openapi.yaml` a cada PR: anotação quebrada ou spec inválido falham o build.

## Testes

A suíte usa Postgres e Redis isolados (`compose.test.yml`) e o `phpunit.xml` aponta para:

- banco `127.0.0.1:5433` / `marketplace_test`
- Redis `127.0.0.1:6380`
- `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`

### Rodar com Docker (recomendado)

```bash
docker compose -f compose.test.yml up -d postgres redis
docker compose -f compose.test.yml run --rm test

# Só um arquivo ou um teste
docker compose -f compose.test.yml run --rm test php artisan test --filter=CheckoutWorkflowTest
```

### Rodar no host

```bash
docker compose -f compose.test.yml up -d postgres redis
php artisan test
```

No host, o PHP precisa da extensão `redis` (o health check testa o Redis de verdade) e `storage/` precisa ser seu, o que os containers já garantem (veja [Permissões entre host e container](#permissões-entre-host-e-container-linux)).

### O que está coberto

| Área | Arquivos em `tests/Feature/` | Testes |
|------|------------------------------|-------:|
| Conta (cadastro, login, e-mail, senha, Google) | `Auth/` | 39 |
| Lojas e vitrine | `Store/`, `Catalog/` | 15 |
| Catálogo | `Category/`, `Product/` | 28 |
| Ofertas, Buy Box, estoque e preço em centavos | `Offer/` | 15 |
| Conversão reais ↔ centavos | `tests/Unit/MoneyTest` | 11 |
| Endereços e carrinho | `Address/`, `Cart/` | 20 |
| Checkout e pedidos | `Checkout/`, `Order/` | 36 |
| Pagamento | `Payment/` (dois testes rodam para Pix, cartão e boleto) | 21 |
| Ledger, reembolso e saque | `Ledger/`, `Refund/`, `Payout/` | 29 |
| Frete e envio | `Shipping/` | 17 |
| Avaliações e pós-venda | `Review/`, `PostSale/` | 20 |
| Seeder de demonstração | `Seeders/` | 4 |
| Health check (banco e Redis) e exemplo unitário | `HealthCheckTest`, `tests/Unit/ExampleTest` | 4 |
| **Total** | | **259** |

Formatação: `./vendor/bin/pint` (ou `--test` para só verificar).

## Estrutura

```text
app/
  Console/Commands/     orders:expire-unpaid, reservations:release-expired
  DTOs/                 CommissionBreakdown, ShippingOption, StoreShippingQuote
  Enums/                estados e tipos de domínio (21 enums)
  Http/Controllers/     público e comprador na raiz; Seller/ e Admin/ por papel
  Http/Middleware/      EnsureUserHasRole, ForceJsonResponse, VerifyMockPaymentWebhookSecret
  Http/Requests/        Form Requests (validação e idempotência)
  Http/Resources/       API Resources
  Jobs/                 ProcessPaymentWebhookEvent
  Models/               Eloquent (UUID; soft delete nos recursos editáveis)
  Policies/             autorização por recurso
  Services/             regras de negócio (um service por contexto)
  Support/Pagination/   CursorPaginates
config/                 payments, shipping, orders, post_sale (domínio)
database/
  migrations/           schema completo, com constraints e trigger do ledger
  factories/            factories com states para os testes
  seeders/              dados de demonstração idempotentes
routes/api.php          rotas /api/v1
routes/console.php      agendamentos
tests/Feature/          testes por contexto
docs/                   referência da API, regras e roadmap
public/docs/            documentação interativa, OpenAPI e coleção Postman (geradas pelo Scribe)
compose.yml             app, postgres, redis, worker, scheduler
compose.test.yml        infraestrutura e runner de testes
```

## Roadmap e fluxo de trabalho

Branches de feature saem de `develop`; `develop` vai para `main` por PR de release.

| Fase | Status |
|------|--------|
| B1 — Papéis e onboarding de seller | Concluída |
| B2 — Catálogo | Concluída |
| B3 — Ofertas, estoque e Buy Box | Concluída |
| B4 — Carrinho, checkout e pedidos multi-seller | Concluída |
| B5 — Pagamento mock, comissão e ledger | Concluída |
| B6 — Frete simulado e envio | Concluída |
| B7 — Avaliações, disputas e devoluções | Concluída |
| Qualidade — CI, `preventLazyLoading`, limpeza de tokens | Próxima |
| L3 — documentação OpenAPI interativa e jornada de demonstração | Concluída |
| L1 a L3 — conta, engajamento, relatórios e API de seller | Liberadas |

Pendências opcionais e próximos passos detalhados: [`docs/roadmap-backlog-tecnico.md`](docs/roadmap-backlog-tecnico.md).

## Licença

Projeto sob [MIT license](https://opensource.org/licenses/MIT).
