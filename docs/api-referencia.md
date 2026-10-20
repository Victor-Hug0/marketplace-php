# Referência da API — Marketplace

Referência de todos os endpoints de `/api/v1` (96 rotas). Gerada a partir de `routes/api.php`, dos Form Requests e dos controllers em 20/10/2026.

Regras de negócio, máquinas de estado e autorização em detalhe: [`fluxos-e-regras.md`](fluxos-e-regras.md).

---

## 1. Convenções

### Base e headers

| Item | Valor |
|------|-------|
| Base URL local | `http://127.0.0.1:8080/api/v1` |
| Formato | JSON. As respostas de `/api/*` são sempre JSON, inclusive erros (middleware `ForceJsonResponse`) |
| Autenticação | `Authorization: Bearer <token>` (Sanctum) |
| Idempotência | `Idempotency-Key: <8 a 128 caracteres>` nos endpoints marcados com **[idem]** |
| Webhook mock | `X-Mock-Webhook-Secret: <PAYMENTS_MOCK_WEBHOOK_SECRET>` |

### Tokens

| Token | Ability | Validade | Uso |
|-------|---------|----------|-----|
| `access_token` | `access` | 1 hora | todas as rotas autenticadas |
| `refresh_token` | `refresh` | 30 dias | só `POST /auth/refresh` |

Login e refresh emitem um par novo e apagam os pares anteriores do usuário: existe uma sessão ativa por usuário. O refresh token não funciona em rotas de `access`, nem o access token no refresh (401).

### Níveis de acesso usados nas tabelas

| Rótulo | Significado |
|--------|-------------|
| Público | sem token |
| Autenticado | Bearer `access` |
| Seller | Bearer `access` + papel `seller` (middleware `role:seller`; o papel só existe com loja aprovada) |
| Admin | Bearer `access` + papel `admin` |
| Dono | além do nível acima, o recurso precisa pertencer ao usuário (Policy). Recurso de outro usuário responde **404** na maioria das rotas, para não revelar que existe |

### Envelope de resposta

Sucesso (recurso único):

```json
{
  "data": { "id": "01a0...", "status": "active" },
  "message": "Opcional, em ações de escrita."
}
```

Coleções paginadas por cursor:

```json
{
  "data": [ { "id": "..." } ],
  "links": { "first": null, "last": null, "prev": null, "next": "...?cursor=eyJ..." },
  "meta": { "path": "...", "per_page": 20, "next_cursor": "eyJ...", "prev_cursor": null }
}
```

Parâmetros de paginação: `per_page` (padrão 20, máximo 100) e `cursor` (valor de `meta.next_cursor`).

Erro:

```json
{
  "message": "Os dados enviados são inválidos.",
  "timestamp": "2026-10-20T10:00:00-03:00",
  "errors": { "email": ["O campo e-mail é obrigatório."] }
}
```

### Códigos de status

| Código | Quando |
|--------|--------|
| `200` | consulta, atualização, ação de estado; **replay idempotente** (mesma `Idempotency-Key`) |
| `201` | criação |
| `202` | webhook aceito e enfileirado |
| `204` | remoção, logout, limpar carrinho |
| `401` | sem token, token inválido/expirado, ability errada, segredo de webhook errado |
| `403` | autenticado sem permissão (papel ou Policy) |
| `404` | recurso inexistente ou de outro usuário |
| `409` | conflito de estado ou regra de negócio (transição inválida, estoque insuficiente, duplicidade, prazo vencido) |
| `422` | validação do Form Request (inclui `Idempotency-Key` ausente) |
| `429` | rate limit |

### Dinheiro

- **Ofertas** recebem e devolvem preço em reais com duas casas, como string decimal: `price`, `promotional_price`, `current_price` (ex.: `"99.90"`). O carrinho também mostra `unit_price`, `line_total` e `subtotal` nesse formato.
- **Do checkout em diante** tudo é inteiro em centavos, com sufixo `_cents`: pedidos, itens, frete, pagamentos, ledger, reembolsos e saques (ex.: `9990`).

### Rate limits

| Rota | Limite |
|------|--------|
| `POST /auth/login` | 10/min por IP (rota) + 10 falhas por IP em 60 s (bloqueio manual, com `Retry-After`) |
| `POST /auth/register` | 10/min por IP (rota) + 10 tentativas por IP + e-mail |
| `POST /orders/{order}/payments` | 20/min |
| `POST /seller/payouts` | 10/min |
| `POST /order-items/{orderItem}/reviews` | 20/min |
| `POST /order-sellers/{orderSeller}/disputes` e `/returns` | 20/min |
| `POST /shipping/quotes` | 60/min |
| `POST /webhooks/payments/mock` | 120/min |

---

## 2. Autenticação e usuários

| Método | Rota | Acesso | Corpo | Sucesso | Erros |
|--------|------|--------|-------|---------|-------|
| `POST` | `/auth/register` | Público | `name`, `email` (único), `cpf` (11, único), `phone_number` (até 11), `password` + `password_confirmation` (mín. 8, até 64, maiúscula, minúscula, número e símbolo; fora de teste também checa vazamento) | `201` `{ message, user }` | `422`, `429` |
| `POST` | `/auth/login` | Público | `email`, `password` | `200` `{ message, user, access_token, refresh_token }` | `401` credenciais, `429` |
| `POST` | `/auth/logout` | Autenticado | — | `204` | `401` |
| `POST` | `/auth/refresh` | Bearer `refresh` | — | `200` `{ message, access_token, refresh_token }` | `401` |
| `GET` | `/users/me` | Autenticado | — | `200` `{ user }` com `roles` e resumo da `store` | `401` |
| `GET` | `/users` | Admin | `per_page`, `cursor` | `200` lista paginada | `401`, `403` |

Todo cadastro recebe o papel `buyer`.

---

## 3. Lojas

| Método | Rota | Acesso | Corpo / Query | Sucesso | Erros |
|--------|------|--------|---------------|---------|-------|
| `POST` | `/stores` | Autenticado (buyer) | `store_name`, `document_type` (`cpf`\|`cnpj`), `document` (11 ou 14 dígitos, único), `description?`, `commercial_email?`, `commercial_phone?` (10–15 dígitos), `origin_cep?` (CEP; gravado só com dígitos) | `201` loja `pending` | `409` usuário já tem loja (inclusive rejeitada), `422` |
| `GET` | `/stores/me` | Autenticado + Dono | — | `200` loja completa | `404` sem loja |
| `GET` | `/stores/{slug}` | Público | `q`, `category`, `category_id`, `per_page`, `cursor` | `200` vitrine (`data` sem documento nem motivo de rejeição; `data.rating`) + `products` paginados | `404` loja inexistente ou não `active` |
| `GET` | `/admin/stores` | Admin | `per_page`, `cursor` | `200` lista com dono | `403` |
| `POST` | `/admin/stores/{store}/approve` | Admin | — | `200` loja `active`; dono ganha papel `seller` | `409` não está `pending` |
| `POST` | `/admin/stores/{store}/reject` | Admin | `reason` (10–2000) | `200` loja `rejected` | `409`, `422` |
| `POST` | `/admin/stores/{store}/suspend` | Admin | `reason` (10–2000) | `200` loja `suspended`; dono perde o papel `seller` | `409` não está `active`, `422` |

---

## 4. Categorias

| Método | Rota | Acesso | Corpo | Sucesso | Erros |
|--------|------|--------|-------|---------|-------|
| `GET` | `/categories` | Público | — | `200` árvore das ativas (`children[]`) | — |
| `GET` | `/categories/{slug}` | Público | — | `200` categoria + `attributes[]` | `404` inexistente ou inativa |
| `GET` | `/admin/categories` | Admin | — | `200` árvore completa, inclusive inativas | `403` |
| `POST` | `/admin/categories` | Admin | `name`, `parent_id?`, `slug?` (gerado se ausente), `description?`, `position?`, `is_active?` | `201` | `422` mais de 3 níveis ou slug inválido |
| `PUT`/`PATCH` | `/admin/categories/{category}` | Admin | mesmos campos, todos opcionais | `200` | `422` ciclo ou profundidade |
| `DELETE` | `/admin/categories/{category}` | Admin | — | `204` (soft delete) | `409` tem subcategorias ou produtos |
| `POST` | `/admin/categories/{category}/attributes` | Admin | `code` (único na categoria), `label`, `data_type` (`string`\|`integer`\|`decimal`\|`boolean`\|`enum`), `options[]` (obrigatório se `enum`), `is_required?`, `is_filterable?`, `position?` | `201` | `422` |
| `PUT` | `/admin/categories/{category}/attributes/{attribute}` | Admin | mesmos campos, opcionais | `200` | `422` |
| `DELETE` | `/admin/categories/{category}/attributes/{attribute}` | Admin | — | `204` | — |

---

## 5. Produtos e catálogo público

### Público

| Método | Rota | Query | Sucesso | Observações |
|--------|------|-------|---------|-------------|
| `GET` | `/products` | `q` (nome/descrição, até 120), `category` (slug) ou `category_id`, `store` (slug) ou `store_id`, `per_page`, `cursor` | `200` lista | só produto `active` de loja `active`; mais recentes primeiro |
| `GET` | `/products/{slug}` | — | `200` produto + `images[]` + `offers[]` elegíveis + `rating { average, count }`; `buy_box` como chave de topo | `404` se produto ou loja não estiver `active` |
| `GET` | `/products/{slug}/reviews` | `per_page`, `cursor` | `200` avaliações (autor só com primeiro nome) + `summary { average, count }` | `404` |

### Seller (dono da loja)

| Método | Rota | Corpo | Sucesso | Erros |
|--------|------|-------|---------|-------|
| `GET` | `/seller/products` | `per_page`, `cursor` | `200` produtos da própria loja | `403` |
| `POST` | `/seller/products` | `category_id` (ativa), `name` (3–180), `slug?`, `description?`, `weight_grams?` (1–100000), `length_cm?`, `width_cm?`, `height_cm?` (1–300) | `201` produto `draft` | `403` loja não ativa, `422` |
| `GET` | `/seller/products/{product}` | — | `200` | `404` de outra loja |
| `PUT`/`PATCH` | `/seller/products/{product}` | mesmos campos, opcionais | `200` | `409` fora de `draft`/`rejected` |
| `DELETE` | `/seller/products/{product}` | — | `204` (soft delete, apaga as imagens do disco) | `409` fora de `draft`/`rejected`/`paused` |
| `POST` | `/seller/products/{product}/submit` | — | `200` `pending_moderation` | `409` fora de `draft`/`rejected` |
| `POST` | `/seller/products/{product}/pause` | — | `200` `paused` | `409` não está `active` |
| `POST` | `/seller/products/{product}/resume` | — | `200` `active` | `409` não está `paused` |
| `POST` | `/seller/products/{product}/images` | multipart: `image` (jpeg, jpg, png, webp; até 2 MB), `alt?` | `201` imagem; a primeira vira capa (`position` 0) | `409` limite de 8 imagens, `422` |
| `PUT` | `/seller/products/{product}/images/reorder` | `image_ids[]` (UUIDs distintos: todas as imagens do produto, na nova ordem) | `200` | `409` lista diferente das imagens do produto, `422` |
| `DELETE` | `/seller/products/{product}/images/{image}` | — | `204` | `404` |

### Admin

| Método | Rota | Corpo / Query | Sucesso | Erros |
|--------|------|---------------|---------|-------|
| `GET` | `/admin/products` | `status?`, `per_page`, `cursor` | `200` | `403` |
| `GET` | `/admin/products/{product}` | — | `200` | `404` |
| `PUT`/`PATCH` | `/admin/products/{product}` | mesmos campos do seller | `200` (admin edita em qualquer status) | `422` |
| `POST` | `/admin/products/{product}/approve` | — | `200` `active` | `409` não está `pending_moderation` |
| `POST` | `/admin/products/{product}/reject` | `reason` (10–2000) | `200` `rejected` com motivo | `409`, `422` |
| `POST` | `/admin/products/{product}/pause` | — | `200` `paused` | `409` não está `active` |

---

## 6. Ofertas e reservas

| Método | Rota | Acesso | Corpo | Sucesso | Erros |
|--------|------|--------|-------|---------|-------|
| `GET` | `/seller/offers` | Seller | `per_page`, `cursor` | `200` ofertas da própria loja | `403` |
| `POST` | `/seller/offers` | Seller (loja `active`) | `product_id` (produto `active`, pode ser de outra loja), `price` (> 0, 2 casas), `promotional_price?` (< `price`), `promo_starts_at?`, `promo_ends_at?` (> início), `stock` (≥ 0) | `201` oferta `active` | `409` já existe oferta da loja para o produto, `422` |
| `GET` | `/seller/offers/{offer}` | Seller + Dono | — | `200` com `stock` e `reserved_stock` | `404` |
| `PUT`/`PATCH` | `/seller/offers/{offer}` | Seller + Dono | `price?`, `promotional_price?`, `promo_starts_at?`, `promo_ends_at?`, `stock?` | `200` | `409` `stock` menor que o reservado, `422` |
| `POST` | `/seller/offers/{offer}/pause` | Seller + Dono | — | `200` `paused` | `409` |
| `POST` | `/seller/offers/{offer}/resume` | Seller + Dono | — | `200` `active` | `409` |
| `DELETE` | `/seller/offers/{offer}` | Seller + Dono | — | `204` (soft delete) | `409` há reservas ativas |
| `POST` | `/offers/{offer}/reservations` | Autenticado | `quantity` (≥ 1) | `201` reserva `active` por 15 min | `409` oferta pausada, loja inativa ou estoque insuficiente |
| `DELETE` | `/offers/{offer}/reservations/{reservation}` | Autenticado + Dono | — | `204` | `403` reserva de outro usuário, `404` reserva de outra oferta, `409` já liberada/consumida |

Para o público, a oferta expõe `current_price` e `available_stock`; `stock` e `reserved_stock` só aparecem para o dono e para o admin.

---

## 7. Endereços

| Método | Rota | Corpo | Sucesso | Erros |
|--------|------|-------|---------|-------|
| `GET` | `/addresses` | — | `200` endereços do usuário (padrão primeiro) | `401` |
| `POST` | `/addresses` | `recipient_name` (2–120), `phone` (10–20), `cep` (`#####-###` ou 8 dígitos), `street`, `number`, `complement?`, `district`, `city`, `state` (UF válida), `label?`, `is_default?` | `201`; o primeiro endereço vira padrão | `422` |
| `GET` | `/addresses/{address}` | — | `200` | `404` de outro usuário |
| `PUT`/`PATCH` | `/addresses/{address}` | mesmos campos, opcionais | `200` | `403`/`404` de outro usuário, `409` tentar tirar o padrão sem definir outro |
| `POST` | `/addresses/{address}/default` | — | `200`; desmarca o anterior | `404` |
| `DELETE` | `/addresses/{address}` | — | `204` (soft delete); se era o padrão, o mais antigo restante vira padrão | `404` |

---

## 8. Carrinho, frete e checkout

| Método | Rota | Acesso | Corpo | Sucesso | Erros |
|--------|------|--------|-------|---------|-------|
| `GET` | `/cart` | Autenticado | — | `200` `{ items[], items_count, subtotal, currency }` (preços vigentes, em reais) | `401` |
| `POST` | `/cart/items` | Autenticado | `offer_id`, `quantity` (1–999) | `201` carrinho; mesma oferta soma a quantidade | `409` oferta pausada, loja ou produto inativo, estoque insuficiente |
| `PATCH` | `/cart/items/{item}` | Autenticado + Dono | `quantity` (1–999) | `200` carrinho | `404`, `409` estoque |
| `DELETE` | `/cart/items/{item}` | Autenticado + Dono | — | `200` carrinho | `404` |
| `DELETE` | `/cart` | Autenticado | — | `204` | — |
| `POST` | `/shipping/quotes` | Autenticado | `cep` **ou** `address_id` (do próprio usuário) | `200` uma cotação por loja do carrinho: `zone`, `billable_weight_grams`, `options[] { service_code, service_name, price_cents, eta_days }` da mais barata para a mais cara | `404` endereço de outro usuário, `409` carrinho vazio, `422` |
| `POST` | `/checkout` **[idem]** | Autenticado | `address_id`, `shipping_service_code?` (padrão `simulated_standard`) | `201` pedido `pending_payment`; `200` em replay da mesma chave | `404` endereço de outro usuário, `409` carrinho vazio, oferta/loja/produto inativo, estoque insuficiente ou frete indisponível para alguma loja, `422` |

O checkout é uma transação única: ou cria o pedido inteiro (subpedidos, itens com preço congelado, frete congelado, reservas) e esvazia o carrinho, ou não grava nada.

---

## 9. Pedidos

| Método | Rota | Acesso | Corpo | Sucesso | Erros |
|--------|------|--------|-------|---------|-------|
| `GET` | `/orders` | Autenticado | `per_page`, `cursor` | `200` pedidos do comprador (mais recentes primeiro) | `401` |
| `GET` | `/orders/{order}` | Autenticado + Dono | — | `200` pedido com `payment_due_at`, `address_snapshot`, `sellers[]` (itens, frete, `shipment`, `refunds`, `dispute`, `return_request`, histórico) e histórico do pedido | `404` |
| `POST` | `/orders/{order}/cancel` | Autenticado + Dono | `reason?` (até 500) | `200` pedido `cancelled`; reservas liberadas e pagamentos pendentes cancelados | `409` fora de `pending_payment` |
| `GET` | `/seller/order-sellers` | Seller | `per_page`, `cursor` | `200` subpedidos da própria loja | `403` |
| `GET` | `/seller/order-sellers/{orderSeller}` | Seller + Dono | — | `200` | `404` |
| `POST` | `/seller/order-sellers/{orderSeller}/cancel` | Seller + Dono | `reason?` | `200` subpedido `cancelled`; se já pago, reembolso automático do restante com reposição de estoque | `409` já enviado ou encerrado |
| `GET` | `/admin/orders` | Admin | `per_page`, `cursor` | `200` todos os pedidos | `403` |
| `GET` | `/admin/orders/{order}` | Admin | — | `200` | `404` |

---

## 10. Envio

| Método | Rota | Acesso | Sucesso | Erros |
|--------|------|--------|---------|-------|
| `POST` | `/seller/order-sellers/{orderSeller}/processing` | Seller + Dono | `200` `processing` | `409` não está `paid` |
| `POST` | `/seller/order-sellers/{orderSeller}/shipments` | Seller + Dono | `201` `shipped` + `shipment { tracking_code (SM#########BR), carrier, service_code, status, shipped_at, estimated_delivery_at }`; aceita `paid` (registra `processing` antes) | `409` não pago, já enviado ou encerrado |
| `POST` | `/seller/order-sellers/{orderSeller}/deliver` | Seller + Dono | `200` `delivered` | `409` não está `shipped` |
| `POST` | `/admin/order-sellers/{orderSeller}/deliver` | Admin | `200` `delivered` | `409` |

---

## 11. Pagamentos

| Método | Rota | Acesso | Corpo | Sucesso | Erros |
|--------|------|--------|-------|---------|-------|
| `GET` | `/orders/{order}/payments` | Autenticado + Dono | — | `200` tentativas de pagamento | `404` |
| `POST` | `/orders/{order}/payments` **[idem]** | Autenticado + Dono | `method` (`pix`\|`credit_card`\|`boleto`) | `201` pagamento `pending` com `instructions` (Pix: `qr_code_text`, `expires_at`; boleto: `digitable_line`, `barcode`, `due_date`; cartão: `checkout_url`); `200` em replay. Cancela a tentativa pendente anterior e pode estender `payment_due_at` | `404`, `409` pedido fora de `pending_payment`, chave usada com outro método, valor zero |
| `POST` | `/webhooks/payments/mock` | Segredo no header | `event_id` (único), `type` (`payment.paid`\|`payment.failed`), `data.external_id` | `202` enfileirado; `200` evento repetido | `401` segredo ausente ou errado (inclusive se `PAYMENTS_MOCK_WEBHOOK_SECRET` estiver vazio), `404` pagamento desconhecido, `409` `event_id` reusado em outro evento, `422` |

O webhook é processado pelo job `ProcessPaymentWebhookEvent` (fila Redis, até 5 tentativas). O resultado fica em `payment_webhook_events.result`: `applied`, `already_paid`, `payment_not_pending` ou `order_not_payable`.

---

## 12. Financeiro do seller e do admin

| Método | Rota | Acesso | Corpo / Query | Sucesso | Erros |
|--------|------|--------|---------------|---------|-------|
| `GET` | `/seller/balance` | Seller | — | `200` `{ balance_cents, pending_payout_cents, held_cents, available_cents, currency }` | `403` |
| `GET` | `/seller/ledger-entries` | Seller | `per_page`, `cursor` | `200` lançamentos da loja | `403` |
| `GET` | `/seller/payouts` | Seller | `per_page`, `cursor` | `200` saques da loja | `403` |
| `POST` | `/seller/payouts` **[idem]** | Seller | `amount_cents` (≥ 1) | `201` saque `requested`; `200` em replay | `409` loja não ativa, valor acima do disponível, chave reusada com outro valor |
| `POST` | `/admin/order-sellers/{orderSeller}/refunds` **[idem]** | Admin | `reason` (até 500), `amount_cents?` (padrão: todo o restante) | `201` reembolso; `200` em replay | `409` subpedido sem pagamento confirmado, valor fora de 1..restante, chave reusada com outro valor |
| `GET` | `/admin/payouts` | Admin | `status?` (`requested`\|`paid`\|`failed`), `per_page`, `cursor` | `200` | `403` |
| `POST` | `/admin/payouts/{payout}/mark-paid` | Admin | — | `200` `paid`; lançamento negativo no ledger | `409` não está `requested` |
| `POST` | `/admin/payouts/{payout}/mark-failed` | Admin | `reason` (até 500) | `200` `failed`; valor volta ao disponível | `409`, `422` |

---

## 13. Avaliações

| Método | Rota | Acesso | Corpo | Sucesso | Erros |
|--------|------|--------|-------|---------|-------|
| `POST` | `/order-items/{orderItem}/reviews` | Autenticado (comprador do item) | `rating` (1–5), `title?` (até 120), `body?` (até 2000) | `201` | `403` não é o comprador ou é o dono da loja, `409` subpedido não entregue ou item já avaliado, `422` |
| `GET` | `/products/{slug}/reviews` | Público | `per_page`, `cursor` | `200` (ver seção 5) | `404` |

---

## 14. Disputas e devoluções

| Método | Rota | Acesso | Corpo / Query | Sucesso | Erros |
|--------|------|--------|---------------|---------|-------|
| `POST` | `/order-sellers/{orderSeller}/disputes` | Autenticado (comprador) | `reason` (`not_received`\|`not_as_described`\|`damaged`\|`other`), `description` (10–2000) | `201` disputa `open`; saldo do subpedido passa a `held_cents` | `404` não é o comprador, `409` não entregue, fora dos 7 dias, já houve disputa ou há disputa/devolução em andamento, `422` |
| `POST` | `/order-sellers/{orderSeller}/returns` | Autenticado (comprador) | `reason` (10–2000), `amount_cents?` (padrão: todo o restante) | `201` devolução `requested`; saldo do subpedido passa a `held_cents` | `404`, `409` mesmas regras + valor fora de 1..restante, `422` |
| `GET` | `/seller/disputes` | Seller | `per_page`, `cursor` | `200` disputas da própria loja | `403` |
| `POST` | `/seller/disputes/{dispute}/respond` | Seller + Dono | `response` (5–2000) | `200` | `404`, `409` disputa encerrada |
| `GET` | `/seller/returns` | Seller | `per_page`, `cursor` | `200` devoluções da própria loja | `403` |
| `POST` | `/seller/returns/{returnRequest}/approve` | Seller + Dono | `notes?` | `200` `approved` + reembolso | `404`, `409` já decidida |
| `POST` | `/seller/returns/{returnRequest}/reject` | Seller + Dono | `notes` (5–2000) | `200` `rejected` | `404`, `409`, `422` |
| `GET` | `/admin/disputes` | Admin | `status?` (`open`\|`resolved_refunded`\|`resolved_rejected`) | `200` | `403` |
| `POST` | `/admin/disputes/{dispute}/resolve` | Admin | `outcome` (`refund`\|`reject`), `amount_cents?` (só com `refund`; padrão: todo o restante), `notes` (5–2000) | `200` `resolved_refunded` ou `resolved_rejected` | `409` já encerrada, `422` |
| `GET` | `/admin/returns` | Admin | `status?` (`requested`\|`approved`\|`rejected`) | `200` | `403` |
| `POST` | `/admin/returns/{returnRequest}/approve` | Admin | `notes?` | `200` `approved` + reembolso | `409` |
| `POST` | `/admin/returns/{returnRequest}/reject` | Admin | `notes` (5–2000) | `200` `rejected` | `409`, `422` |

---

## 15. Fora de `/api/v1`

| Método | Rota | Uso |
|--------|------|-----|
| `GET` | `/up` | health check do Laravel |
