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
    'allow_install_initial' => false,
    'allow_web_migrations' => false,
    'allow_destructive_migrations' => false,
    // Cuenta temporal y aislada para App Review. Usa password_hash(), nunca texto plano.
    'app_review_enabled' => false,
    'app_review_login' => '',
    'app_review_password_hash' => '',
    'app_review_expires_at' => '',
    'app_review_session_ttl_hours' => 24
];
