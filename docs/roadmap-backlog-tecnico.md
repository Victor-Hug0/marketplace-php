# Backlog Técnico Detalhado — Marketplace API

Documento derivado de [`roadmap-implementacao-api.md`](roadmap-implementacao-api.md). Quebra cada fase em **itens implementáveis** (tamanho de 0,5–2 dias), com contrato técnico, schema, endpoints, serviços, testes e dependências.

**Fluxo Git:** `feature/*` → `develop` → `main`  
**Convenção de ID:** `{FASE}.{SLICE}.{ITEM}` (ex.: `B3.2.03`)  
**Status:** `done` · `wip` · `todo` · `blocked` · `later`

---

## Legenda de esforço

| Tag | Significado |
|-----|-------------|
| `S` | ≤ 0,5 dia |
| `M` | 0,5–1,5 dia |
| `L` | 1,5–3 dias |
| `XL` | > 3 dias (deve ser requebrado) |

## Convenções transversais (aplicar em todo item)

| Tema | Padrão do projeto |
|------|-------------------|
| IDs | UUID (`HasUuids`) |
| Soft delete | Domínio mutável; **não** em audit/ledger/reservations históricas se append-only |
| Auth | Sanctum abilities: `access` / `refresh` |
| Authz | `role:{buyer\|seller\|admin}` + Policy por recurso |
| Validação | Form Request |
| Resposta | API Resource + `{ data, meta?, links? }` / `ApiErrorResponse` |
| Paginação | Cursor (`CursorPaginates`) |
| Dinheiro | `decimal(12,2)` ou integer **centavos** — **decidir e padronizar na B3**; preferir **centavos `bigint`** para evitar float |
| Concorrência | `DB::transaction` + `lockForUpdate()` em estoque/checkout/pagamento |
| Audit | `AuditLog` sem tokens, senhas, PAN, paths absolutos |
| Testes | Feature + factories; cenários 200/201/204/401/403/404/409/422 |
| Branch | Criar a partir de `develop` |

---

## Mapa de progresso (visão rápida)

| Fase | Status geral | Notas |
|------|--------------|-------|
| Fundação (auth, docker, audit) | `done` | Pré-requisito |
| B1 Atores / loja | `done` | Store + roles + middleware |
| B2 Catálogo | `done` | Categorias, produtos, vitrine, busca |
| B2-extra (atributos valorados, resize) | `todo` | Paralelo seguro à B3 |
| B3 Ofertas / estoque / Buy Box | `done` | Em `develop` (PR #3) |
| B4 Carrinho / checkout / pedidos | `done` | B4.1–B4.5 em `develop` |
| B5 Pagamento / ledger | `done` | B5.1–B5.3 em `develop` (PRs #9–#11) |
| B6 Frete / envio | `done` | B6.1 cotação (PR #12) e B6.2 envio (PR #13) em `develop` |
| B7 Pós-venda | `wip` | B7.1 avaliações em `develop` (PR #14); B7.2 disputas/devoluções em `feature/b7-2-disputes-returns` |
| L1–L3 | `later` | Após jornada core verde |

---

## Fundação (pré-B1) — referência

| ID | Item | Status | Esforço |
|----|------|--------|---------|
| F.1.01 | Sanctum login/register/logout/refresh + abilities | `done` | — |
| F.1.02 | User UUID, soft delete, CPF/telefone, password hashed | `done` | — |
| F.1.03 | `ApiErrorResponse` + renders Validation/Auth/Http | `done` | — |
| F.1.04 | Audit log morph + sanitização de bearer token | `done` | — |
| F.1.05 | Cursor pagination trait | `done` | — |
| F.1.06 | Docker: app, postgres, redis, worker + `compose.test.yml` | `done` | — |
| F.1.07 | Rate limit login/register | `done` | — |
| F.1.08 | Password::defaults() | `done` | — |

---

# B1 — Atores, papéis e autorização

**Objetivo:** buyer/seller/admin com loja aprovada antes de vender.

### Slice B1.1 — Roles e cadastro

| ID | Item | Status | Esforço | Detalhe técnico |
|----|------|--------|---------|-----------------|
| B1.1.01 | Migration `roles` + `role_user` (UUID, unique name, timestamps pivot) | `done` | S | Rollback: drop pivot antes de roles |
| B1.1.02 | Enum `RoleName` + Model `Role` | `done` | S | `buyer` / `seller` / `admin` |
| B1.1.03 | Seeder roles | `done` | S | `DatabaseSeeder` |
| B1.1.04 | Register associa `buyer` em transação (`syncWithoutDetaching`) | `done` | S | `AuthService::register` |
| B1.1.05 | `User::hasRole` / `roles()` | `done` | S | |

**Testes:** register cria role buyer; seed idempotente.

### Slice B1.2 — Loja (seller onboarding)

| ID | Item | Status | Esforço | Detalhe técnico |
|----|------|--------|---------|-----------------|
| B1.2.01 | Migration `stores` | `done` | M | UUID, `user_id` unique, slug unique, document unique, status, rejection_reason, reviewed_*, softDeletes |
| B1.2.02 | Enum `StoreStatus` + Model `Store` + Factory | `done` | S | pending/active/rejected/suspended |
| B1.2.03 | `StoreService::requestStore` (slug único, 1 loja/user, audit) | `done` | M | 409 se já existe |
| B1.2.04 | `StoreService::approve` (status+role seller+audit) | `done` | M | `lockForUpdate`, só from pending |
| B1.2.05 | `StoreService::reject` (motivo obrigatório, sem role seller) | `done` | M | |
| B1.2.06 | `StoreService::suspend` (remove seller role) | `done` | M | from active |
| B1.2.07 | Middleware `role` + Policy Store | `done` | M | |
| B1.2.08 | Endpoints owner + admin | `done` | M | `POST /stores`, `GET /stores/me`, admin approve/reject/suspend |
| B1.2.09 | `GET /users/me` com roles + store | `done` | S | eager load |
| B1.2.10 | Feature tests StoreWorkflow | `done` | M | |

### Slice B1.3 — KYC enxuto (ainda aberto)

| ID | Item | Status | Esforço | Detalhe técnico |
|----|------|--------|---------|-----------------|
| B1.3.01 | Migration `kyc_documents` | `todo` | M | `store_id`, `type` enum, `path`, `status`, sem soft delete obrigatório |
| B1.3.02 | Upload documento (disk `private`/`local`) | `todo` | M | Nunca retornar path absoluto; URL assinada opcional |
| B1.3.03 | Admin listar/aprovar docs | `todo` | M | Policy admin |
| B1.3.04 | Bloquear approve de store sem docs mínimos (flag config) | `todo` | S | Feature flag `stores.require_kyc` default false no MVP |

**Dependência:** pode ficar após B3/B4; não bloqueia core de compra.

---

# B2 — Catálogo

**Objetivo:** produto canônico ≠ oferta; descoberta pública.

### Slice B2.1 — Categorias *(done)*

| ID | Item | Status | Esforço | Detalhe |
|----|------|--------|---------|---------|
| B2.1.01 | Migration `categories` + self-FK restrict | `done` | M | max depth 3 no service |
| B2.1.02 | Migration `category_attributes` | `done` | M | unique `(category_id, code)`, `data_type`, `options` JSON |
| B2.1.03 | Enum `CategoryAttributeDataType` | `done` | S | string/integer/decimal/boolean/enum |
| B2.1.04 | `CategoryService` (slug, ciclo, depth, delete com filhos/produtos) | `done` | L | |
| B2.1.05 | API pública árvore + detalhe por slug | `done` | M | |
| B2.1.06 | API admin CRUD categoria + atributos | `done` | L | |
| B2.1.07 | Seeder + Feature tests | `done` | M | |

### Slice B2.2 — Produtos + imagens + moderação *(done)*

| ID | Item | Status | Esforço | Detalhe |
|----|------|--------|---------|---------|
| B2.2.01 | Migration `products` + `product_images` | `done` | M | ownership `store_id`; unique slug (global no código atual) |
| B2.2.02 | Enum `ProductStatus` (incl. `pending_moderation`) | `done` | S | |
| B2.2.03 | `ProductService` FSM + imagens (max 8) + audit | `done` | XL | submit/approve/reject/pause/resume |
| B2.2.04 | Policy ownership seller + admin | `done` | M | |
| B2.2.05 | Endpoints seller/admin/público | `done` | L | |
| B2.2.06 | Seeder + Feature tests | `done` | L | |

**Contrato FSM produto (atual):**

```text
draft|rejected --submit--> pending_moderation --approve--> active
pending_moderation --reject--> rejected
active --pause--> paused --resume--> active
seller edita conteúdo só em draft|rejected
```

### Slice B2.3 — Vitrine e busca *(done)*

| ID | Item | Status | Esforço | Detalhe |
|----|------|--------|---------|---------|
| B2.3.01 | `GET /stores/{slug}` público + produtos cursor | `done` | M | `PublicStoreResource` sem PII |
| B2.3.02 | `ProductService::publicCatalogQuery` | `done` | M | q / category / store + loja active |
| B2.3.03 | Detalhe produto exige store active | `done` | S | |
| B2.3.04 | Feature tests PublicCatalog | `done` | M | |

### Slice B2.4 — Extras de catálogo *(paralelo seguro à B3)*

| ID | Item | Status | Esforço | Detalhe técnico |
|----|------|--------|---------|-----------------|
| B2.4.01 | Migration `product_attribute_values` | `todo` | M | `product_id`, `category_attribute_id`, `value_string`/`value_number`/`value_boolean`, unique `(product_id, category_attribute_id)` |
| B2.4.02 | Validação valores vs `data_type` + `options` | `todo` | M | Service no create/update produto |
| B2.4.03 | Expor atributos no `ProductResource` | `todo` | S | |
| B2.4.04 | Filtro público `?attr[color]=preto` | `todo` | L | joins; índices em value_* |
| B2.4.05 | Job `ProcessProductImage` (resize/webp) | `todo` | M | queue redis; path relativo |
| B2.4.06 | Limite MIME/size upload reforçado | `todo` | S | Form Request + config |
| B2.4.07 | Flag `is_platform` / store platform seeder | `todo` | S | produto da plataforma |

---

# B3 — Ofertas, estoque, preços (Buy Box) — `wip`

**Objetivo:** multi-seller no mesmo produto; inventário seguro.  
**Branch ativa:** `feature/b3-offers-inventory`  
**Dependência:** B2 done; B4 bloqueada até B3.3 (reserve) existir.

### Decisões técnicas a fixar no início da B3

| Decisão | Recomendação MVP |
|---------|------------------|
| Dinheiro | `price_cents` `bigint` + `currency=BRL` |
| Estoque | `stock` int + `reserved_stock` int **ou** só `stock` + soma de `inventory_reservations` ativas — preferir **coluna `reserved_stock` denormalizada** atualizada no mesmo lock |
| Variantes | **Adiar** `product_variants` no MVP; `offers.product_id` direto; coluna nullable `product_variant_id` reservada |
| Unique oferta | unique `(store_id, product_id)` where not deleted |
| Promo | `promotional_price_cents` + `promo_starts_at`/`promo_ends_at` |

### Slice B3.1 — Schema e domínio

| ID | Item | Status | Esforço | Detalhe técnico |
|----|------|--------|---------|-----------------|
| B3.1.01 | Migration `offers` | `wip` | M | ver colunas abaixo |
| B3.1.02 | Migration `inventory_reservations` | `wip` | M | |
| B3.1.03 | Enums `OfferStatus`, `ReservationStatus` | `wip` | S | |
| B3.1.04 | Models `Offer`, `InventoryReservation` + relations | `wip` | M | `Store::offers`, `Product::offers` |
| B3.1.05 | Factories + states | `wip` | S | active/paused, inStock |
| B3.1.06 | Helpers: `currentPriceCents()`, `availableStock()` | `todo` | S | promo window timezone app |

**Schema sugerido `offers`:**

```text
id uuid PK
store_id uuid FK stores restrict
product_id uuid FK products restrict
price_cents bigint not null check >= 0
promotional_price_cents bigint nullable check >= 0
promo_starts_at timestamptz nullable
promo_ends_at timestamptz nullable
stock unsigned int not null default 0
reserved_stock unsigned int not null default 0
status string not null default 'active'  -- active|paused
softDeletes, timestamps
unique (store_id, product_id)
index (product_id, status)
index (store_id, status)
check (reserved_stock <= stock)
```

**Schema sugerido `inventory_reservations`:**

```text
id uuid PK
offer_id uuid FK offers restrict
user_id uuid nullable FK users nullOnDelete   -- até B4 ter cart_id
cart_id uuid nullable                         -- preenchido na B4
quantity unsigned int not null check > 0
status string not null  -- pending|committed|released|expired
expires_at timestamptz not null
idempotency_key string nullable unique
timestamps
index (offer_id, status)
index (expires_at)  -- job de expiração
```

### Slice B3.2 — CRUD de ofertas (seller)

| ID | Item | Status | Esforço | Detalhe |
|----|------|--------|---------|---------|
| B3.2.01 | `OfferService::create/update` | `todo` | L | só store active + role seller; product active; unique store+product |
| B3.2.02 | Pause / resume oferta | `todo` | S | |
| B3.2.03 | Ajuste de estoque (`adjustStock`) | `todo` | M | delta com lock; nunca negativo em available |
| B3.2.04 | Soft delete oferta (só se reserved=0) | `todo` | S | 409 se há reserva |
| B3.2.05 | Policy `OfferPolicy` | `todo` | M | |
| B3.2.06 | Form Requests + `OfferResource` | `todo` | M | expor `current_price_cents`, `available_stock` |
| B3.2.07 | Rotas `seller/offers` | `todo` | M | CRUD + pause/resume |
| B3.2.08 | Admin list/pause oferta | `todo` | S | |
| B3.2.09 | Feature tests CRUD + isolamento IDOR | `todo` | M | |

**Endpoints sugeridos:**

```http
GET    /api/v1/seller/offers
POST   /api/v1/seller/offers
GET    /api/v1/seller/offers/{offer}
PUT    /api/v1/seller/offers/{offer}
POST   /api/v1/seller/offers/{offer}/pause
POST   /api/v1/seller/offers/{offer}/resume
PATCH  /api/v1/seller/offers/{offer}/stock   # { "delta": 5 } ou { "absolute": 20 }
DELETE /api/v1/seller/offers/{offer}
```

### Slice B3.3 — Buy Box e detalhe público enriquecido

| ID | Item | Status | Esforço | Detalhe |
|----|------|--------|---------|---------|
| B3.3.01 | `BuyBoxService::resolve(Product): ?Offer` | `todo` | M | regras ordenadas abaixo |
| B3.3.02 | Listar ofertas elegíveis do produto | `todo` | M | store active, offer active, available>0 |
| B3.3.03 | Enriquecer `GET /products/{slug}` com `buy_box` + `offers[]` | `todo` | M | |
| B3.3.04 | Listagem pública: opcional `min_price_cents` (subquery/join) | `todo` | L | cuidado N+1 |
| B3.3.05 | Testes: 2 sellers, empates preço/estoque/idade | `todo` | M | |

**Algoritmo Buy Box (MVP — documentar e testar):**

```text
candidatos = offers where
  status=active
  AND store.status=active
  AND product.status=active
  AND (stock - reserved_stock) > 0
  AND (promotional vigente OU price)

ordenar por:
  1. current_price_cents ASC
  2. available_stock DESC
  3. created_at ASC
limite 1
```

### Slice B3.4 — Inventário (reserve / release / commit / expire)

| ID | Item | Status | Esforço | Detalhe |
|----|------|--------|---------|---------|
| B3.4.01 | `InventoryService::reserve(offer, qty, user|cart, ttl, idemKey)` | `todo` | L | transaction + lock offer; increment reserved; create reservation |
| B3.4.02 | `release(reservation)` | `todo` | M | decrementa reserved; status released |
| B3.4.03 | `commit(reservation)` | `todo` | M | stock-=qty; reserved-=qty; status committed |
| B3.4.04 | Expiração agendada de reservas e pedidos não pagos | `done` | M | `reservations:release-expired` libera só reservas avulsas (sem `order_id`); reservas de pedido seguem `orders.payment_due_at` via `orders:expire-unpaid`, que cancela o pedido pelo fluxo de cancelamento. Ambos rodam a cada minuto no serviço `scheduler` do compose |
| B3.4.05 | Endpoint interno/admin ou seller debug (opcional) | `todo` | S | MVP: só service; HTTP na B4 via cart |
| B3.4.06 | Teste concorrência: 2 reserves no último item | `todo` | L | `Http::pool` ou parallel processes / DB locks assert |
| B3.4.07 | Idempotência de reserve por key | `todo` | M | mesma key retorna mesma reservation |

**Invariantes (assert em testes):**

```text
0 <= reserved_stock <= stock
available = stock - reserved_stock
commit: stock e reserved diminuem juntos
release/expire: só reserved diminui
```

### Slice B3.5 — Hardening B3

| ID | Item | Status | Esforço | Detalhe |
|----|------|--------|---------|---------|
| B3.5.01 | Store suspended → ofertas inelegíveis Buy Box | `todo` | S | |
| B3.5.02 | Product paused/rejected → ofertas ocultas | `todo` | S | |
| B3.5.03 | Seeder multi-oferta no mesmo produto | `todo` | S | |
| B3.5.04 | Atualizar README + roadmap status B3 | `todo` | S | |

**Critério de pronto B3:** checklist do roadmap §B3 + testes de oversell.

---

# B4 — Carrinho, checkout e pedidos

**Dependência:** B3.4 (reserve/release/commit) `done`.

### Slice B4.1 — Endereços do buyer

| ID | Item | Status | Esforço | Detalhe técnico |
|----|------|--------|---------|-----------------|
| B4.1.01 | Migration `addresses` | `done` | M | user_id, label, recipient_name, phone, cep, street, number, complement, district, city, state, is_default, softDeletes |
| B4.1.02 | Validação CEP BR (`#####-###` ou 8 dígitos) | `done` | S | |
| B4.1.03 | CRUD `/api/v1/addresses` + Policy owner | `done` | M | um default por user (transaction) |
| B4.1.04 | Feature tests IDOR endereço | `done` | S | |

### Slice B4.2 — Carrinho

| ID | Item | Status | Esforço | Detalhe |
|----|------|--------|---------|---------|
| B4.2.01 | Migration `carts` + `cart_items` | `done` | M | cart 1:1 user; item unique `(cart_id, offer_id)` |
| B4.2.02 | `CartService::add/update/remove/clear` | `done` | L | valida offer active, store active, available stock |
| B4.2.03 | Recalcular totais no Resource (não persistir preço ainda) | `done` | S | preço vigente da offer |
| B4.2.04 | Endpoints `/api/v1/cart` | `done` | M | GET/POST items/PATCH/DELETE |
| B4.2.05 | Feature tests carrinho multi-seller | `done` | M | |

**Endpoints:**

```http
GET    /api/v1/cart
POST   /api/v1/cart/items          # { offer_id, quantity }
PATCH  /api/v1/cart/items/{item}   # { quantity }
DELETE /api/v1/cart/items/{item}
DELETE /api/v1/cart
```

### Slice B4.3 — Schema de pedidos

| ID | Item | Status | Esforço | Detalhe |
|----|------|--------|---------|---------|
| B4.3.01 | Migration `orders` | `done` | M | buyer_id, status, currency, subtotal/shipping/total cents, address snapshot cols, idempotency_key unique per buyer |
| B4.3.02 | Migration `order_sellers` | `done` | M | order_id, store_id, status, shipping_* nullable (B6), totals |
| B4.3.03 | Migration `order_items` | `done` | M | order_seller_id, offer_id nullable, product_id, snapshots: name, slug, unit_price_cents, quantity, store_id |
| B4.3.04 | Migration `order_status_histories` | `done` | S | morph order/order_seller, from, to, actor, reason |
| B4.3.05 | Enums OrderStatus / OrderSellerStatus | `done` | S | alinhar FSM roadmap |
| B4.3.06 | Models + factories | `done` | M | |

### Slice B4.4 — Checkout atômico

| ID | Item | Status | Esforço | Detalhe |
|----|------|--------|---------|---------|
| B4.4.01 | `CheckoutService::checkout(user, addressId, idempotencyKey)` | `done` | XL | ver passos |
| B4.4.02 | Agrupar cart items por `store_id` → N `order_sellers` | `done` | M | |
| B4.4.03 | Snapshot endereço + itens | `done` | M | |
| B4.4.04 | Reservar estoque por item (InventoryService) | `done` | M | falha → rollback total 409; `order_id` na reservation |
| B4.4.05 | Limpar carrinho após sucesso | `done` | S | |
| B4.4.06 | `POST /api/v1/checkout` + header `Idempotency-Key` | `done` | M | |
| B4.4.07 | Testes: multi-seller, estoque insuficiente, idempotência | `done` | L | |

**Passos de `CheckoutService` (uma transaction):**

```text
1. lock cart + items
2. revalidar cada offer (status, preço, store, product)
3. validar address ownership
4. para cada item: InventoryService::reserve(..., cart_id)
5. criar order (pending_payment) + order_sellers + order_items
6. gravar histories
7. clear cart
8. commit
```

### Slice B4.5 — Consulta e FSM de cancelamento

| ID | Item | Status | Esforço | Detalhe |
|----|------|--------|---------|---------|
| B4.5.01 | `OrderStateMachine` (transições permitidas) | `done` | L | tabela de transitions; 409 inválida |
| B4.5.02 | Buyer: list/show próprios orders | `done` | M | |
| B4.5.03 | Seller: list/show próprios `order_sellers` | `done` | M | Policy store ownership |
| B4.5.04 | Admin: list/show tudo | `done` | S | |
| B4.5.05 | Buyer cancela em `pending_payment` → release reservas | `done` | M | |
| B4.5.06 | Seller cancela `order_seller` em estados iniciais | `done` | M | release parcial; cancela order se todos sellers cancelados |
| B4.5.07 | Histórico de status na resposta | `done` | S | |
| B4.5.08 | Feature tests FSM matrix | `done` | L | |

**Endpoints:**

```http
GET    /api/v1/orders
GET    /api/v1/orders/{order}
POST   /api/v1/orders/{order}/cancel
GET    /api/v1/seller/order-sellers
GET    /api/v1/seller/order-sellers/{orderSeller}
POST   /api/v1/seller/order-sellers/{orderSeller}/cancel
GET    /api/v1/admin/orders
```

---

# B5 — Pagamento mock, comissão e ledger

**Dependência:** B4 checkout `done`.

### Slice B5.1 — Pagamentos mock

| ID | Item | Status | Esforço | Detalhe |
|----|------|--------|---------|---------|
| B5.1.01 | Migration `payments` | `done` | M | order_id, method enum, status, external_id, amount_cents, payload mock JSON; índice parcial: 1 pagamento `pending`/`paid` por order |
| B5.1.02 | Migration `payment_webhook_events` | `done` | S | unique (provider, event_id), processed_at, result |
| B5.1.03 | `PaymentService::createIntent` | `done` | M | pix/card/boleto payloads fake; `Idempotency-Key`; novo intent cancela o pendente anterior |
| B5.1.04 | Webhook mock `POST /api/v1/webhooks/payments/mock` | `done` | L | header `X-Mock-Webhook-Secret` (fail-closed); idempotente; Job `ProcessPaymentWebhookEvent` |
| B5.1.05 | Ao pagar: commit reservas + FSM order/order_sellers → paid | `done` | L | subpedidos cancelados não são cobrados nem transicionados |
| B5.1.06 | Testes 3 métodos + webhook duplicado | `done` | M | `tests/Feature/Payment/PaymentWorkflowTest.php` |

**Endpoints:**

```http
GET    /api/v1/orders/{order}/payments
POST   /api/v1/orders/{order}/payments          # Idempotency-Key; { "method": "pix|credit_card|boleto" }
POST   /api/v1/webhooks/payments/mock           # X-Mock-Webhook-Secret; { event_id, type: payment.paid|payment.failed, data.external_id }
```

**Regras de consistência:**

- Cancelar pedido (buyer) ou subpedido (seller) em `pending_payment` cancela intents pendentes — o valor a pagar mudou.
- Webhook `payment.paid` para pagamento não pendente ou pedido fora de `pending_payment` é registrado como no-op (`result`), sem alterar estado.
- Ordem de lock: order → payment → order_sellers → reservations (mesma do cancelamento, evita deadlock).

### Slice B5.2 — Comissão e ledger

| ID | Item | Status | Esforço | Detalhe |
|----|------|--------|---------|---------|
| B5.2.01 | Migration `platform_fee_rules` | `done` | S | percent_bps ou fixed; vigência (`starts_at`/`ends_at`); CHECK constraints no Postgres |
| B5.2.02 | Migration `ledger_entries` append-only | `done` | M | account_type (seller/platform), store_id nullable, order_seller_id, payment_id, platform_fee_rule_id, amount_cents signed, reason, `idempotency_key` única; trigger bloqueia UPDATE/DELETE |
| B5.2.03 | `LedgerService::postSale(orderSeller)` | `done` | L | crédito seller (`total − comissão`) + plataforma (comissão sobre `subtotal`); idempotente; chamado na confirmação do pagamento |
| B5.2.04 | Saldo seller = SUM(entries) | `done` | S | índice `(account_type, store_id)`; `GET /api/v1/seller/balance` + extrato |
| B5.2.05 | Config comissão MVP global (ex. 10%) | `done` | S | `PLATFORM_COMMISSION_BPS` (fallback) + `PlatformFeeRuleSeeder` |

**Endpoints:**

```http
GET    /api/v1/seller/balance
GET    /api/v1/seller/ledger-entries     # cursor
```

**Regras:**

- Comissão percentual arredonda half-up ao centavo; taxa fixa é limitada ao subtotal do subpedido.
- Regra vigente no momento do pagamento; sem regra no banco, usa config (`rule_source = config` no metadata).
- Soma dos lançamentos de um pagamento = `payments.amount_cents` (subpedidos cancelados não geram lançamentos).

### Slice B5.3 — Reembolso e payout

| ID | Item | Status | Esforço | Detalhe |
|----|------|--------|---------|---------|
| B5.3.01 | Migration `refunds` | `done` | M | por `order_seller`; payment_id, amount_cents > 0, reason, restocked, idempotency_key única por subpedido |
| B5.3.02 | Reembolso total/parcial + ledger contrário | `done` | L | admin; automático no cancelamento pelo seller de subpedido pago; comissão estornada proporcionalmente |
| B5.3.03 | Migration `payouts` | `done` | M | requested/paid/failed; idempotency_key única por loja |
| B5.3.04 | Seller request payout do disponível | `done` | M | disponível = SUM(ledger) − payouts `requested`; lock na loja |
| B5.3.05 | Admin marcar payout paid (mock) | `done` | S | `paid` lança débito `payout` no ledger; `failed` libera o valor |
| B5.3.06 | Testes financeiros (somas batem) | `done` | L | capturado − reembolsado − sacado = SUM(ledger) |

**Endpoints:**

```http
POST   /api/v1/admin/order-sellers/{orderSeller}/refunds   # Idempotency-Key; { amount_cents?, reason }
GET    /api/v1/seller/payouts                              # cursor
POST   /api/v1/seller/payouts                              # Idempotency-Key; { amount_cents }
GET    /api/v1/admin/payouts?status=                       # cursor
POST   /api/v1/admin/payouts/{payout}/mark-paid
POST   /api/v1/admin/payouts/{payout}/mark-failed          # { reason }
GET    /api/v1/seller/balance                              # balance_cents, pending_payout_cents, available_cents
```

**Regras decididas:**

- Reembolso é por subpedido, até `total_cents − já reembolsado`. Sem `amount_cents`, reembolsa o restante.
- Ledger contrário: plataforma devolve a comissão proporcional (half-up); o reembolso que zera o subpedido estorna o resto exato (sem drift de arredondamento). Seller cobre o restante.
- Quem reembolsa: admin (total/parcial) e reembolso total automático quando o seller cancela subpedido `paid`/`processing`. Comprador continua cancelando só em `pending_payment`.
- Reembolso total em `paid`/`processing` → subpedido `refunded` e estoque devolvido. Após `shipped` o status não muda e não há reposição (devolução física fica para B7).
- Pedido pai: todos os subpedidos `cancelled` → `cancelled`; mistura de `cancelled`/`refunded` → `refunded`.
- FSM: adicionado `processing → refunded` (order e order_seller).
- Saque: todo o saldo creditado é sacável (sem hold no MVP). Reembolso após saque pode deixar saldo negativo, o que bloqueia novos saques. Loja não ativa não saca.

---

# B6 — Frete simulado e envio

**Dependência:** B4 (order_sellers). Pode iniciar cotação após B4.3; tracking após B5 paid.

### Decisões técnicas da B6

| Decisão | Escolha |
|---------|---------|
| Onde ficam peso e medidas | No **produto**, não na oferta: são características físicas do item e valem para todos os sellers que o vendem |
| Destino do frete no ledger | O frete vai **inteiro para o seller**, sem comissão: o ledger calcula a comissão só sobre o subtotal de itens e credita ao seller o `total_cents` do subpedido (itens + frete) |
| Falta de regra de frete | **Não** existe "sem regra = frete grátis" em produção, porque isso esconderia uma configuração faltando. Sem regra que atenda, o checkout retorna 409 |
| Testes que não são sobre frete | Suítes de checkout, pedidos, pagamentos, ledger, reembolsos e saques chamam `ConfiguresShipping::useFreeShipping()`, que cria regras de preço zero, para manter os totais esperados iguais ao subtotal |
| Status do pedido pai durante o envio | O pedido acompanha o **subpedido mais atrasado** entre os ativos: só vira `shipped` quando todos os sellers enviaram e `delivered` quando todos entregaram. Subpedidos cancelados ou reembolsados não seguram o pedido. As transições passam por cada etapa (`paid → processing → shipped → delivered`) e ficam no histórico |
| Envio direto de `paid` | O seller pode enviar sem chamar "em separação" antes; o sistema registra `processing` e `shipped` em sequência |
| Quem confirma a entrega | O seller do subpedido ou um admin (não há transportadora real para confirmar) |

### Slice B6.1 — Cotação

| ID | Item | Status | Esforço | Detalhe |
|----|------|--------|---------|---------|
| B6.1.01 | Campos peso/dimensões em product + CEP de origem da loja | `done` | M | `products.weight_grams/length_cm/width_cm/height_cm` (nullable, fallback `shipping.default_package`); `stores.origin_cep` (fallback `shipping.default_origin_cep`) |
| B6.1.02 | Migration `shipping_rate_rules` | `done` | M | service_code, zone (`local`/`regional`/`national`), max_weight_grams, price_cents, eta_days; seeder com 2 serviços × 3 zonas × 3 faixas |
| B6.1.03 | `ShippingQuoteService::quoteStore/quoteCart` | `done` | L | zona pelo prefixo do CEP; peso faturável = max(real, volumétrico ÷ 6000) × qty; menor faixa que comporta o peso |
| B6.1.04 | Integrar cotação no checkout (por order_seller) | `done` | L | congela `shipping_cents`, service, ETA e `shipping_quoted_at`; `shipping_service_code` opcional; serviço indisponível → 409 sem pedido |
| B6.1.05 | Endpoint pré-checkout `POST /shipping/quotes` | `done` | M | `{ cep }` ou `{ address_id }` (só do próprio buyer); cotação do carrinho por seller |
| B6.1.06 | Testes fixtures CEP/peso | `done` | M | `tests/Feature/Shipping/ShippingQuoteTest.php`; demais suites usam `ConfiguresShipping::useFreeShipping()` |

### Slice B6.2 — Shipments

| ID | Item | Status | Esforço | Detalhe |
|----|------|--------|---------|---------|
| B6.2.01 | Migration `shipments` | `done` | M | 1 por subpedido (unique `order_seller_id`), `tracking_code` unique `SM#########BR`, carrier `simulated`, status `in_transit`/`delivered`, `shipped_at`, `estimated_delivery_at` (= envio + prazo cotado), `delivered_at` |
| B6.2.02 | Seller em separação e envio pós-paid → FSM shipped | `done` | M | `POST seller/order-sellers/{id}/processing` e `/shipments` (201); `FulfillmentService` |
| B6.2.03 | Marcar entregue (seller/admin) | `done` | M | `POST seller/order-sellers/{id}/deliver` e `POST admin/order-sellers/{id}/deliver`; job de entrega automática fica para depois |
| B6.2.04 | Buyer/seller veem tracking | `done` | S | bloco `shipment` em `OrderSellerResource` (pedido do buyer, lista e detalhe do seller) |
| B6.2.05 | Feature tests | `done` | M | `tests/Feature/Shipping/ShipmentWorkflowTest.php` |

---

# B7 — Pós-venda essencial

**Dependência:** delivered (B6) + ledger (B5) para disputa/RMA.

### Decisões técnicas da B7.1

| Decisão | Escolha |
|---------|---------|
| Unidade avaliada | Um **item de pedido** (`order_item_id` unique): cada compra entregue rende uma avaliação, e comprar de novo permite avaliar de novo |
| Nota agregada | Calculada por **query** (`withAvg`/`withCount` sobre índices de `product_id` e `store_id`), sem coluna denormalizada em `stores`/`products`. Denormalizar só se o volume pedir |
| Autoavaliação | Bloqueada: o dono da loja não avalia produto da própria loja, mesmo que tenha comprado (403) |
| Dados do autor | A listagem pública expõe só o **primeiro nome** (minimização LGPD); nunca e-mail ou id do usuário |
| Integridade da nota | Validação 1–5 no Form Request **e** `CHECK` no banco |
| Edição/remoção | Fora do escopo: a avaliação é imutável nesta entrega |

### Slice B7.1 — Reviews

| ID | Item | Status | Esforço | Detalhe |
|----|------|--------|---------|---------|
| B7.1.01 | Migration `product_reviews` | `done` | M | unique `order_item_id`; `user_id`, `product_id`, `store_id`; rating 1–5 com `CHECK`; `title`, `body` |
| B7.1.02 | Só após delivered + ownership do item | `done` | M | `ProductReviewPolicy` (comprador do item) + `ProductReviewService` (subpedido `delivered`, sem autoavaliação, duplicada → 409) |
| B7.1.03 | Agregar média do produto e do seller | `done` | M | `rating { average, count }` em `GET /products/{slug}` e `GET /stores/{slug}` via query |
| B7.1.04 | Endpoints create/list | `done` | M | `POST /order-items/{id}/reviews` (201); `GET /products/{slug}/reviews` público, cursor + `summary` |
| B7.1.05 | Testes 403 sem compra | `done` | S | `tests/Feature/Review/ProductReviewTest.php` (401/403/409/422, agregados) |

### Decisões técnicas da B7.2

| Decisão | Escolha |
|---------|---------|
| Trava do saque | **Derivada do status**, sem coluna `hold_payout`: enquanto a disputa está `open` ou a devolução está `requested`, o saldo de seller daquele subpedido entra em `held_cents` e sai do `available_cents` (`LedgerService::heldBalanceCents`) |
| Devolução também trava | Sim. Uma devolução aprovada debita o seller; travar durante a análise evita que ele saque um valor que pode ser estornado |
| Janela | 7 dias após `shipments.delivered_at`, configurável em `config/post_sale.php` (`dispute_window_days`, `return_window_days`) |
| Quantidade por subpedido | No máximo uma disputa e uma devolução por subpedido em toda a vida dele (unique `order_seller_id`), e nunca as duas em andamento ao mesmo tempo |
| Quem decide | Disputa: só o **admin** resolve; o seller pode registrar uma resposta. Devolução: o **seller** do subpedido ou um admin aprova/recusa (recusa exige justificativa) |
| Reembolso | Disputa resolvida a favor do comprador e devolução aprovada usam `OrderService::issueRefund` (mesmo fluxo da B5: ledger proporcional, idempotência por `dispute:{id}` / `return:{id}`) |
| Estoque | Sem reposição automática: a mercadoria já foi entregue e pode voltar avariada. O seller ajusta o estoque da oferta manualmente |
| FSM | `delivered → refunded` passou a ser permitido (subpedido e pedido), para o reembolso total pós-entrega fechar o subpedido. Reembolso parcial mantém `delivered` |

### Slice B7.2 — Disputas e RMA

| ID | Item | Status | Esforço | Detalhe |
|----|------|--------|---------|---------|
| B7.2.01 | Migration `disputes` | `done` | M | order_seller_id unique, opened_by, status (`open`/`resolved_refunded`/`resolved_rejected`), reason (`not_received`/`not_as_described`/`damaged`/`other`), description, resposta do seller, resolução, refund_id |
| B7.2.02 | Buyer abre disputa em janela (7 dias pós-delivered) | `done` | M | `POST /order-sellers/{id}/disputes`; `PostSaleEligibility` (comprador, `delivered`, janela, nada em andamento) |
| B7.2.03 | Admin resolve; libera/bloqueia payout | `done` | L | `POST /admin/disputes/{id}/resolve` (`refund` com valor opcional ou `reject`); seller responde em `POST /seller/disputes/{id}/respond`; `held_cents` no saldo |
| B7.2.04 | Migration `return_requests` (RMA) | `done` | M | order_seller_id unique, requested_by, status (`requested`/`approved`/`rejected`), reason, amount_cents, decisão, refund_id |
| B7.2.05 | Aprovar RMA → RefundService | `done` | L | `POST /order-sellers/{id}/returns`; seller e admin `.../returns/{id}/approve` e `/reject` |
| B7.2.06 | Feature tests fluxo mínimo | `done` | M | `tests/Feature/PostSale/DisputeWorkflowTest.php` e `ReturnRequestWorkflowTest.php` |

---

# L1 — Conta e higiene (`later`)

| ID | Item | Status | Esforço |
|----|------|--------|---------|
| L1.1.01 | Verificação de e-mail (notification + endpoint) | `later` | M |
| L1.1.02 | Forgot/reset password | `later` | M |
| L1.1.03 | Social login Google (Socialite) | `later` | L |
| L1.1.04 | Exigir e-mail verificado para seller/payout (flag) | `later` | S |
| L1.1.05 | Rate limit reforçado em reset | `later` | S |

---

# L2 — Marketing (`later`)

| ID | Item | Status | Esforço |
|----|------|--------|---------|
| L2.1.01 | Cupons (% / fixed / frete) | `later` | XL |
| L2.1.02 | Boost/ads de listagem | `later` | L |
| L2.1.03 | Wishlist | `later` | M |
| L2.1.04 | Q&A produto | `later` | M |
| L2.1.05 | Chat buyer-seller | `later` | XL |
| L2.1.06 | Notificações e-mail/in-app (fila) | `later` | L |

---

# L3 — Admin analytics / OpenAPI / API seller (`later`)

| ID | Item | Status | Esforço |
|----|------|--------|---------|
| L3.1.01 | Relatórios GMV / comissão / cancelamentos | `later` | L |
| L3.1.02 | OpenAPI (Scribe/L5-Swagger) | `later` | L |
| L3.1.03 | API seller tokens + webhooks HMAC | `later` | XL |
| L3.1.04 | Seeders demo ricos (jornada completa) | `later` | M |

---

## Itens transversais (backlog contínuo)

| ID | Item | Status | Esforço | Quando |
|----|------|--------|---------|--------|
| X.1.01 | `preventLazyLoading()` em local/testing | `todo` | S | agora |
| X.1.02 | Padronizar dinheiro em centavos em todo o código | `todo` | M | B3 |
| X.1.03 | Job/Command limpeza tokens Sanctum expirados | `todo` | S | qualquer |
| X.1.04 | Health check DB/redis em `/up` estendido | `todo` | S | qualquer |
| X.1.05 | Atualizar README com fluxo develop + matriz endpoints | `todo` | M | após B3 |
| X.1.06 | Matriz de Policies documentada | `todo` | S | contínuo |
| X.1.07 | Teste de regressão full suite no CI | `todo` | M | L3/agora se houver CI |
| X.1.08 | Prazo de pagamento do pedido (`payment_due_at`) | `done` | M | Checkout define agora + `orders.unpaid_ttl_minutes` (60). Cada tentativa de pagamento só estende: Pix até a validade do QR (30 min), boleto até o fim do dia de vencimento (3 dias), cartão + TTL. Pagamento que chega depois do cancelamento é ignorado (`payment_not_pending`) |

---

## Ordem de execução recomendada (pós-criação de `develop`)

```text
AGORA (outro agente):
  B3.1 → B3.2 → B3.3 → B3.4 → B3.5
  merge → develop

PARALELO SEGURO (este agente, se ocioso):
  B2.4.01–07  OU  X.1.01 / X.1.05

EM SEGUIDA:
  B4.1 → B4.2 → B4.3 → B4.4 → B4.5

DEPOIS:
  B5.*  (pagamento + ledger)
  B6.*  (frete; cotação pode iniciar após B4.3)
  B7.*  (reviews; disputa após B5+B6)

SÓ ENTÃO:
  L1 → L2 → L3
```

### Definition of Done por item

1. Migration reversível (quando houver schema)  
2. Service com regra de negócio (não no Controller)  
3. Form Request + Policy + Resource  
4. Rotas versionadas `/api/v1`  
5. Feature tests (feliz + 401/403/404/409/422 relevantes)  
6. Pint limpo nos arquivos tocados  
7. Audit nos estados críticos  
8. Sem preço/estoque em `products` (permanece em `offers`)

---

## Referência rápida de arquivos atuais (B1–B2)

| Área | Caminhos |
|------|----------|
| Auth | `app/Http/Controllers/AuthController.php`, `app/Services/AuthService.php` |
| Store | `app/Services/StoreService.php`, `app/Http/Controllers/StoreController.php`, `Admin/StoreController.php` |
| Category | `app/Services/CategoryService.php`, `CategoryController`, `Admin/CategoryController` |
| Product | `app/Services/ProductService.php`, `Seller/ProductController`, `Admin/ProductController` |
| Público | `ProductController`, `StoreController::show`, `PublicStoreResource` |
| Rotas | `routes/api.php` |
| Testes | `tests/Feature/{Auth,Store,Category,Product,Catalog}` |

---

_Atualize o campo Status deste backlog a cada merge em `develop`. O roadmap narrativo permanece em [`roadmap-implementacao-api.md`](roadmap-implementacao-api.md)._
