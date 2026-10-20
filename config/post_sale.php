<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Post-sale Windows
    |--------------------------------------------------------------------------
    |
    | Days after the delivery is confirmed during which the buyer may open a
    | dispute or request a return for a sub-order.
    |
    */

    'dispute_window_days' => (int) env('POST_SALE_DISPUTE_WINDOW_DAYS', 7),

    'return_window_days' => (int) env('POST_SALE_RETURN_WINDOW_DAYS', 7),

];
