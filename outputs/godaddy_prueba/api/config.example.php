<?php

return [
    'db_host' => 'localhost',
    'db_name' => 'TU_BASE_DE_DATOS',
    'db_user' => 'TU_USUARIO',
    'db_pass' => 'TU_PASSWORD',
    'cors_origin' => '*',
    'token_ttl_days' => 30,
    'upload_dir' => dirname(__DIR__, 3) . '/sealy_uploads',
    'setup_key' => 'CAMBIA_ESTA_CLAVE_TEMPORAL',
    'allow_destructive_migrations' => false
];
