<?php
/**
 * PromptPress — Banco de imágenes (Unsplash) · defaults LOCALES.
 *
 * Copia este archivo a `config/image_bank.php` y pon tu Access Key de Unsplash.
 *
 *   cp config/image_bank.example.php config/image_bank.php
 *
 * Por qué un archivo aparte y no `config/config.php`:
 *  - El instalador REGENERA `config.php` (solo db/app_key/env), así que cualquier
 *    clave que pusieras ahí se perdería al reinstalar.
 *  - `config/image_bank.php` está en `.gitignore`: el instalador no lo toca y la
 *    Access Key NO llega al repositorio (importante en repos públicos).
 *  - `core/App::boot()` lo fusiona automáticamente bajo `config.php`.
 *
 * La Access Key es UNIVERSAL para toda la instalación (no es per-site). Si la
 * dejas vacía, el banco queda "No configurado" y la generación cae al fallback
 * sin imágenes. Consíguela en https://unsplash.com/developers (app gratuita).
 *
 * Atribución obligatoria: las imágenes se muestran con crédito al fotógrafo y
 * enlaces UTM `promptpress` (ver Unsplash API guidelines).
 */

return [
    'image_bank' => [
        'provider'   => 'unsplash',
        'access_key' => '',            // ← pega aquí tu Access Key de Unsplash
        'app_name'   => 'promptpress',
        'cache_ttl'  => 86400,
    ],
];
