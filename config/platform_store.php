<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform Store
    |--------------------------------------------------------------------------
    |
    | The marketplace also sells its own products (1P) through a regular store
    | flagged is_platform. PlatformStoreSeeder creates it once, owned by an
    | operator account with a random password: sign in through password reset.
    | Its sales follow the same ledger as any seller, commission included.
    |
    */

    'store_name' => env('PLATFORM_STORE_NAME', 'Marketplace Oficial'),
    'slug' => env('PLATFORM_STORE_SLUG', 'marketplace-oficial'),
    'document' => env('PLATFORM_STORE_CNPJ', '00000000000191'),
    'commercial_email' => env('PLATFORM_STORE_EMAIL', 'loja@marketplace.test'),
    'origin_cep' => env('PLATFORM_STORE_ORIGIN_CEP', env('SHIPPING_DEFAULT_ORIGIN_CEP', '01310100')),
    'owner_email' => env('PLATFORM_STORE_OWNER_EMAIL', 'plataforma@marketplace.test'),
    'owner_name' => env('PLATFORM_STORE_OWNER_NAME', 'Operação Marketplace'),

];
