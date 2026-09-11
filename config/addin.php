<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Add-in de Word (integración Office.js)
    |--------------------------------------------------------------------------
    */

    // Tamaño máximo aceptado para un `.docx` subido por el Add-in (bytes).
    'max_file_size' => env('ADDIN_MAX_FILE_SIZE', 20 * 1024 * 1024),

    // Nombre que reciben los tokens de acceso de Sanctum emitidos por el panel.
    'token_name' => env('ADDIN_TOKEN_NAME', 'word-addin'),

    // Minutos que un bloqueo puede permanecer activo sin renovación. El panel
    // renueva el TTL con un heartbeat cada 25 s mientras está abierto, así que
    // una sesión viva jamás expira. La caducidad corta existente solo se alcanza
    // cuando el panel dejó de latir (Word cerrado de golpe, crash, apagado,
    // caída de internet): en cuanto se supera, la siguiente purga (cada 8 s del
    // Explorador y del panel) libera el documento sin dejar a nadie atascado.
    'lock_ttl_minutes' => (int) env('ADDIN_LOCK_TTL_MINUTES', 3),
];