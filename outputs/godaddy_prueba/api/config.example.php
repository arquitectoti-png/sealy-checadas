<?php

return [
    'db_host' => 'localhost',
    'db_name' => 'TU_BASE_DE_DATOS',
    'db_user' => 'TU_USUARIO',
    'db_pass' => 'TU_PASSWORD',
    'cors_origin' => '*',
    'token_ttl_days' => 30,
    'upload_dir' => dirname(__DIR__, 3) . '/sealy_uploads',
    // Opcional. Si se configura, AUTO usa Google Time Zone API para resolver zona por coordenadas.
    // Sin llave, el sistema usa reglas internas de zonas horarias de Mexico.
    'google_timezone_api_key' => '',
    'setup_key' => 'CAMBIA_ESTA_CLAVE_TEMPORAL',
    'allow_destructive_migrations' => false
];
