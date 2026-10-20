<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Unpaid Orders
    |--------------------------------------------------------------------------
    |
    | Minutes a new order (or a card payment attempt) keeps its stock
    | reserved while waiting for payment. Pix and boleto attempts extend the
    | deadline to their own expiry. Overdue orders are cancelled by the
    | scheduled orders:expire-unpaid command, which releases the stock.
    |
    */

    'unpaid_ttl_minutes' => (int) env('ORDERS_UNPAID_TTL_MINUTES', 60),

];
