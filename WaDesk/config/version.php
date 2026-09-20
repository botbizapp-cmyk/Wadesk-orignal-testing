<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Installed version
    |--------------------------------------------------------------------------
    | Bumped by each update package. The admin Updater compares the version
    | inside an uploaded ZIP against this before applying.
    */
    'version' => '1.7.0',
    'build'   => 8,

    /*
    |--------------------------------------------------------------------------
    | Envato purchase verification
    |--------------------------------------------------------------------------
    | Item id comes from config/license.php — a SHIPPED file, NOT .env — so the
    | installer (which writes .env) can never wipe it and buyers configure
    | nothing.
    |
    | The author TOKEN is deliberately absent from config. `config:cache`
    | var_export()s this whole array to bootstrap/cache/config.php, so anything
    | resolved here lands on disk in plaintext. The token is decrypted on demand
    | instead — see \App\Support\Licence::token().
    */
    'envato' => (static function () {
        $file = __DIR__ . '/license.php';
        $data = is_file($file) ? (array) require $file : [];

        return [
            'item_id' => (string) ($data['item_id'] ?? ''),
        ];
    })(),
];
