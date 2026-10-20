<?php

use Knuckles\Scribe\Config\AuthIn;
use Knuckles\Scribe\Config\Defaults;
use Knuckles\Scribe\Extracting\Strategies;

use function Knuckles\Scribe\Config\removeStrategies;

// Full reference: https://scribe.knuckles.wtf/laravel/reference/config
// Regenerate with `php artisan scribe:generate` (needs the database: example
// responses are built from API Resources and factories inside a rolled-back transaction).

return [
    'title' => 'Marketplace API',

    'description' => 'API REST de um marketplace multi-seller: lojas, catálogo, ofertas com Buy Box, checkout idempotente, pagamento simulado, ledger, frete, envio e pós-venda.',

    'intro_text' => <<<'INTRO'
        Todos os endpoints ficam em `/api/v1` e respondem JSON, inclusive os erros.

        ## Autenticação

        `POST /auth/login` devolve um par de tokens Sanctum:

        - `access_token` (1 hora): envie como `Authorization: Bearer {token}` em todas as rotas autenticadas.
        - `refresh_token` (30 dias): use só em `POST /auth/refresh`, que devolve um par novo.

        Rotas de `seller` exigem o papel `seller` (concedido quando o admin aprova a loja); rotas de `admin`, o papel `admin`.
        Recurso de outro usuário responde **404**.

        ## Convenções

        - **PUT e PATCH:** onde a documentação mostra `PUT`, a mesma rota também aceita `PATCH` com campos parciais.
        - **Idempotência:** endpoints com o header `Idempotency-Key` (8 a 128 caracteres) devolvem o mesmo recurso com **200** quando a chave se repete.
        - **Dinheiro:** ofertas e carrinho usam reais com duas casas como string (`"99.90"`); do checkout em diante tudo é inteiro em centavos (`*_cents`).
        - **Paginação:** listagens usam cursor (`per_page` até 100 e `cursor`, a partir de `meta.next_cursor`).
        - **Erros:** `{ "message", "timestamp", "errors" }`; 401 sem token, 403 sem permissão, 404 inexistente ou de terceiros, 409 conflito de estado ou regra de negócio, 422 validação, 429 rate limit.

        Regras de negócio e máquinas de estado: `docs/fluxos-e-regras.md` no repositório.
        INTRO,

    'base_url' => env('SCRIBE_BASE_URL', 'http://127.0.0.1:8080'),

    'routes' => [
        [
            'match' => [
                'prefixes' => ['api/*'],
                'domains' => ['*'],
            ],
            'include' => [],
            // PATCH duplicates of PUT routes would repeat the same operationId in the OpenAPI spec.
            'exclude' => [
                'PATCH api/v1/addresses/*',
                'PATCH api/v1/admin/categories/*',
                'PATCH api/v1/admin/products/*',
                'PATCH api/v1/seller/offers/*',
                'PATCH api/v1/seller/products/*',
            ],
        ],
    ],

    // Static HTML in public/docs, committed to the repository and served at /docs.
    'type' => 'static',

    'theme' => 'default',

    'static' => [
        'output_path' => 'public/docs',
    ],

    'laravel' => [
        'add_routes' => false,
        'docs_url' => '/docs',
        'assets_directory' => null,
        'middleware' => [],
    ],

    'external' => [
        'html_attributes' => [],
    ],

    'try_it_out' => [
        'enabled' => true,
        'base_url' => null,
        'use_csrf' => false,
        'csrf_url' => '/sanctum/csrf-cookie',
    ],

    'auth' => [
        'enabled' => true,
        'default' => true,
        'in' => AuthIn::BEARER->value,
        'name' => 'Authorization',
        'use_value' => env('SCRIBE_AUTH_KEY'),
        'placeholder' => '{ACCESS_TOKEN}',
        'extra_info' => 'Obtenha o token em <code>POST /api/v1/auth/login</code> e use o <code>access_token</code>.',
    ],

    'example_languages' => [
        'bash',
        'javascript',
    ],

    'postman' => [
        'enabled' => true,
        'overrides' => [],
    ],

    'openapi' => [
        'enabled' => true,
        'version' => '3.0.3',
        'overrides' => [
            'info.version' => '1.0.0',
        ],
        'generators' => [],
    ],

    'groups' => [
        'default' => 'Outros',
        'order' => [
            'Autenticação',
            'Usuários',
            'Lojas',
            'Categorias',
            'Produtos',
            'Ofertas e reservas',
            'Endereços',
            'Carrinho',
            'Frete',
            'Checkout',
            'Pedidos',
            'Pagamentos',
            'Envio',
            'Financeiro',
            'Avaliações',
            'Pós-venda',
        ],
    ],

    'logo' => false,

    // Fixed text keeps regenerated output identical when nothing changed.
    'last_updated' => 'Gerado a partir do código',

    'examples' => [
        'faker_seed' => 1234,
        'models_source' => ['factoryCreate', 'factoryMake', 'databaseFirst'],
    ],

    'strategies' => [
        'metadata' => [
            ...Defaults::METADATA_STRATEGIES,
        ],
        'headers' => [
            ...Defaults::HEADERS_STRATEGIES,
            Strategies\StaticData::withSettings(data: [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]),
        ],
        'urlParameters' => [
            ...Defaults::URL_PARAMETERS_STRATEGIES,
        ],
        'queryParameters' => [
            ...Defaults::QUERY_PARAMETERS_STRATEGIES,
        ],
        'bodyParameters' => [
            ...Defaults::BODY_PARAMETERS_STRATEGIES,
        ],
        // No live response calls: examples come from attributes, API Resources and factories.
        'responses' => removeStrategies(
            Defaults::RESPONSES_STRATEGIES,
            [Strategies\Responses\ResponseCalls::class],
        ),
        'responseFields' => [
            ...Defaults::RESPONSE_FIELDS_STRATEGIES,
        ],
    ],

    'database_connections_to_transact' => [config('database.default')],

    'fractal' => [
        'serializer' => null,
    ],
];
