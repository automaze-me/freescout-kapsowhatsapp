<?php

return [
    'name' => 'KapsoWhatsApp',

    'options' => [
        // No configuration is specific to one deployment (design spec Goals).
        // WhatsApp always delivers phone numbers as bare international
        // digits, so this only matters for phone numbers typed locally into
        // FreeScout in national format (a single leading trunk zero). Empty
        // by default: guessing a country here is worse than declining to
        // guess. Set for installs where agents type local numbers, e.g.
        // `Option::set('kapsowhatsapp.default_country_code', '49')` (bare
        // digits, no "+"). See Services/PhoneNumber::configuredDefaultCountryCode().
        'default_country_code' => ['default' => ''],
    ],
];
