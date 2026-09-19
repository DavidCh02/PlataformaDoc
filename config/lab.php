<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Consola secreta de diagnóstico ("Lab")
    |--------------------------------------------------------------------------
    |
    | Página oculta para que los administradores prueben el sistema en
    | producción (recordatorios, Telegram, permisos, cron, etc.) y vean los
    | errores tal cual ocurren.
    |
    | - Solo existe en la URL exacta de abajo (no hay enlaces en la app).
    | - Pide email + contraseña y exige el permiso `users.manage`.
    | - Desactívala con LAB_ENABLED=false si no la necesitas.
    |
    */
    'enabled' => env('LAB_ENABLED', true),

    // Segmento secreto de la URL, p. ej. /soporte-lab-7x9q2
    // Cámbialo en producción con LAB_PATH=algo-imposible-de-adivinar
    'path' => env('LAB_PATH', 'soporte-lab-7x9q2'),

    // Minutos que dura la sesión del lab tras el login.
    'session_minutes' => env('LAB_SESSION_MINUTES', 60),
];
