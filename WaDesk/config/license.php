<?php

/*
|--------------------------------------------------------------------------
| Licence (Envato)
|--------------------------------------------------------------------------
| CodeCanyon item id used by the installer and the admin Updater.
|
| THE AUTHOR TOKEN IS DELIBERATELY NOT HERE.
|
| It lives encrypted in `config/license.json` and is decrypted on demand by
| \App\Support\Licence::token(), at the moment of verification only.
|
| Why not resolve it in this file: config values get baked into
| `bootstrap/cache/config.php` by `php artisan config:cache` — the standard
| production step — which var_export()s the fully-resolved array. A token
| decrypted here would be written to disk in PLAINTEXT, defeating the whole
| point of encrypting it. Keeping it out of config makes that impossible.
|
| `item_id` is not secret, so it stays.
|
| To rotate the token, regenerate config/license.json. Never put a plaintext
| (or base64) token back into a config file.
*/

return [
    'item_id' => '63755235',

    // Intentionally absent: 'token'. Use \App\Support\Licence::token().
];
