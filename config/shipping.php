<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Simulated Shipping
    |--------------------------------------------------------------------------
    |
    | Internal rate table used while there is no real carrier integration.
    | Prices and ETAs come from shipping_rate_rules; these values only cover
    | what the rule table cannot know.
    |
    */

    'default_service_code' => env('SHIPPING_DEFAULT_SERVICE', 'simulated_standard'),

    // Used for stores that have not registered an origin CEP yet.
    'default_origin_cep' => env('SHIPPING_DEFAULT_ORIGIN_CEP', '01310100'),

    // Used for products registered without weight or dimensions.
    'default_package' => [
        'weight_grams' => 500,
        'length_cm' => 16,
        'width_cm' => 11,
        'height_cm' => 6,
    ],

    // Volumetric weight (kg) = length x width x height (cm) / divisor.
    'volumetric_divisor' => 6000,

];
